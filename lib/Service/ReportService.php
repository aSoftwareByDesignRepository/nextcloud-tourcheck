<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\AnchorMapper;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\ReportDelivery;
use OCA\TourCheck\Db\ReportDeliveryMapper;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Db\ReportSched;
use OCA\TourCheck\Db\ReportSchedMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Branded period reports + SLA metrics + CSV export + claim bundles.
 * SLA definition (pinned): a checkpoint counts when a non-tombstoned event
 * attached to its round_cp exists; "on_time" = event.client_ts (or server
 * ts when absent) within [earliest, latest]; "attested" counts separately —
 * an attested checkpoint is not silently merged into "scanned" (NN-07).
 */
class ReportService
{
	public function __construct(
		private readonly RoundMapper $rounds,
		private readonly CheckEventMapper $events,
		private readonly RoundCheckpointMapper $roundCps,
		private readonly ReportSchedMapper $schedules,
		private readonly ReportDeliveryMapper $deliveries,
		private readonly JournalEntryMapper $journal,
		private readonly AnchorMapper $anchors,
		private readonly IncidentMapper $incidents,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly SettingsService $settings,
		private readonly IUserManager $users,
		private readonly IRootFolder $rootFolder,
		private readonly IAppData $appData,
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly IL10N $l,
		private readonly ChainService $chainService,
	) {
	}

	/** @return array{from:string,to:string} */
	public static function periodBounds(string $period): array
	{
		if (preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
			$from = $period . '-01';
			$to = (new \DateTimeImmutable($from))->modify('first day of next month')->format('Y-m-d');
			return ['from' => $from, 'to' => $to];
		}
		if (preg_match('/^\d{4}-W\d{2}$/', $period) === 1) {
			$from = (new \DateTimeImmutable())->setISODate((int)substr($period, 0, 4), (int)substr($period, 5, 2));
			return ['from' => $from->format('Y-m-d'), 'to' => $from->modify('+7 days')->format('Y-m-d')];
		}
		throw new ValidationException(Constants::ERR_VALIDATION, 'Period must be YYYY-MM or YYYY-Www.', ['period' => 'invalid']);
	}

	/**
	 * Aggregate report data — shared by PDF, CSV, and the claim bundle.
	 * $audience 'client' redacts worker identities (object-client recipients
	 * get the operational proof without per-worker surveillance data — the
	 * report_sched share_policy semantic).
	 * @return array<string,mixed>
	 */
	public function aggregate(int $siteId, string $period, string $audience = 'internal'): array
	{
		$this->acl->assertCanViewSite($siteId);
		$site = $this->sites->getVisible($siteId);
		$b = self::periodBounds($period);
		$from = new \DateTime($b['from'] . ' 00:00:00', new \DateTimeZone('UTC'));
		$to = new \DateTime($b['to'] . ' 00:00:00', new \DateTimeZone('UTC'));

		$rounds = $this->rounds->findScheduledBetween($siteId, $from, $to);

		$events = $this->events->findForSiteBetween($siteId, $from, $to);
		$eventsByRoundCp = [];
		foreach ($events as $e) {
			if ($e->getRoundCpId() !== null && (int)$e->getTombstoned() === 0) {
				$eventsByRoundCp[(int)$e->getRoundCpId()] = $e;
			}
		}

		$roundRows = [];
		$tot = ['rounds' => 0, 'completed' => 0, 'partial' => 0, 'missed' => 0, 'skipped' => 0,
			'cps' => 0, 'sla_denom' => 0, 'done' => 0, 'on_time' => 0, 'late' => 0, 'attested' => 0,
			'early' => 0, 'late_sync' => 0, 'orphan' => 0];
		foreach ($rounds as $round) {
			$cps = $this->roundCps->findForRound((int)$round->getId());
			$skippedRound = $round->getStatus() === Constants::ROUND_SKIPPED;
			$cpRows = [];
			foreach ($cps as $rcp) {
				$e = $eventsByRoundCp[(int)$rcp->getId()] ?? null;
				$grade = 'missing';
				$at = null;
				if ($e !== null) {
					$at = $e->getClientTs() ?? $e->getServerReceivedAt();
					if ($e->getMethod() === Constants::METHOD_ATTEST) {
						$grade = 'attested';
						$tot['attested']++;
					} elseif ($e->getFlaggedEarly() === 1) {
						$grade = 'early';
						$tot['early']++;
					} elseif ($rcp->getLatest() !== null && $at > $rcp->getLatest()) {
						$grade = 'late';
						$tot['late']++;
					} else {
						$grade = 'on_time';
						$tot['on_time']++;
					}
					$tot['done']++;
					if ((int)$e->getLateSync() === 1) {
						$tot['late_sync']++;
					}
				}
				$tot['cps']++;
				if (!$skippedRound) {
					$tot['sla_denom']++; // spec: SLA divides by scheduled cps
				}
				$cpRows[] = [
					'checkpoint' => $rcp->getNameSnap(),
					'due' => $rcp->getDue()?->format('H:i'),
					'status' => $grade,
					'method' => $e?->getMethod(),
					'at' => $at?->format('Y-m-d H:i'),
					'actor' => $audience === 'client' && $e !== null ? 'REDACTED'
						: ($e === null ? null
							: ((int)$e->getTombstoned() === 1 ? $this->l->t('(erased)')
								: ($e->getActorUid() ?? $e->getStationMemberId()))),
				];
			}
			$tot['rounds']++;
			$tot[$round->getStatus() === Constants::ROUND_COMPLETED ? 'completed'
				: ($round->getStatus() === Constants::ROUND_PARTIAL ? 'partial'
				: ($round->getStatus() === Constants::ROUND_SKIPPED ? 'skipped' : 'missed'))]++;
			$roundRows[] = [
				'id' => (int)$round->getId(),
				'start' => $round->getScheduledStart()->format('Y-m-d H:i'),
				'status' => $round->getStatus(),
				'cps' => $cpRows,
			];
		}
		foreach ($events as $e) {
			if ((int)$e->getOrphan() === 1 && (int)$e->getTombstoned() === 0) {
				$tot['orphan']++;
			}
		}

		$sla = $tot['sla_denom'] > 0 ? round(($tot['on_time'] + $tot['early']) / $tot['sla_denom'] * 100, 1) : 0.0;
		$attainment = $tot['rounds'] > 0 ? round($tot['completed'] / max(1, $tot['rounds'] - $tot['skipped']) * 100, 1) : 0.0;
		$incidents = $this->incidents->findForSiteBetween($siteId, $from, $to);
		$anchor = $this->anchors->findLatestAnchored($siteId);
		// NN-13: every export carries the verifiable chain-head hash + anchor
		// status — an integrity claim without the artifact is marketing.
		$head = $this->journal->findHead($siteId);

		return [
			'site' => ['id' => $siteId, 'name' => $site->getName(), 'tz' => $site->getTz()],
			'period' => $period,
			'audience' => $audience,
			'from' => $b['from'],
			'to' => $b['to'],
			'rounds' => $roundRows,
			'totals' => $tot,
			'sla' => ['on_time_pct' => $sla, 'round_attainment_pct' => $attainment],
			'incidents' => array_map(static fn ($i) => [
				'id' => (int)$i->getId(), 'category' => $i->getCategory(), 'severity' => $i->getSeverity(),
				'status' => $i->getStatus(), 'created' => $i->getCreatedAt()->format('Y-m-d H:i'),
				'title' => $i->getTitle(),
			], $incidents),
			'evidence' => [
				'journal_entries' => $this->journal->countForSite($siteId),
				'chain_head' => $head === null ? null : [
					'seq' => (int)$head->getSeq(), 'hash' => (string)$head->getEntryHash(),
				],
				'anchor' => $anchor === null ? null : [
					'seq' => (int)$anchor->getSeq(), 'status' => $anchor->getStatus(),
					'anchored_at' => $anchor->getAnchoredAt()?->format('c'),
				],
			],
			'generated_at' => $this->timeFactory->getDateTime()->format('c'),
		];
	}

	/** Branded PDF (SimplePdfBuilder statement layout). */
	public function buildPdf(int $siteId, string $period, string $audience = 'internal'): string
	{
		$data = $this->aggregate($siteId, $period, $audience);
		$brand = $this->settings->getBrandName() !== '' ? $this->settings->getBrandName() : 'TourCheck';
		$l = $this->l;
		$rows = [];
		foreach ($data['rounds'] as $round) {
			foreach ($round['cps'] as $cp) {
				$rows[] = [$round['start'], (string)$cp['checkpoint'], (string)$cp['status'], (string)($cp['at'] ?? '—'), (string)($cp['actor'] ?? '—')];
			}
			if ($round['cps'] === []) {
				$rows[] = [$round['start'], '—', $round['status'], '—', '—'];
			}
		}
		return SimplePdfBuilder::buildStatement([
			'brand' => $brand,
			'title' => $l->t('Tour report') . ' — ' . $data['site']['name'] . ' · ' . $period,
			'meta' => [
				[$l->t('Period'), $data['from'] . ' → ' . $data['to']],
				[$l->t('Generated'), $data['generated_at']],
				[$l->t('Anchor'), $data['evidence']['anchor'] === null ? $l->t('unanchored') : $l->t('anchored at seq %d', [$data['evidence']['anchor']['seq']])],
				[$l->t('Chain head'), $data['evidence']['chain_head'] === null ? $l->t('none') : (string)$data['evidence']['chain_head']['hash']],
			],
			'keyFigure' => [
				'label' => $l->t('On-time SLA'),
				'value' => $data['sla']['on_time_pct'] . ' %',
				'details' => [
					$l->t('Round attainment: %s %%', [$data['sla']['round_attainment_pct']]),
					$l->t('Rounds: %d completed / %d partial / %d missed / %d total', [$data['totals']['completed'], $data['totals']['partial'], $data['totals']['missed'], $data['totals']['rounds']]),
					$l->t('Checkpoints: %d on-time, %d late, %d attested, %d late-sync, %d unassigned', [$data['totals']['on_time'], $data['totals']['late'], $data['totals']['attested'], $data['totals']['late_sync'], $data['totals']['orphan']]),
				],
			],
			'columns' => [$l->t('Round'), $l->t('Checkpoint'), $l->t('Status'), $l->t('Checked at'), $l->t('By')],
			'colWidths' => [0.24, 0.26, 0.16, 0.18, 0.16],
			'rows' => $rows,
			'emptyItemsText' => $l->t('No rounds in this period.'),
			'note' => $l->t('Evidence grades are shown as recorded — attested confirmations are never displayed as scans.'),
		]);
	}

	/** CSV (UTF-8 BOM, semicolon, formula-injection safe) with NN-13 evidence preamble. */
	public function buildCsv(int $siteId, string $period, string $audience = 'internal'): string
	{
		$data = $this->aggregate($siteId, $period, $audience);
		$rows = [];
		foreach ($data['rounds'] as $round) {
			foreach ($round['cps'] as $cp) {
				$rows[] = [$round['start'], $round['status'], (string)$cp['checkpoint'], (string)($cp['due'] ?? ''), $cp['status'], (string)($cp['method'] ?? ''), (string)($cp['at'] ?? ''), (string)($cp['actor'] ?? '')];
			}
		}
		$head = $data['evidence']['chain_head'];
		$anchor = $data['evidence']['anchor'];
		$meta = [
			['evidence_chain_head_seq', $head === null ? 'NONE' : $head['seq']],
			['evidence_chain_head_hash', $head === null ? 'NONE' : $head['hash']],
			['evidence_anchor_status', $anchor === null ? 'UNANCHORED' : 'ANCHORED'],
			['evidence_anchor_seq', $anchor === null ? '' : $anchor['seq']],
			['evidence_anchor_at', $anchor === null || $anchor['anchored_at'] === null ? '' : $anchor['anchored_at']],
			['audience', $audience],
			['generated_at', $data['generated_at']],
		];
		return CsvExportBuilder::build(
			['round_start', 'round_status', 'checkpoint', 'due', 'grade', 'method', 'checked_at', 'actor'],
			$rows,
			$meta,
		);
	}

	/**
	 * ODS export (AC-3/GA-7). ODF declares formulas via table:formula — a
	 * string-typed cell can never become one, but we still neutralize
	 * leading formula chars so CSV and ODS exports behave identically for
	 * downstream consumers that re-export or convert.
	 */
	public function buildOds(int $siteId, string $period, string $audience = 'internal'): string
	{
		$data = $this->aggregate($siteId, $period, $audience);
		$neutral = static function (mixed $v): mixed {
			return is_string($v) ? CsvExportBuilder::neutralizeFormula($v) : $v;
		};

		$detail = [];
		foreach ($data['rounds'] as $round) {
			foreach ($round['cps'] as $cp) {
				$detail[] = array_map($neutral, [
					$round['start'], $round['status'], (string)$cp['checkpoint'],
					(string)($cp['due'] ?? ''), $cp['status'], (string)($cp['method'] ?? ''),
					(string)($cp['at'] ?? ''), (string)($cp['actor'] ?? ''),
				]);
			}
		}
		$t = $data['totals'];
		$summaryRows = array_map(static fn (array $r) => array_map($neutral, $r), [
			['site', (string)$data['site']['name']],
			['period', (string)$data['period']],
			['rounds_total', (int)$t['rounds']],
			['rounds_completed', (int)$t['completed']],
			['rounds_partial', (int)$t['partial']],
			['rounds_missed', (int)$t['missed']],
			['rounds_skipped', (int)$t['skipped']],
			['sla_on_time_pct', (float)$data['sla']['on_time_pct']],
			['round_attainment_pct', (float)$data['sla']['round_attainment_pct']],
			['anchor_seq', $data['evidence']['anchor']['seq'] ?? 'UNANCHORED'],
			['anchor_status', $data['evidence']['anchor'] === null ? 'UNANCHORED' : 'ANCHORED'],
			['chain_head_seq', $data['evidence']['chain_head']['seq'] ?? 'NONE'],
			['chain_head_hash', $data['evidence']['chain_head']['hash'] ?? 'NONE'],
			['audience', $audience],
			['generated_at', (string)$data['generated_at']],
		]);
		$incidentRows = array_map(static fn ($i) => array_map($neutral, [
			$i['id'], $i['created'], $i['category'], $i['severity'], $i['status'], (string)$i['title'],
		]), $data['incidents']);

		return (new OdsBuilder())->build([
			['name' => 'Summary', 'columns' => ['metric', 'value'], 'rows' => $summaryRows],
			['name' => 'Rounds', 'columns' => ['round_start', 'round_status', 'checkpoint', 'due', 'grade', 'method', 'checked_at', 'actor'], 'rows' => $detail],
			['name' => 'Incidents', 'columns' => ['id', 'created', 'category', 'severity', 'status', 'title'], 'rows' => $incidentRows],
		], 'TourCheck');
	}

	// ── schedules & deliveries ───────────────────────────────────────────

	public function upsertSchedule(int $siteId, string $cadence, string $recipient, bool $active, string $audience = 'internal'): ReportSched
	{
		$uid = $this->acl->assertCanManageSite($siteId);
		if (!in_array($cadence, ['weekly', 'monthly'], true)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Cadence must be weekly or monthly.', ['cadence' => 'invalid']);
		}
		if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Recipient must be an email address.', ['recipient' => 'invalid']);
		}
		if (!in_array($audience, ['internal', 'client'], true)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Audience must be internal or client.', ['audience' => 'invalid']);
		}
		$existing = $this->schedules->findByNatural($siteId, $cadence, $recipient);
		$now = $this->timeFactory->getDateTime();
		$apply = static function (ReportSched $s) use ($active, $audience, $now): ReportSched {
			$s->setActive($active ? 1 : 0);
			$s->setSharePolicyJson(json_encode(['audience' => $audience]));
			$s->setRevision((int)$s->getRevision() + 1);
			$s->setUpdatedAt($now);
			return $s;
		};
		if ($existing !== null) {
			$sched = $this->schedules->update($apply($existing));
		} else {
			$sched = new ReportSched();
			$sched->setSiteId($siteId);
			$sched->setCadence($cadence);
			$sched->setRecipient($recipient);
			$sched->setSharePolicyJson(json_encode(['audience' => $audience]));
			$sched->setActive($active ? 1 : 0);
			$sched->setRevision(1);
			$sched->setCreatedAt($now);
			$sched->setUpdatedAt($now);
			try {
				$sched = $this->schedules->insert($sched);
			} catch (\OCP\DB\Exception $e) {
				// A concurrent upsert won the (site,cadence,recipient) insert —
				// reload the winner and apply our fields instead of surfacing
				// a raw 500 (mint-idempotency: never a second row, never 500).
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				$existing = $this->schedules->findByNatural($siteId, $cadence, $recipient);
				if ($existing === null) {
					throw $e;
				}
				$sched = $this->schedules->update($apply($existing));
			}
		}
		$this->chain()->append($siteId, [
			'kind' => Constants::KIND_ADMIN, 'actorUid' => $uid,
			'body' => 'report_schedule_' . ($active ? 'set' : 'disabled'),
			'payloadRef' => 'report_sched:' . $sched->getId(),
			'payloadFields' => ['sched' => (int)$sched->getId(), 'cadence' => $cadence, 'recipient' => $recipient, 'audience' => $audience],
		]);
		return $sched;
	}

	private function chain(): ChainService
	{
		return $this->chainService;
	}

	/** @return list<ReportSched> */
	public function listSchedules(int $siteId): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->schedules->findForSite($siteId);
	}

	/**
	 * Generate + persist a report delivery. PDFs live in appdata
	 * (`reports/site{N}/dlv-{id}.pdf`) — NOT the acting user's Files tree —
	 * so every authorized site viewer can download the delivery (a private
	 * file_id in user Files 404s for everyone else).
	 * @return array{delivery: ReportDelivery, path: string}
	 */
	public function generate(int $siteId, string $period, string $kind, ?int $schedId, string $uid, string $audience = 'internal'): array
	{
		$this->acl->assertCanViewSite($siteId);
		$pdf = $this->buildPdf($siteId, $period, $audience);
		$dlv = new ReportDelivery();
		$dlv->setSiteId($siteId);
		$dlv->setSchedId($schedId);
		$dlv->setPeriod($period);
		$dlv->setKind($kind);
		$dlv->setStatus('generated');
		// Client-audience PDFs are redacted by construction — they are the
		// curated exports the portal may list once a planner marks them.
		$dlv->setAudience(in_array($audience, ['internal', 'client'], true) ? $audience : 'internal');
		$dlv->setClientVisible($audience === 'client');
		$dlv->setCreatedAt($this->timeFactory->getDateTime());
		$dlv = $this->deliveries->insert($dlv);
		$file = $this->reportFolder($siteId)->newFile($this->deliveryFileName($dlv), $pdf);
		$dlv->setFileId((int)$dlv->getId()); // key into appdata filename
		$dlv = $this->deliveries->update($dlv);
		return ['delivery' => $dlv, 'path' => 'appdata/reports/site' . $siteId . '/' . $file->getName()];
	}

	/** Manage-gated per-delivery portal visibility toggle. */
	public function setClientVisible(int $id, bool $visible): ReportDelivery
	{
		$dlv = $this->deliveries->find($id);
		if ($dlv === null) {
			throw new NotFoundException();
		}
		$this->acl->assertCanManageSite((int)$dlv->getSiteId());
		// An internal-audience PDF contains worker identities — refuse to
		// publish it to the portal even if a manager toggles the flag.
		// Client-audience PDFs are redacted by construction.
		if ($visible && (string)$dlv->getAudience() !== 'client') {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Only customer-audience reports can be shared to the client portal.', ['client_visible' => 'internal_audience']);
		}
		$dlv->setClientVisible($visible);
		return $this->deliveries->update($dlv);
	}

	/** Decoded share-policy audience for a schedule — defaults to internal. */
	public static function audienceOf(ReportSched $sched): string
	{
		$p = json_decode((string)($sched->getSharePolicyJson() ?? ''), true);
		return is_array($p) && ($p['audience'] ?? '') === 'client' ? 'client' : 'internal';
	}

	private function deliveryFileName(ReportDelivery $d): string
	{
		return 'dlv-' . (int)$d->getId() . '.pdf';
	}

	private function reportFolder(int $siteId): ISimpleFolder
	{
		try {
			$root = $this->appData->getFolder('reports');
		} catch (\OCP\Files\NotFoundException) {
			$root = $this->appData->newFolder('reports');
		}
		$dir = 'site' . $siteId;
		try {
			return $root->getFolder($dir);
		} catch (\OCP\Files\NotFoundException) {
			return $root->newFolder($dir);
		}
	}

	/** Stored PDF for a delivery row (appdata), or null if missing. */
	public function deliveryFile(ReportDelivery $d): ?ISimpleFile
	{
		try {
			return $this->reportFolder((int)$d->getSiteId())->getFile($this->deliveryFileName($d));
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Rebuild the appdata PDF when a delivery row exists but its file does
	 * not (prior run crashed between insert and newFile). Returns the
	 * delivery unchanged when the file is already there.
	 */
	public function ensureDeliveryFile(ReportDelivery $d): ReportDelivery
	{
		if ($this->deliveryFile($d) !== null) {
			return $d;
		}
		$siteId = (int)$d->getSiteId();
		$sched = $d->getSchedId() !== null ? $this->schedules->find((int)$d->getSchedId()) : null;
		$audience = $sched !== null ? self::audienceOf($sched) : 'internal';
		$pdf = $this->buildPdf($siteId, (string)$d->getPeriod(), $audience);
		$folder = $this->reportFolder($siteId);
		try {
			$existing = $folder->getFile($this->deliveryFileName($d));
			$existing->putContent($pdf);
		} catch (\OCP\Files\NotFoundException) {
			$folder->newFile($this->deliveryFileName($d), $pdf);
		}
		if ($d->getFileId() === null) {
			$d->setFileId((int)$d->getId());
			$d = $this->deliveries->update($d);
		}
		return $d;
	}

	/** Mail a scheduled delivery's PDF to the schedule recipient. */
	public function sendDelivery(ReportDelivery $d): void
	{
		$sched = $d->getSchedId() !== null ? $this->schedules->find((int)$d->getSchedId()) : null;
		$recipient = $sched !== null ? (string)($sched->getRecipient() ?? '') : '';
		if ($recipient === '') {
			return; // manual deliveries are downloaded, not mailed
		}
		$file = $this->deliveryFile($d);
		if ($file === null) {
			$d->setStatus('failed');
			$this->deliveries->update($d);
			$this->logger->warning('tourcheck: report delivery missing file', ['delivery' => $d->getId()]);
			return;
		}
		try {
			$site = $this->sites->findById((int)$d->getSiteId());
			$msg = $this->mailer->createMessage();
			$msg->setTo([$recipient => $recipient]);
			$msg->setSubject($this->l->t('TourCheck report — %s — %s', [$site?->getName() ?? '', (string)$d->getPeriod()]));
			$msg->setPlainBody($this->l->t('Your scheduled TourCheck report is attached.'));
			$msg->attach($this->mailer->createAttachment(
				$file->getContent(),
				'tourcheck-report-' . $d->getPeriod() . '.pdf',
				'application/pdf'
			));
			$this->mailer->send($msg);
			$d->setStatus('sent');
		} catch (\Throwable $e) {
			$d->setStatus('failed');
			$this->logger->warning('tourcheck: report mail failed', ['delivery' => $d->getId(), 'e' => $e->getMessage()]);
		}
		$this->deliveries->update($d);
	}

	/** First site admin — kept for callers that attribute an action. */
	public function scheduleOwner(int $siteId): ?string
	{
		foreach ($this->acl->supervisorRecipients($siteId) as $uid) {
			$role = $this->acl->siteRole($uid, $siteId);
			if ($role === Constants::ROLE_ADMIN || $role === Constants::ROLE_PLANNER) {
				return $uid;
			}
		}
		return $this->acl->supervisorRecipients($siteId)[0] ?? null;
	}

	/** @return list<ReportDelivery> */
	public function listDeliveries(int $siteId, int $limit = 50): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->deliveries->findForSite($siteId, $limit);
	}
}
