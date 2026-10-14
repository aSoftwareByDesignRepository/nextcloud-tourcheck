<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\Checkpoint;
use OCA\TourCheck\Db\CheckpointMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class CheckpointService
{
	public function __construct(
		private readonly CheckpointMapper $checkpoints,
		private readonly TokenService $tokens,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly ChainService $chain,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/** @return list<Checkpoint> */
	public function listForSite(int $siteId, bool $includeInactive = false): array
	{
		$this->acl->assertCanViewSite($siteId);
		return $this->checkpoints->findForSite($siteId, $includeInactive);
	}

	/** Internal fetch incl. archived — snapshot generation only, no ACL. */
	public function getAny(int $checkpointId): ?Checkpoint
	{
		return $this->checkpoints->find($checkpointId);
	}

	public function getInSite(int $checkpointId, int $siteId): Checkpoint
	{
		$cp = $this->checkpoints->find($checkpointId);
		if ($cp === null || (int)$cp->getSiteId() !== $siteId || (int)$cp->getActive() !== 1) {
			throw new NotFoundException();
		}
		return $cp;
	}

	/**
	 * Create + issue a QR token in one transaction. Returns
	 * [checkpoint, rawToken] — the raw token is shown once for printing.
	 * @return array{0: Checkpoint, 1: string}
	 */
	public function create(int $siteId, array $data): array
	{
		$this->sites->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);

		$name = trim((string)($data['name'] ?? ''));
		if ($name === '' || strlen($name) > 190) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Checkpoint name is required.', ['name' => 'required']);
		}
		$geoLat = isset($data['geo_lat']) && $data['geo_lat'] !== '' ? (float)$data['geo_lat'] : null;
		$geoLon = isset($data['geo_lon']) && $data['geo_lon'] !== '' ? (float)$data['geo_lon'] : null;
		$geoRadius = isset($data['geo_radius']) && $data['geo_radius'] !== '' ? max(10, min(1000, (int)$data['geo_radius'])) : null;
		if (($geoLat !== null) !== ($geoLon !== null)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Latitude and longitude must both be set.', ['geo' => 'incomplete']);
		}
		if ($geoLat !== null && (abs($geoLat) > 90 || abs($geoLon) > 180)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Coordinates out of range.', ['geo' => 'invalid']);
		}
		$profile = $this->validateProfile($data['profile'] ?? null);

		$now = $this->timeFactory->getDateTime();
		$this->db->beginTransaction();
		try {
			$cp = new Checkpoint();
			$cp->setSiteId($siteId);
			$cp->setName($name);
			$cp->setInstructions(isset($data['instructions']) && trim((string)$data['instructions']) !== '' ? trim((string)$data['instructions']) : null);
			$cp->setOrderHint((int)($data['order_hint'] ?? 0));
			$cp->setProfileJson($profile !== null ? json_encode($profile) : null);
			$cp->setGeoLat($geoLat);
			$cp->setGeoLon($geoLon);
			$cp->setGeoRadius($geoLat !== null ? ($geoRadius ?? 100) : null);
			$cp->setActive(1);
			$cp->setRevision(1);
			$cp->setCreatedAt($now);
			$cp->setUpdatedAt($now);
			$cp = $this->checkpoints->insert($cp);
			$kind = in_array($data['kind'] ?? 'qr', ['qr', 'nfc', 'manual'], true) ? (string)$data['kind'] : 'qr';
			$raw = $this->tokens->issue($cp, $kind);
			$this->chain->append($siteId, [
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $uid,
				'body' => 'checkpoint_created',
				'payloadRef' => 'checkpoint:' . $cp->getId(),
				'payloadFields' => ['checkpoint' => (int)$cp->getId(), 'site' => $siteId, 'name' => $name],
			]);
			$this->db->commit();
			return [$cp, $raw];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function update(int $siteId, int $checkpointId, array $data, ?int $baseRevision): Checkpoint
	{
		$this->sites->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);
		$this->getInSite($checkpointId, $siteId);
		// Row lock before the CAS check — otherwise two writers both pass
		// assertRevision on the same base and the second silently wins.
		$this->db->beginTransaction();
		try {
			$cp = $this->checkpoints->findForUpdate($checkpointId);
			if ($cp === null || (int)$cp->getSiteId() !== $siteId || (int)$cp->getActive() !== 1) {
				throw new NotFoundException();
			}
			$this->sites->assertRevision($cp->getRevision(), $baseRevision);

			if (array_key_exists('name', $data)) {
				$name = trim((string)$data['name']);
				if ($name === '' || strlen($name) > 190) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Checkpoint name is required.', ['name' => 'required']);
				}
				$cp->setName($name);
			}
			if (array_key_exists('instructions', $data)) {
				$cp->setInstructions(trim((string)$data['instructions']) ?: null);
			}
			if (array_key_exists('order_hint', $data)) {
				$cp->setOrderHint((int)$data['order_hint']);
			}
			if (array_key_exists('profile', $data)) {
				$profile = $this->validateProfile($data['profile']);
				$cp->setProfileJson($profile !== null ? json_encode($profile) : null);
			}
			if (array_key_exists('geo_lat', $data) || array_key_exists('geo_lon', $data)) {
				$lat = $data['geo_lat'] === null || $data['geo_lat'] === '' ? null : (float)$data['geo_lat'];
				$lon = $data['geo_lon'] === null || $data['geo_lon'] === '' ? null : (float)$data['geo_lon'];
				if (($lat !== null) !== ($lon !== null) || ($lat !== null && (abs($lat) > 90 || abs($lon) > 180))) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid coordinates.', ['geo' => 'invalid']);
				}
				$cp->setGeoLat($lat);
				$cp->setGeoLon($lon);
				$cp->setGeoRadius($lat !== null ? max(10, min(1000, (int)($data['geo_radius'] ?? $cp->getGeoRadius() ?? 100))) : null);
			}
			$cp->setRevision((int)$cp->getRevision() + 1);
			$cp->setUpdatedAt($this->timeFactory->getDateTime());
			$cp = $this->checkpoints->update($cp);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $uid,
			'body' => 'checkpoint_updated',
			'payloadRef' => 'checkpoint:' . $checkpointId,
			'payloadFields' => ['checkpoint' => $checkpointId, 'revision' => (int)$cp->getRevision()],
		]);
		return $cp;
	}

	public function archive(int $siteId, int $checkpointId): void
	{
		$this->sites->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);
		$this->getInSite($checkpointId, $siteId);
		$this->db->beginTransaction();
		try {
			// Re-read under the row lock — the entity fetched above may be
			// stale by now (a concurrent update() holds the lock).
			$cp = $this->checkpoints->findForUpdate($checkpointId);
			if ($cp === null || (int)$cp->getSiteId() !== $siteId || (int)$cp->getActive() !== 1) {
				throw new NotFoundException();
			}
			$cp->setActive(0);
			$cp->setRevision((int)$cp->getRevision() + 1);
			$cp->setUpdatedAt($this->timeFactory->getDateTime());
			$this->checkpoints->update($cp);
			// All live tokens die with the checkpoint — a scan on a torn-down
			// QR must resolve as invalid, not "checkpoint gone".
			$this->tokens->revokeAllForCheckpoint($checkpointId);
			$this->chain->append($siteId, [
				'kind' => Constants::KIND_ADMIN,
				'actorUid' => $uid,
				'body' => 'checkpoint_archived',
				'payloadRef' => 'checkpoint:' . $checkpointId,
				'payloadFields' => ['checkpoint' => $checkpointId],
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Mint fresh printable tokens for every active checkpoint of a site —
	 * the QR sheet re-print flow. One journal entry covers the whole sheet.
	 * @return list<array{checkpoint: Checkpoint, token: string}>
	 */
	public function sheetTokensForSite(int $siteId): array
	{
		$this->sites->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);
		$cps = $this->checkpoints->findForSite($siteId);
		$kinds = $this->tokens->activeKindMap(array_map(static fn ($c) => (int)$c->getId(), $cps));
		$rows = [];
		foreach ($cps as $cp) {
			// Manual checkpoints are button-confirmed by design — no wall code.
			if (($kinds[(int)$cp->getId()] ?? 'qr') === 'manual') {
				continue;
			}
			$rows[] = [
				'checkpoint' => $cp,
				'token' => $this->tokens->rotate($cp, 'qr'),
			];
		}
		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $uid,
			'body' => 'sheet_printed',
			'payloadRef' => 'site:' . $siteId,
			'payloadFields' => ['checkpoints' => count($rows)],
		]);
		return $rows;
	}

	/** @return array{0: string} new raw token for re-printing */
	public function rotateToken(int $siteId, int $checkpointId): array
	{
		$this->sites->getVisible($siteId);
		$uid = $this->acl->assertCanManageSite($siteId);
		$cp = $this->getInSite($checkpointId, $siteId);
		$raw = $this->tokens->rotate($cp, 'qr');
		$this->chain->append($siteId, [
			'kind' => Constants::KIND_ADMIN,
			'actorUid' => $uid,
			'body' => 'token_rotated',
			'payloadRef' => 'checkpoint:' . $checkpointId,
			'payloadFields' => ['checkpoint' => $checkpointId],
		]);
		return [$raw];
	}

	/**
	 * Checklist/profile schema validation — values_json at capture time must
	 * validate against this snapshot (TH: no free-form value injection).
	 * @return array{items:list<array{key:string,label:string,required:bool,kind:string}>}|null
	 */
	public function validateProfile(mixed $profile): ?array
	{
		if ($profile === null || $profile === '' || $profile === []) {
			return null;
		}
		if (!is_array($profile) || !isset($profile['items']) || !is_array($profile['items'])) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Profile needs an items list.', ['profile' => 'invalid']);
		}
		$items = [];
		foreach ($profile['items'] as $i => $item) {
			if (!is_array($item)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid checklist item.', ['profile' => 'invalid']);
			}
			$key = trim((string)($item['key'] ?? ''));
			$label = trim((string)($item['label'] ?? ''));
			$kind = in_array($item['kind'] ?? '', ['bool', 'photo', 'number', 'text'], true) ? (string)$item['kind'] : 'bool';
			if ($key === '' || strlen($key) > 64 || !preg_match('/^[a-z0-9_-]+$/', $key)) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Invalid item key.', ['profile' => 'invalid']);
			}
			if ($label === '' || strlen($label) > 190) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Item label required.', ['profile' => 'invalid']);
			}
			$clean = ['key' => $key, 'label' => $label, 'required' => in_array($item['required'] ?? false, [true, 1, '1', 'true'], true), 'kind' => $kind];
			// NH-05: number items may carry a measurement band — out-of-band
			// values at confirm mint an auto-incident. Unit is display-only.
			if ($kind === 'number') {
				$min = $item['min'] ?? null;
				$max = $item['max'] ?? null;
				if ($min !== null && !is_numeric($min)) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Threshold min must be numeric.', ['profile' => 'invalid']);
				}
				if ($max !== null && !is_numeric($max)) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Threshold max must be numeric.', ['profile' => 'invalid']);
				}
				if ($min !== null && $max !== null && (float)$min > (float)$max) {
					throw new ValidationException(Constants::ERR_VALIDATION, 'Threshold min exceeds max.', ['profile' => 'invalid']);
				}
				if ($min !== null) {
					$clean['min'] = (float)$min;
				}
				if ($max !== null) {
					$clean['max'] = (float)$max;
				}
				if (isset($item['unit']) && is_string($item['unit']) && strlen($item['unit']) <= 16) {
					$clean['unit'] = $item['unit'];
				}
			}
			$items[] = $clean;
			if (count($items) > 50) {
				throw new ValidationException(Constants::ERR_VALIDATION, 'Too many checklist items.', ['profile' => 'invalid']);
			}
		}
		return $items === [] ? null : ['items' => $items];
	}
}
