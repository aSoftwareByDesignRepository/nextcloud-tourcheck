<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Integration;

use OCA\TourCheck\Service\ErasureService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Regression: RetentionPurgeJob used to fatal on a non-existent fetch
 * constant (IQueryBuilder::FETCH_NUMERIC), so retention never ran — and the
 * NH-02 geo columns leaked past tombstoning. This test walks the real DB
 * path so both defects stay dead.
 */
final class RetentionPurgeIntegrationTest extends TestCase
{
	private int $siteId = 0;

	public function testPurgeTombstonesOldEventsAndWipesGeo(): void
	{
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);
		/** @var ErasureService $svc */
		$svc = \OC::$server->get(ErasureService::class);

		// Dedicated site so the purge count can't be skewed by seeded rows.
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_site')->values([
			'name' => $qb->createNamedParameter('RetentionPurgeIT'),
			'tz' => $qb->createNamedParameter('UTC'),
			'live_position' => $qb->createNamedParameter(0),
			'active' => $qb->createNamedParameter(1),
			'revision' => $qb->createNamedParameter(1),
			'created_at' => $qb->createNamedParameter(new \DateTime('2000-01-01'), 'datetime'),
			'updated_at' => $qb->createNamedParameter(new \DateTime('2000-01-01'), 'datetime'),
		])->executeStatement();
		$this->siteId = (int)$db->lastInsertId('tck_site');

		// Old event (default retention = 10y) carrying PII columns incl. geo.
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_event')->values([
			'site_id' => $qb->createNamedParameter($this->siteId),
			'client_event_id' => $qb->createNamedParameter('purge-it-1'),
			'actor_uid' => $qb->createNamedParameter('worker1'),
			'method' => $qb->createNamedParameter('scan_qr'),
			'note' => $qb->createNamedParameter('pii note'),
			'server_received_at' => $qb->createNamedParameter(new \DateTime('2000-06-01'), 'datetime'),
			'late_sync' => $qb->createNamedParameter(0),
			'flagged_early' => $qb->createNamedParameter(0),
			'orphan' => $qb->createNamedParameter(1),
			'tombstoned' => $qb->createNamedParameter(0),
			'created_at' => $qb->createNamedParameter(new \DateTime('2000-06-01'), 'datetime'),
			'geo_lat' => $qb->createNamedParameter(52.52),
			'geo_lon' => $qb->createNamedParameter(13.405),
			'geo_acc' => $qb->createNamedParameter(12),
		])->executeStatement();

		$purged = $svc->purgeRetention($this->siteId);
		self::assertSame(1, $purged, 'the old event must be tombstoned');

		$q = $db->getQueryBuilder();
		$row = $q->select('tombstoned', 'actor_uid', 'note', 'geo_lat', 'geo_lon', 'geo_acc')
			->from('tck_event')
			->where($q->expr()->eq('client_event_id', $q->createNamedParameter('purge-it-1')))
			->executeQuery()->fetch();
		self::assertNotFalse($row);
		self::assertSame(1, (int)$row['tombstoned']);
		self::assertNull($row['actor_uid']);
		self::assertNull($row['note']);
		// GDPR: geo is PII — tombstoning must wipe it, not just the note.
		self::assertNull($row['geo_lat']);
		self::assertNull($row['geo_lon']);
		self::assertNull($row['geo_acc']);

		// The purge itself is journaled — absence would look like tampering.
		$j = $db->getQueryBuilder();
		$journal = $j->select('kind', 'payload_ref')
			->from('tck_journal')
			->where($j->expr()->eq('site_id', $j->createNamedParameter($this->siteId)))
			->andWhere($j->expr()->eq('kind', $j->createNamedParameter('purged_range')))
			->executeQuery()->fetchAll();
		self::assertCount(1, $journal);

		// Second run is a clean no-op (idempotent cron).
		self::assertSame(0, $svc->purgeRetention($this->siteId));
	}

	/**
	 * DSR/Art. 17: eraseUid must sweep every uid-PII column — actor AND
	 * attester on events, geo/client_ts, incident creator+assignee, corrective
	 * assignee/verifier, key ledger holder/actor, round started/completed-by,
	 * live positions — and journal the erasure itself.
	 */
	public function testEraseUidSweepsAllUidColumns(): void
	{
		$db = \OC::$server->get(IDBConnection::class);
		$svc = \OC::$server->get(ErasureService::class);

		// Real login: assertCanManageSite resolves the actor from the session.
		$mgr = \OC::$server->get(\OCP\IUserManager::class);
		\OC::$server->get(\OCP\IUserSession::class)->setUser($mgr->get('tck_atlas'));

		$qb = $db->getQueryBuilder();
		$qb->insert('tck_site')->values([
			'name' => $qb->createNamedParameter('EraseUidIT'),
			'tz' => $qb->createNamedParameter('UTC'),
			'live_position' => $qb->createNamedParameter(1),
			'active' => $qb->createNamedParameter(1),
			'revision' => $qb->createNamedParameter(1),
			'created_at' => $qb->createNamedParameter(new \DateTime('2000-01-01'), 'datetime'),
			'updated_at' => $qb->createNamedParameter(new \DateTime('2000-01-01'), 'datetime'),
		])->executeStatement();
		$this->siteId = (int)$db->lastInsertId('tck_site');
		$site = $this->siteId;

		// Site member row so tck_atlas qualifies as manager of THIS site.
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_site_member')->values([
			'site_id' => $qb->createNamedParameter($site),
			'uid' => $qb->createNamedParameter('tck_atlas'),
			'role' => $qb->createNamedParameter('admin'),
			'created_at' => $qb->createNamedParameter(new \DateTime(), 'datetime'),
		])->executeStatement();

		$now = new \DateTime();
		// A plan assigned to the target — the assignee leg.
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_plan')->values([
			'site_id' => $qb->createNamedParameter($site),
			'name' => $qb->createNamedParameter('Nachtrunde'),
			'cps_json' => $qb->createNamedParameter('[]'),
			'recurrence_json' => $qb->createNamedParameter('{}'),
			'assignee' => $qb->createNamedParameter('worker1'),
			'active' => $qb->createNamedParameter(1),
			'revision' => $qb->createNamedParameter(1),
			'created_at' => $qb->createNamedParameter($now, 'datetime'),
			'updated_at' => $qb->createNamedParameter($now, 'datetime'),
		])->executeStatement();
		$planId = (int)$db->lastInsertId('tck_plan');

		// Round owned by the target — started_by/completed_by legs.
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_round')->values([
			'plan_id' => $qb->createNamedParameter($planId),
			'site_id' => $qb->createNamedParameter($site),
			'snapshot_json' => $qb->createNamedParameter('{"plan":{"id":1,"name":"Nachtrunde","revision":1},"cps":[],"recurrence":{"type":"daily","time":"20:00","duration_min":120}}'),
			'scheduled_start' => $qb->createNamedParameter($now, 'datetime'),
			'window_end' => $qb->createNamedParameter((clone $now)->modify('+2 hours'), 'datetime'),
			'status' => $qb->createNamedParameter('completed'),
			'started_by' => $qb->createNamedParameter('worker1'),
			'completed_by' => $qb->createNamedParameter('worker1'),
			'created_at' => $qb->createNamedParameter($now, 'datetime'),
			'updated_at' => $qb->createNamedParameter($now, 'datetime'),
		])->executeStatement();
		$roundId = (int)$db->lastInsertId('tck_round');

		// Two events: one BY the target (geo+note+ts PII), one by another actor
		// but ATTESTED by the target (attester leg leaks without the 2nd pass).
		foreach ([
			['ceid' => 'e-act-' . $site, 'actor' => 'worker1', 'attester' => null],
			['ceid' => 'e-att-' . $site, 'actor' => 'worker2', 'attester' => 'worker1'],
		] as $ev) {
			$qb = $db->getQueryBuilder();
			$qb->insert('tck_event')->values([
				'site_id' => $qb->createNamedParameter($site),
				'round_id' => $qb->createNamedParameter($roundId),
				'client_event_id' => $qb->createNamedParameter($ev['ceid']),
				'actor_uid' => $qb->createNamedParameter($ev['actor']),
				'attester_uid' => $qb->createNamedParameter($ev['attester']),
				'attestation_reason' => $qb->createNamedParameter($ev['attester'] === null ? null : 'attest reason'),
				'signature_fileid' => $qb->createNamedParameter($ev['attester'] === null ? 111 : 222),
				'method' => $qb->createNamedParameter('scan_qr'),
				'note' => $qb->createNamedParameter('pii note'),
				'server_received_at' => $qb->createNamedParameter($now, 'datetime'),
				'late_sync' => $qb->createNamedParameter(0),
				'flagged_early' => $qb->createNamedParameter(0),
				'orphan' => $qb->createNamedParameter(0),
				'tombstoned' => $qb->createNamedParameter(0),
				'created_at' => $qb->createNamedParameter($now, 'datetime'),
				'client_ts' => $qb->createNamedParameter($now, 'datetime'),
				'geo_lat' => $qb->createNamedParameter(52.52),
				'geo_lon' => $qb->createNamedParameter(13.405),
				'geo_acc' => $qb->createNamedParameter(10),
			])->executeStatement();
		}

		// Live position breadcrumbs for the target on this round.
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_position')->values([
			'round_id' => $qb->createNamedParameter($roundId),
			'actor_uid' => $qb->createNamedParameter('worker1'),
			'lat' => $qb->createNamedParameter(52.52),
			'lon' => $qb->createNamedParameter(13.405),
			'accuracy_m' => $qb->createNamedParameter(10),
			'server_received_at' => $qb->createNamedParameter($now, 'datetime'),
			'expires_at' => $qb->createNamedParameter((clone $now)->modify('+7 days'), 'datetime'),
		])->executeStatement();

		// Incident: target authored one; another authored the second (whose
		// corrective is assigned to + verified by the target).
		foreach (['worker1', 'worker2'] as $createdBy) {
			$qb = $db->getQueryBuilder();
			$qb->insert('tck_incident')->values([
				'site_id' => $qb->createNamedParameter($site),
				'category' => $qb->createNamedParameter('general'),
				'title' => $qb->createNamedParameter('incident title'),
				'note' => $qb->createNamedParameter('pii note'),
				'severity' => $qb->createNamedParameter('low'),
				'status' => $qb->createNamedParameter('open'),
				'revision' => $qb->createNamedParameter(1),
				'created_by' => $qb->createNamedParameter($createdBy),
				'created_at' => $qb->createNamedParameter($now, 'datetime'),
				'updated_at' => $qb->createNamedParameter($now, 'datetime'),
			])->executeStatement();
			$incId = (int)$db->lastInsertId('tck_incident');

			// Correctives per incident — one where the target is ONLY the
			// assignee, one where they are ONLY the verifier. A single
			// combined row would let the assignee-OR-leg mask a dead
			// verifier leg (mutation-escape).
			$seeds = [['asg' => 'worker1', 'ver' => null], ['asg' => null, 'ver' => 'worker1']];
			if ($createdBy === 'worker2') {
				// Untouched control row — proves the OR scoping only hits
				// rows that actually reference the erased uid.
				$seeds[] = ['asg' => 'worker2', 'ver' => 'worker2'];
			}
			foreach ($seeds as $c) {
				$qb = $db->getQueryBuilder();
				$qb->insert('tck_corrective')->values([
					'incident_id' => $qb->createNamedParameter($incId),
					'text' => $qb->createNamedParameter('fix it'),
					'assignee_uid' => $qb->createNamedParameter($c['asg']),
					'status' => $qb->createNamedParameter('open'),
					'verified_by' => $qb->createNamedParameter($c['ver']),
					'revision' => $qb->createNamedParameter(1),
					'created_at' => $qb->createNamedParameter($now, 'datetime'),
					'updated_at' => $qb->createNamedParameter($now, 'datetime'),
				])->executeStatement();
			}
		}

		// Key held by target + one ledger movement by target.
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_key')->values([
			'site_id' => $qb->createNamedParameter($site),
			'label' => $qb->createNamedParameter('Front door'),
			'status' => $qb->createNamedParameter('out'),
			'holder_uid' => $qb->createNamedParameter('worker1'),
			'revision' => $qb->createNamedParameter(1),
			'created_at' => $qb->createNamedParameter($now, 'datetime'),
			'updated_at' => $qb->createNamedParameter($now, 'datetime'),
		])->executeStatement();
		$keyId = (int)$db->lastInsertId('tck_key');
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_key_log')->values([
			'key_id' => $qb->createNamedParameter($keyId),
			'site_id' => $qb->createNamedParameter($site),
			'action' => $qb->createNamedParameter('take'),
			'actor_uid' => $qb->createNamedParameter('worker1'),
			'note' => $qb->createNamedParameter('movement note'),
			'created_at' => $qb->createNamedParameter($now, 'datetime'),
		])->executeStatement();
		// Second movement BY another actor but recorded FOR the target.
		$qb = $db->getQueryBuilder();
		$qb->insert('tck_key_log')->values([
			'key_id' => $qb->createNamedParameter($keyId),
			'site_id' => $qb->createNamedParameter($site),
			'action' => $qb->createNamedParameter('take'),
			'actor_uid' => $qb->createNamedParameter('worker2'),
			'holder_uid' => $qb->createNamedParameter('worker1'),
			'created_at' => $qb->createNamedParameter($now, 'datetime'),
		])->executeStatement();

		$rows = $svc->eraseUid($site, 'worker1', 'tck_atlas');
		self::assertGreaterThan(0, $rows);

		// Event legs: actor row tombstoned+wiped; attester/reason/signature
		// nulled on the other.
		$q = $db->getQueryBuilder();
		$evRows = $q->select('client_event_id', 'actor_uid', 'attester_uid', 'attestation_reason',
			'signature_fileid', 'note', 'geo_lat', 'client_ts', 'tombstoned')
			->from('tck_event')->where($q->expr()->eq('site_id', $q->createNamedParameter($site)))
			->executeQuery()->fetchAll();
		foreach ($evRows as $r) {
			self::assertNull($r['attester_uid'], $r['client_event_id'] . ': attester must be nulled');
			self::assertNull($r['attestation_reason'], $r['client_event_id'] . ': attestation reason must be nulled');
			self::assertNull($r['signature_fileid'], $r['client_event_id'] . ': signature must be nulled');
			if ($r['client_event_id'] === 'e-act-' . $site) {
				self::assertSame(1, (int)$r['tombstoned']);
				self::assertNull($r['actor_uid']);
				self::assertNull($r['note']);
				self::assertNull($r['geo_lat']);
				self::assertNull($r['client_ts']);
			} else {
				// Other actor's event: not tombstoned, actor intact.
				self::assertSame(0, (int)$r['tombstoned']);
				self::assertSame('worker2', $r['actor_uid']);
			}
		}

		// Incident leg: target-authored scrubbed, other author's untouched.
		$q = $db->getQueryBuilder();
		$incs = $q->select('created_by', 'title', 'note')->from('tck_incident')
			->where($q->expr()->eq('site_id', $q->createNamedParameter($site)))
			->executeQuery()->fetchAll();
		self::assertCount(1, array_filter($incs, static fn ($r) => $r['created_by'] === null && $r['title'] === null));
		self::assertCount(1, array_filter($incs, static fn ($r) => $r['created_by'] === 'worker2'));

		// Corrective legs (assignee + verified_by) — scoped to this site's
		// incidents only. 4 rows reference worker1 (nulled); the worker2
		// control row must survive untouched.
		$q = $db->getQueryBuilder();
		$corrs = $q->select('c.assignee_uid', 'c.verified_by')
			->from('tck_corrective', 'c')
			->innerJoin('c', 'tck_incident', 'i', $q->expr()->eq('c.incident_id', 'i.id'))
			->where($q->expr()->eq('i.site_id', $q->createNamedParameter($site)))
			->executeQuery()->fetchAll();
		self::assertCount(5, $corrs, 'seeded correctives must exist');
		$worker2Rows = array_filter($corrs, static fn ($r) => $r['assignee_uid'] === 'worker2' && $r['verified_by'] === 'worker2');
		self::assertCount(1, $worker2Rows, 'unrelated assignee/verifier must survive the sweep');
		foreach ($corrs as $r) {
			self::assertNotSame('worker1', $r['assignee_uid']);
			self::assertNotSame('worker1', $r['verified_by']);
		}

		// Key + ledger legs (custodian, mover, holder, notes).
		$q = $db->getQueryBuilder();
		$key = $q->select('holder_uid')->from('tck_key')->where($q->expr()->eq('id', $q->createNamedParameter($keyId)))
			->executeQuery()->fetch();
		self::assertNull($key['holder_uid']);
		$q = $db->getQueryBuilder();
		$logs = $q->select('actor_uid', 'holder_uid', 'note')->from('tck_key_log')
			->where($q->expr()->eq('key_id', $q->createNamedParameter($keyId)))
			->executeQuery()->fetchAll();
		self::assertNull($logs[0]['actor_uid']);
		self::assertNull($logs[0]['note']);
		self::assertSame('worker2', $logs[1]['actor_uid'], "other actor's movement survives");
		self::assertNull($logs[1]['holder_uid']);

		// Plan assignee leg.
		$q = $db->getQueryBuilder();
		$plan = $q->select('assignee')->from('tck_plan')->where($q->expr()->eq('id', $q->createNamedParameter($planId)))
			->executeQuery()->fetch();
		self::assertNull($plan['assignee']);

		// Round legs.
		$q = $db->getQueryBuilder();
		$round = $q->select('started_by', 'completed_by')->from('tck_round')
			->where($q->expr()->eq('id', $q->createNamedParameter($roundId)))->executeQuery()->fetch();
		self::assertNull($round['started_by']);
		self::assertNull($round['completed_by']);

		// Position breadcrumbs hard-deleted.
		$q = $db->getQueryBuilder();
		$posCount = (int)$q->select($q->func()->count('id'))->from('tck_position')
			->where($q->expr()->eq('round_id', $q->createNamedParameter($roundId)))
			->executeQuery()->fetchOne();
		self::assertSame(0, $posCount);

		// Erasure itself is journaled (uid never in the clear — hash only).
		$q = $db->getQueryBuilder();
		$j = $q->select('body', 'payload_ref')->from('tck_journal')
			->where($q->expr()->eq('site_id', $q->createNamedParameter($site)))
			->andWhere($q->expr()->eq('kind', $q->createNamedParameter('erasure')))
			->executeQuery()->fetchAll();
		self::assertCount(1, $j);
		self::assertStringNotContainsString('worker1', (string)$j[0]['payload_ref']);
	}

	protected function tearDown(): void
	{
		if ($this->siteId > 0) {
			$db = \OC::$server->get(IDBConnection::class);
			// Child tables without site_id first (corrective via incident,
			// position via round), then site-scoped rows, then the site.
			foreach ([['tck_corrective', 'incident_id', 'tck_incident'], ['tck_position', 'round_id', 'tck_round']] as [$child, $fk, $parent]) { // teardown delete (child tables first)
				$sel = $db->getQueryBuilder();
				$sel->select('id')->from($parent)
					->where($sel->expr()->eq('site_id', $sel->createNamedParameter($this->siteId)));
				$ids = array_map('intval', $sel->executeQuery()->fetchFirstColumn());
				if ($ids !== []) {
					$del = $db->getQueryBuilder();
					$del->delete($child)->where($del->expr()->in($fk,
						$del->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
					$del->executeStatement();
				}
			}
			foreach (['tck_event', 'tck_journal', 'tck_incident', 'tck_key', 'tck_key_log', 'tck_plan', 'tck_site_member', 'tck_round'] as $t) { // teardown delete
				$qb = $db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->eq('site_id', $qb->createNamedParameter($this->siteId)));
				$qb->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('tck_site')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->siteId)));
			$qb->executeStatement();
		}
		parent::tearDown();
	}
}
