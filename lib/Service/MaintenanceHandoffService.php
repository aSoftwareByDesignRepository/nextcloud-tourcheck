<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * F18 (caller half) — "defect on round" → MaintenanceCheck draft WO.
 *
 * The WO lands in MN triage as a draft; a dispatcher owns the lifecycle.
 * Failure rule (suite §4.4): a dead/absent MaintenanceCheck must never
 * block or break the incident — the handoff returns a soft-fail code and
 * the incident stays fully usable. Dedupe is two-sided: the stored
 * `wo_ref` short-circuits repeat clicks, MN's UNIQUE(ref_type, ref_id) is
 * the concurrent-write arbiter.
 */
class MaintenanceHandoffService
{
	private const FACADE = 'OCA\\MaintenanceCheck\\Public\\FieldIssueDraftFacade';
	private const REF_TYPE = 'tck_incident';

	public function __construct(
		private readonly IncidentMapper $incidents,
		private readonly SiteService $sites,
		private readonly CheckpointMapper $checkpoints,
		private readonly AccessControlService $acl,
		private readonly ChainService $chain,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function installed(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('maintenancecheck')
				&& class_exists(self::FACADE)
				&& method_exists(self::FACADE, 'createDraft');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return array{ok:bool,woNumber?:string,already?:bool,code?:string}
	 */
	public function handoff(int $incidentId): array
	{
		$inc = $this->incidents->find($incidentId);
		if ($inc === null) {
			throw new NotFoundException();
		}
		$uid = $this->acl->assertCanSuperviseSite((int)$inc->getSiteId());

		$existing = $inc->getWoRef();
		if ($existing !== null && $existing !== '') {
			return ['ok' => true, 'already' => true, 'woNumber' => $existing];
		}
		if (!$this->installed()) {
			return ['ok' => false, 'code' => 'maintenancecheck_unavailable'];
		}

		$siteLabel = '';
		try {
			$site = $this->sites->findById((int)$inc->getSiteId());
			$siteLabel = $site !== null ? (string)$site->getName() : '';
		} catch (\Throwable) {
			$siteLabel = '';
		}
		$checkpointLabel = '';
		if ($inc->getCheckpointId() !== null && (int)$inc->getCheckpointId() > 0) {
			try {
				$cp = $this->checkpoints->find((int)$inc->getCheckpointId());
				$checkpointLabel = $cp !== null ? (string)$cp->getName() : '';
			} catch (\Throwable) {
				$checkpointLabel = '';
			}
		}

		try {
			$facade = \OCP\Server::get(self::FACADE);
		} catch (\Throwable $e) {
			$this->logger->warning('F18 facade resolve failed', ['exception' => $e]);
			return ['ok' => false, 'code' => 'maintenancecheck_unavailable'];
		}

		try {
			$res = $facade->createDraft([
				'refType' => self::REF_TYPE,
				'refId' => (string)$inc->getId(),
				'siteLabel' => $siteLabel,
				'title' => trim((string)$inc->getTitle()) !== '' ? (string)$inc->getTitle() : 'TourCheck incident #' . $inc->getId(),
				'description' => (string)($inc->getNote() ?? ''),
				'checkpointLabel' => $checkpointLabel,
				'photoFileId' => (int)($inc->getPhotoFileid() ?? 0) ?: null,
				'actorUid' => $uid,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('F18 handoff failed for incident ' . $inc->getId(), ['exception' => $e]);
			return ['ok' => false, 'code' => 'handoff_failed'];
		}

		if (($res['ok'] ?? false) !== true) {
			return ['ok' => false, 'code' => (string)($res['code'] ?? 'handoff_failed')];
		}

		$number = (string)($res['number'] ?? '');
		if ($number !== '' && !($res['already'] ?? false)) {
			$this->persistWoRef($inc, $number);
			$this->journal($inc, $uid, 'wo_handoff:' . $number);
		} elseif ($number !== '' && ($res['already'] ?? false)) {
			// ref-dedupe hit while our wo_ref was empty — heal the local state.
			$this->persistWoRef($inc, $number);
		}
		return ['ok' => true, 'woNumber' => $number, 'already' => (bool)($res['already'] ?? false)];
	}

	private function persistWoRef(Incident $inc, string $number): void
	{
		$inc->setWoRef(mb_substr($number, 0, 40));
		$this->incidents->update($inc);
	}

	private function journal(Incident $inc, string $uid, string $body): void
	{
		$this->chain->append((int)$inc->getSiteId(), [
			'roundId' => $inc->getRoundId(),
			'kind' => Constants::KIND_INCIDENT,
			'actorUid' => $uid,
			'body' => $body,
			'payloadRef' => 'incident:' . $inc->getId(),
			'payloadFields' => [
				'id' => (int)$inc->getId(), 'woRef' => $inc->getWoRef(),
			],
		]);
	}
}
