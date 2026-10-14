<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\ManDownState;
use OCA\TourCheck\Db\ManDownStateMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Automatic man-down (F4) — a SOFTWARE inactivity workflow, not a certified
 * DIN V VDE 0825-11 device. Two triggers converge on the same alert:
 *
 *  1. Device-side: the companion detects no motion for (mandown_min −
 *     pre_s), runs a local pre-alarm; if the worker can't cancel, it POSTs
 *     alarm → we fire immediately.
 *  2. Server-side safety net: a cron sweep fires when last_activity_at is
 *     older than mandown_min — covers dead/offline phones the device
 *     trigger can't report.
 *
 * Both paths are idempotent: the deterministic client_event_id
 * ("md:{round}:{uid}:{bucket}") collapses double fires, and the phase
 * transition watching→alarmed is claimed transactionally.
 */
class ManDownService
{
	public function __construct(
		private readonly ManDownStateMapper $states,
		private readonly AlertService $alerts,
		private readonly RoundService $rounds,
		private readonly SiteService $sites,
		private readonly AccessControlService $acl,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/** Site config the companion needs to run its local pre-alarm. */
	public function configFor(int $siteId): array
	{
		$site = $this->sites->findById($siteId);
		$min = $site !== null ? $site->getMandownMin() : null;
		return [
			'enabled' => $min !== null && (int)$min > 0,
			'inactivityMin' => $min !== null ? (int)$min : 0,
			'prealarmS' => $site !== null ? (int)$site->getMandownPreS() : 60,
		];
	}

	/**
	 * Activity heartbeat — every companion interaction that proves the
	 * worker is responsive (motion, tap, position ping, checkpoint). This
	 * resets the inactivity clock; it is cheap, idempotent, and bounded by
	 * the caller's rate limit.
	 */
	public function heartbeat(int $roundId, string $uid): void
	{
		$round = $this->rounds->findById($roundId);
		if ($round === null || $round->getStatus() !== 'active') {
			throw new NotFoundException();
		}
		$siteId = (int)$round->getSiteId();
		$this->acl->assertCanViewSite($siteId);
		// Only the worker's own heartbeats count — a supervisor must not be
		// able to silence another worker's man-down clock (NN: no remote ack).
		if ($round->getStartedBy() !== $uid && $this->acl->siteRole($uid, $siteId) !== Constants::ROLE_WORKER) {
			throw new NotFoundException();
		}
		if (!$this->configFor($siteId)['enabled']) {
			return; // site did not opt in — heartbeats are a no-op
		}
		$now = $this->timeFactory->getDateTime();
		$this->db->beginTransaction();
		try {
			$state = $this->states->findForWorker($roundId, $uid);
			if ($state === null) {
				$state = new ManDownState();
				$state->setRoundId($roundId);
				$state->setWorkerUid($uid);
				$state->setSiteId($siteId);
				$state->setPhase('watching');
				$state->setPrealarmAt(null);
				$state->setLastActivityAt($now);
				$state->setUpdatedAt($now);
				$this->states->insert($state);
			} elseif ($state->getPhase() === 'watching') {
				// 'alarmed' must NOT be reset by a late heartbeat — only an
				// acknowledged alert resolves it (an alarmed worker's phone
				// pinging again must not un-ring the bell).
				$state->setLastActivityAt($now);
				$state->setPrealarmAt(null);
				$state->setUpdatedAt($now);
				$this->states->update($state);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Explicit alarm from the device (pre-alarm window expired unanswered)
	 * or manual SOS. Idempotent: same round+worker while alarmed returns the
	 * existing alert.
	 */
	public function triggerAlarm(int $roundId, string $uid, string $source = 'device'): array
	{
		$round = $this->rounds->findById($roundId);
		if ($round === null) {
			throw new NotFoundException();
		}
		$siteId = (int)$round->getSiteId();
		// The cron sweep has no session — only enforce actor checks when a
		// device actually triggered this (source=device/manual come through
		// the companion API where the caller IS the worker).
		if ($source !== 'server_inactivity') {
			$this->acl->assertCanViewSite($siteId);
			if ($round->getStartedBy() !== $uid && $this->acl->siteRole($uid, $siteId) !== Constants::ROLE_WORKER) {
				throw new NotFoundException();
			}
		}
		$now = $this->timeFactory->getDateTime();

		// Claim the phase transition — concurrent device alarm + sweep race
		// here; exactly one wins the update.
		$this->db->beginTransaction();
		try {
			$state = $this->states->findForWorker($roundId, $uid);
			if ($state !== null && $state->getPhase() === 'alarmed') {
				$this->db->commit();
				return ['already' => true];
			}
			if ($state === null) {
				$state = new ManDownState();
				$state->setRoundId($roundId);
				$state->setWorkerUid($uid);
				$state->setSiteId($siteId);
				$state->setLastActivityAt($now);
				$state->setPhase('alarmed');
				$state->setUpdatedAt($now);
				$this->states->insert($state);
			} else {
				$state->setPhase('alarmed');
				$state->setUpdatedAt($now);
				$this->states->update($state);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$alert = $this->alerts->fire(
			Constants::ALERT_MANDOWN,
			$siteId,
			$uid,
			'critical',
			['source' => $source],
			sprintf('md:%d:%s:%s', $roundId, $uid, $now->format('YmdH')),
			$roundId
		);
		return ['already' => false, 'alertId' => (int)$alert->getId()];
	}

	/**
	 * Cron sweep — fire for every watching state whose clock expired.
	 * Returns fired count.
	 */
	public function sweep(): int
	{
		$fired = 0;
		// Site-specific thresholds differ, so fetch watching rows and check
		// per-row against that site's mandown_min. Bounded.
		foreach ($this->states->findStale(new \DateTime('-1 minute'), 200) as $state) {
			$cfg = $this->configFor((int)$state->getSiteId());
			if (!$cfg['enabled']) {
				continue;
			}
			$deadline = $state->getLastActivityAt()->getTimestamp() + $cfg['inactivityMin'] * 60;
			if ($this->timeFactory->getTime() < $deadline) {
				continue;
			}
			$round = $this->rounds->findById((int)$state->getRoundId());
			if ($round === null || $round->getStatus() !== 'active') {
				// Round ended without a close sweep — close the watch.
				$state->setPhase('closed');
				$state->setUpdatedAt($this->timeFactory->getDateTime());
				$this->states->update($state);
				continue;
			}
			$this->triggerAlarm((int)$state->getRoundId(), (string)$state->getWorkerUid(), 'server_inactivity');
			$fired++;
		}
		return $fired;
	}

	/** Close watches when a round ends — called from RoundService close/skip. */
	public function closeForRound(int $roundId): void
	{
		$this->states->closeForRound($roundId);
	}
}
