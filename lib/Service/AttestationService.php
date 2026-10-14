<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Supervisor attestation — the explicit "I vouch for this" path.
 * Renders as its own evidence grade (never as a scan, NN-07). Requires a
 * reason ≥ 10 chars: an attest without stated cause is theater.
 */
class AttestationService
{
	public function __construct(
		private readonly EventService $events,
		private readonly TokenService $tokens,
		private readonly AccessControlService $acl,
		private readonly CheckpointService $checkpoints,
		private readonly ChainService $chain,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{event: CheckEvent, replayed: bool, attached: bool}
	 */
	public function attest(int $siteId, int $checkpointId, string $reason, ?string $clientEventId, ?string $clientTs, ?int $roundId): array
	{
		$uid = $this->acl->assertCanSuperviseSite($siteId);
		$reason = trim($reason);
		if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'A reason (10–1000 chars) is required for attestation.', ['reason' => 'required']);
		}
		$cp = $this->checkpoints->getInSite($checkpointId, $siteId);
		$cid = $clientEventId !== null && trim($clientEventId) !== '' ? trim($clientEventId) : 'attest_' . bin2hex(random_bytes(16));

		$result = $this->events->ingest([
			'token' => '__attest__' . $checkpointId, // resolved below, bypassed via direct cp
			'client_event_id' => $cid,
			'client_ts' => $clientTs,
			'method' => Constants::METHOD_ATTEST,
			'actor_uid' => $uid,
			'round_id' => $roundId,
			'attestation_reason' => $reason,
			'_checkpoint' => $cp,
		]);
		return $result;
	}
}
