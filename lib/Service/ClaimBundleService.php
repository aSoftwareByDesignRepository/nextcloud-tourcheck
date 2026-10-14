<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;

/**
 * One-click claim bundle: force-anchor → collect evidence → zip with a
 * signed manifest → land in the manager's Files. The manifest hashes every
 * artifact so the bundle itself is verifiable offline; an unanchored state
 * is written INTO the manifest (visible, never implied).
 */
class ClaimBundleService
{
	public function __construct(
		private readonly AnchorService $anchors,
		private readonly ReportService $reports,
		private readonly ChainService $chain,
		private readonly JournalEntryMapper $journal,
		private readonly CheckEventMapper $events,
		private readonly IncidentMapper $incidents,
		private readonly PhotoService $photos,
		private readonly SiteService $sites,
		private readonly AccessControlService $acl,
		private readonly SettingsService $settings,
		private readonly RateLimitService $rateLimit,
		private readonly IRootFolder $rootFolder,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{path:string, anchoring:string, files:int}
	 */
	public function build(int $siteId, string $period, string $uid): array
	{
		$this->acl->assertCanManageSite($siteId);
		$site = $this->sites->getVisible($siteId);
		// Bundle builds are expensive and TSA-synchronous — one per site+user
		// per 5 min (spec cooldown); 429 otherwise.
		$this->rateLimit->check('bundle', $siteId . '|' . $uid, 1, 300);

		// 1. Anchor first — completeness to THIS head before packaging.
		$anchoring = $this->anchors->anchorForBundle($siteId);

		// 2. Assemble artifacts.
		$pdf = $this->reports->buildPdf($siteId, $period);
		$csv = $this->reports->buildCsv($siteId, $period);
		$chainVerify = $this->chain->verify($siteId);
		$journalRows = array_map(static fn ($e) => $e->jsonSerialize(), $this->journal->findAllForSite($siteId));
		$journalJson = json_encode($journalRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		// Photo evidence referenced by period events + incidents.
		$b = ReportService::periodBounds($period);
		$from = new \DateTime($b['from'] . ' 00:00:00', new \DateTimeZone('UTC'));
		$to = new \DateTime($b['to'] . ' 00:00:00', new \DateTimeZone('UTC'));
		$photoIds = [];
		foreach ($this->events->findForSiteBetween($siteId, $from, $to) as $e) {
			if ($e->getPhotoFileid() !== null && (int)$e->getTombstoned() === 0) {
				$photoIds[(int)$e->getPhotoFileid()] = true;
			}
		}
		foreach ($this->incidents->findForSiteBetween($siteId, $from, $to) as $i) {
			if ($i->getPhotoFileid() !== null) {
				$photoIds[(int)$i->getPhotoFileid()] = true;
			}
		}

		// Per-site Ed25519 key (config-secret-derived): the manifest is SIGNED,
		// not just hashed — a doctored bundle fails signature verify even when
		// its file hashes were regenerated. The pubkey is journaled below, so
		// the verifying key is itself bound into the hash chain.
		$sign = $this->settings->bundleSignKeypair($siteId);

		$manifest = [
			'format' => 'tourcheck-claim-bundle/1',
			'site' => ['id' => $siteId, 'name' => $site->getName()],
			'period' => $period,
			'generated_at' => $this->timeFactory->getDateTime()->format('c'),
			'anchoring' => $anchoring, // 'anchored' | 'unanchored' — visible in the bundle itself
			'signature' => [
				'algo' => 'ed25519',
				'pubkey' => sodium_bin2hex($sign['pk']),
				'signs' => 'manifest.json',
			],
			'chain' => [
				'verified' => $chainVerify['ok'],
				'entries' => $chainVerify['entries'],
				'head' => $chainVerify['head'],
				'break_at_seq' => $chainVerify['break_at_seq'],
				'reason' => $chainVerify['reason'],
			],
			'files' => [],
		];

		// 3. Zip.
		$tmp = tempnam(sys_get_temp_dir(), 'tck_bundle_');
		if ($tmp === false) {
			throw new DomainException('bundle_failed', 'Could not create bundle.', 500);
		}
		try {
			$zip = new \ZipArchive();
			if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
				throw new DomainException('bundle_failed', 'Could not create bundle archive.', 500);
			}
			$add = static function (string $name, string $content) use ($zip, &$manifest): void {
				$zip->addFromString($name, $content);
				$manifest['files'][] = ['name' => $name, 'sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
			};
			$add('report-' . $period . '.pdf', $pdf);
			$add('events-' . $period . '.csv', $csv);
			$add('journal.json', is_string($journalJson) ? $journalJson : '[]');
			$add('verify-chain.json', json_encode($chainVerify, JSON_PRETTY_PRINT));
			// Referenced photo evidence — an event/incident claiming a photo
			// without the bytes is a broken proof.
			foreach (array_keys($photoIds) as $fileId) {
				$f = $this->photos->read($siteId, $fileId);
				if ($f !== null) {
					$add('photos/' . $fileId . '.jpg', $f->getContent());
				} else {
					$manifest['missing_photos'][] = $fileId;
				}
			}
			$add('README.txt', $this->readme($site, $period, $anchoring));
			$manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
			if ($manifestJson === false) {
				throw new DomainException('bundle_failed', 'Could not encode bundle manifest.', 500);
			}
			$zip->addFromString('manifest.json', $manifestJson);
			$zip->addFromString('manifest.sig', sodium_bin2hex(sodium_crypto_sign_detached($manifestJson, $sign['sk'])));
			sodium_memzero($sign['sk']);
			$zip->close();

			// 4. Into the manager's Files (folder ACL = who can read it).
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$dir = $userFolder->nodeExists('TourCheck') ? $userFolder->get('TourCheck') : $userFolder->newFolder('TourCheck');
			$dir = $dir->nodeExists('claim-bundles') ? $dir->get('claim-bundles') : $dir->newFolder('claim-bundles');
			$filename = 'claim-' . preg_replace('/[^A-Za-z0-9_-]/', '', $site->getName()) . '-' . $period . '-' . date('His') . '.zip';
			$node = $dir->newFile($filename);
			$node->putContent(fopen($tmp, 'rb'));
			// The bundle's creation is itself a control-plane event (NN-20).
			$this->chain->append($siteId, [
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $uid,
				'body' => 'claim_bundle_built:' . $period . ':' . $anchoring,
				'payloadRef' => null,
				'payloadFields' => [
					'period' => $period,
					'anchoring' => $anchoring,
					'bundle_pubkey' => sodium_bin2hex($sign['pk']),
				],
			]);
			return ['path' => $dir->getPath() . '/' . $filename, 'anchoring' => $anchoring, 'files' => count($manifest['files']) + 1];
		} finally {
			@unlink($tmp);
		}
	}

	/** Human-readable bundle guide — a claim reviewer is not a developer. */
	private function readme($site, string $period, string $anchoring): string
	{
		return implode("\n", [
			'TourCheck claim bundle — ' . $site->getName() . ' — ' . $period,
			str_repeat('=', 60),
			'',
			'report-' . $period . '.pdf   Branded period report (SLA, rounds, checkpoints).',
			'events-' . $period . '.csv   Every checkpoint event, one row each.',
			'journal.json            The tamper-evident journal (hash-chained).',
			'verify-chain.json       Chain verification result computed at bundle time.',
			'photos/*.jpg            Photo evidence referenced by events/incidents.',
			'manifest.json           SHA-256 hash of every file — verify offline:',
			'                        sha256sum <file> and compare to manifest.json.',
			'manifest.sig            Ed25519 signature over manifest.json. Verify:',
			'                        pubkey in manifest.json (and in the site journal',
			'                        entry for this bundle) + sodium_crypto_sign_verify_',
			'                        detached(sig, manifest.json bytes, pubkey).',
			'',
			'External anchoring: ' . strtoupper($anchoring) . '. "anchored" means the',
			'journal head at bundle time was timestamped by the configured TSA,',
			'proving nothing was appended or removed afterwards.',
			'',
		]);
	}
}
