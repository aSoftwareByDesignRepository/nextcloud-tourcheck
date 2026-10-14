<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * GDPR erasure without breaking the chain: evidence rows are tombstoned
 * (content nulled, tombstoned=1) — the journal keeps an `erasure` entry so
 * the hole is itself evidence. Journal rows are NEVER deleted; bulk
 * retention purges write one `purged_range` entry covering the range.
 */
class ErasureService
{
	public function __construct(
		private readonly ChainService $chain,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly SettingsService $settings,
		private readonly PhotoService $photos,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Erasure of personal data for one uid on a site (admin/DSR path, Art. 17).
	 * Sweeps every uid-PII column in the schema: events (actor + attester,
	 * incl. attestation_reason/signature), geo/client_ts, incident creator,
	 * corrective assignee/verifier, plan assignee, key ledger holder/actor,
	 * round started/completed-by, live positions, and the evidence FILES
	 * (photo/audio/signature) behind fileid references.
	 *
	 * Scope note: tck_journal is untouched — actor_uid/body sit inside the
	 * entry hash, so scrubbing them would read as tampering (spec §459/NG-1).
	 * tck_site_member is untouched — membership removal is a separate audited
	 * flow; a member row alone is an operational record, not evidence PII.
	 */
	public function eraseUid(int $siteId, string $targetUid, string $actorUid): int
	{
		$this->acl->assertCanManageSite($siteId);
		$this->db->beginTransaction();
		try {
			$count = 0;
			// 1. Evidence file ids BEFORE the tombstone nulls the references —
			//    actor rows carry photo+signature, attestations carry the
			//    attester's signature, incidents carry photo+audio.
			$fileIds = [];
			foreach ([
				['tck_event', 'photo_fileid', 'actor_uid'],
				['tck_event', 'signature_fileid', 'actor_uid'],
				['tck_event', 'signature_fileid', 'attester_uid'],
				['tck_incident', 'photo_fileid', 'created_by'],
				['tck_incident', 'audio_fileid', 'created_by'],
			] as [$table, $fileCol, $uidCol]) {
				$sel = $this->db->getQueryBuilder();
				$sel->select($fileCol)->from($table)
					->where($sel->expr()->eq('site_id', $sel->createNamedParameter($siteId)))
					->andWhere($sel->expr()->eq($uidCol, $sel->createNamedParameter($targetUid)))
					->andWhere($sel->expr()->isNotNull($fileCol));
				$res = $sel->executeQuery();
				while ($row = $res->fetch()) {
					$fileIds[] = (int)$row[$fileCol];
				}
			}

			// 2. Events where target is the actor — full tombstone (same
			//    column set as purgeRetention, chain-safe).
			$ev = $this->db->getQueryBuilder();
			$ev->update('tck_event')
				->set('actor_uid', $ev->createNamedParameter(null))
				->set('attester_uid', $ev->createNamedParameter(null))
				->set('station_member_id', $ev->createNamedParameter(null))
				->set('note', $ev->createNamedParameter(null))
				->set('values_json', $ev->createNamedParameter(null))
				->set('photo_fileid', $ev->createNamedParameter(null))
				->set('signature_fileid', $ev->createNamedParameter(null))
				->set('attestation_reason', $ev->createNamedParameter(null))
				->set('client_ts', $ev->createNamedParameter(null))
				->set('geo_lat', $ev->createNamedParameter(null))
				->set('geo_lon', $ev->createNamedParameter(null))
				->set('geo_acc', $ev->createNamedParameter(null))
				->set('tombstoned', $ev->createNamedParameter(1))
				->where($ev->expr()->eq('site_id', $ev->createNamedParameter($siteId)))
				->andWhere($ev->expr()->eq('actor_uid', $ev->createNamedParameter($targetUid)));
			$count += $ev->executeStatement();

			// 3. Attestations the target made on OTHER actors' events —
			//    uid, free-text reason and the attester's signature file.
			$att = $this->db->getQueryBuilder();
			$att->update('tck_event')
				->set('attester_uid', $att->createNamedParameter(null))
				->set('attestation_reason', $att->createNamedParameter(null))
				->set('signature_fileid', $att->createNamedParameter(null))
				->where($att->expr()->eq('site_id', $att->createNamedParameter($siteId)))
				->andWhere($att->expr()->eq('attester_uid', $att->createNamedParameter($targetUid)));
			$count += $att->executeStatement();

			// 4. Incidents the target authored — creator + content + files.
			//    (Incidents carry no assignee_uid — assignment lives on the
			//    corrective action, scrubbed below.)
			$inc = $this->db->getQueryBuilder();
			$inc->update('tck_incident')
				->set('created_by', $inc->createNamedParameter(null))
				->set('note', $inc->createNamedParameter(null))
				->set('title', $inc->createNamedParameter(null))
				->set('photo_fileid', $inc->createNamedParameter(null))
				->set('audio_fileid', $inc->createNamedParameter(null))
				->where($inc->expr()->eq('site_id', $inc->createNamedParameter($siteId)))
				->andWhere($inc->expr()->eq('created_by', $inc->createNamedParameter($targetUid)));
			$count += $inc->executeStatement();

			// 5. Corrective actions — assignee/verifier (site via incident).
			//    DBAL expr()->in() quotes a string as an identifier, not raw
			//    SQL — fetch the ids first (bounded, admin path).
			$incSel = $this->db->getQueryBuilder();
			$incSel->select('id')->from('tck_incident')
				->where($incSel->expr()->eq('site_id', $incSel->createNamedParameter($siteId)));
			$incIds = array_map('intval', $incSel->executeQuery()->fetchFirstColumn());
			if ($incIds !== []) {
				$corr = $this->db->getQueryBuilder();
				$corr->update('tck_corrective')
					->set('assignee_uid', $corr->createNamedParameter(null))
					->set('verified_by', $corr->createNamedParameter(null))
					->where($corr->expr()->in('incident_id', $corr->createNamedParameter($incIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($corr->expr()->orX(
						$corr->expr()->eq('assignee_uid', $corr->createNamedParameter($targetUid)),
						$corr->expr()->eq('verified_by', $corr->createNamedParameter($targetUid)),
					));
				$count += $corr->executeStatement();
			}

			// 6. Key ledger — current custodian, movement actor, holder the
			//    movement was recorded for, and target-authored log notes.
			$k = $this->db->getQueryBuilder();
			$k->update('tck_key')->set('holder_uid', $k->createNamedParameter(null))
				->where($k->expr()->eq('site_id', $k->createNamedParameter($siteId)))
				->andWhere($k->expr()->eq('holder_uid', $k->createNamedParameter($targetUid)));
			$count += $k->executeStatement();
			foreach (['actor_uid', 'holder_uid'] as $col) {
				$k = $this->db->getQueryBuilder();
				$k->update('tck_key_log')
					->set($col, $k->createNamedParameter(null))
					->set('note', $k->createNamedParameter(null))
					->where($k->expr()->eq('site_id', $k->createNamedParameter($siteId)))
					->andWhere($k->expr()->eq($col, $k->createNamedParameter($targetUid)));
				$count += $k->executeStatement();
			}

			// 6b. Plans the target is assigned to.
			$p = $this->db->getQueryBuilder();
			$p->update('tck_plan')->set('assignee', $p->createNamedParameter(null))
				->where($p->expr()->eq('site_id', $p->createNamedParameter($siteId)))
				->andWhere($p->expr()->eq('assignee', $p->createNamedParameter($targetUid)));
			$count += $p->executeStatement();

			// 7. Round lifecycle actors.
			foreach (['started_by', 'completed_by'] as $col) {
				$r = $this->db->getQueryBuilder();
				$r->update('tck_round')->set($col, $r->createNamedParameter(null))
					->where($r->expr()->eq('site_id', $r->createNamedParameter($siteId)))
					->andWhere($r->expr()->eq($col, $r->createNamedParameter($targetUid)));
				$count += $r->executeStatement();
			}

			// 8. Live positions are the heaviest PII (movement breadcrumbs) and
			//    not chain-bound — hard delete, they self-expire anyway.
			$roundSel = $this->db->getQueryBuilder();
			$roundSel->select('id')->from('tck_round')
				->where($roundSel->expr()->eq('site_id', $roundSel->createNamedParameter($siteId)));
			$roundIds = array_map('intval', $roundSel->executeQuery()->fetchFirstColumn());
			if ($roundIds !== []) {
				$pos = $this->db->getQueryBuilder();
				$pos->delete('tck_position')
					->where($pos->expr()->eq('actor_uid', $pos->createNamedParameter($targetUid)))
					->andWhere($pos->expr()->in('round_id', $pos->createNamedParameter($roundIds, IQueryBuilder::PARAM_INT_ARRAY)));
				$count += $pos->executeStatement();
			}

			// 9. Evidence files — best-effort inside the txn; a locked file
			//    must not resurrect the DB rows (PhotoService::delete logs).
			foreach ($fileIds as $fileId) {
				$this->photos->delete($siteId, $fileId);
			}

			$this->chain->append($siteId, [
				'kind' => Constants::KIND_ERASURE,
				'actorUid' => $actorUid,
				'body' => 'erasure:' . $count . ' rows',
				'payloadRef' => 'erasure:' . $siteId . ':' . hash('sha256', $targetUid),
				'payloadFields' => ['site' => $siteId, 'rows' => $count, 'uid_hash' => hash('sha256', $targetUid)],
			]);
			$this->db->commit();
			return $count;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Retention purge — bulk-tombstone old evidence rows, then ONE
	 * `purged_range` journal entry records the range. Deleting rows outright
	 * would look exactly like tampering; tombstoning keeps that distinction.
	 */
	public function purgeRetention(int $siteId, string $actorUid = 'cron'): int
	{
		$years = $this->settings->getJournalRetentionYears();
		$cutoff = $this->timeFactory->getDateTime()->modify('-' . $years . ' years');
		$this->db->beginTransaction();
		try {
			// Find the range first (for the journal entry).
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->min('id'), $qb->func()->max('id'), $qb->func()->count('id'))
				->from('tck_event')
				->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
				->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')))
				->andWhere($qb->expr()->eq('tombstoned', $qb->createNamedParameter(0)));
			$row = $qb->executeQuery()->fetchNumeric();
			$count = (int)($row[2] ?? 0);
			if ($count === 0) {
				$this->db->rollBack();
				return 0;
			}
			[$minId, $maxId] = [(int)$row[0], (int)$row[1]];

			$u = $this->db->getQueryBuilder();
			$u->update('tck_event')
				->set('actor_uid', $u->createNamedParameter(null))
				->set('attester_uid', $u->createNamedParameter(null))
				->set('station_member_id', $u->createNamedParameter(null))
				->set('note', $u->createNamedParameter(null))
				->set('values_json', $u->createNamedParameter(null))
				->set('photo_fileid', $u->createNamedParameter(null))
				->set('signature_fileid', $u->createNamedParameter(null))
				->set('attestation_reason', $u->createNamedParameter(null))
				->set('client_ts', $u->createNamedParameter(null))
				->set('geo_lat', $u->createNamedParameter(null))
				->set('geo_lon', $u->createNamedParameter(null))
				->set('geo_acc', $u->createNamedParameter(null))
				->set('tombstoned', $u->createNamedParameter(1))
				->where($u->expr()->eq('site_id', $u->createNamedParameter($siteId)))
				->andWhere($u->expr()->lt('created_at', $u->createNamedParameter($cutoff, 'datetime')))
				->andWhere($u->expr()->eq('tombstoned', $u->createNamedParameter(0)));
			$u->executeStatement();

			$this->chain->append($siteId, [
				'kind' => Constants::KIND_PURGED_RANGE,
				'actorUid' => $actorUid,
				'body' => 'purged_range:' . $minId . '-' . $maxId . ':' . $count,
				'payloadRef' => 'purge:' . $siteId,
				'payloadFields' => ['site' => $siteId, 'min_id' => $minId, 'max_id' => $maxId, 'rows' => $count],
			]);
			$this->db->commit();
			return $count;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Cron entry — all sites. */
	public function purgeAllSites(): int
	{
		$total = 0;
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('tck_site')->where($qb->expr()->eq('active', $qb->createNamedParameter(1)));
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$total += $this->purgeRetention((int)$row['id']);
		}
		$res->closeCursor();
		return $total;
	}
}
