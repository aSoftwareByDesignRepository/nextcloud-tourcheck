<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\JournalEntry;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCA\TourCheck\Support\LockGuard;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Per-site hash-chained journal. entry_hash = sha256(prev_hash_raw || canonical).
 *
 * Canonical serialization is an explicit field whitelist — never JSON
 * reserialization of a row. Integers big-endian 64-bit; strings length-prefixed
 * (u32be byte-length || UTF-8 bytes); timestamps ISO-8601 UTC 'Y-m-d\TH:i:s\Z'.
 * Golden vectors pin the format (tests/Unit/Service/ChainGoldenVectorTest).
 *
 * Concurrency: named lock `tck_chain_<siteId>` + unique (site_id, seq) backstop.
 * Lock order rule (deadlock prevention): round → chain-head. Callers that also
 * touch round rows must lock the round FIRST (documented in EventService).
 */
class ChainService
{
	private const LOCK_PREFIX = 'tourcheck/chain/';

	public function __construct(
		private readonly JournalEntryMapper $journal,
		private readonly CheckEventMapper $events,
		private readonly SettingsService $settings,
		private readonly ILockingProvider $locking,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	public static function i64be(int $v): string
	{
		return pack('N2', ($v >> 32) & 0xffffffff, $v & 0xffffffff);
	}

	public static function lp(string $s): string
	{
		return pack('N', strlen($s)) . $s;
	}

	public static function ts(\DateTimeInterface $dt): string
	{
		return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}

	/**
	 * Canonical bytes of a journal entry (fixed field order, whitelist only).
	 * @param array{seq:int,siteId:int,roundId:int,kind:string,actorUid:string,memberId:int,body:string,payloadRef:string,payloadHash:string,serverTs:string} $e
	 */
	public static function canonicalEntry(array $e): string
	{
		return self::i64be($e['seq'])
			. self::i64be($e['siteId'])
			. self::i64be($e['roundId'])
			. self::lp($e['kind'])
			. self::lp($e['actorUid'])
			. self::i64be($e['memberId'])
			. self::lp($e['body'])
			. self::lp($e['payloadRef'])
			. self::lp($e['payloadHash'])
			. self::lp($e['serverTs']);
	}

	/**
	 * Canonical payload hash of a mutable/evidence row. The whitelist per
	 * entity is fixed at write time; a tampered row hashes differently.
	 * @param array<string, int|string|null> $fields ordered whitelist map
	 */
	public static function payloadHash(array $fields): string
	{
		$buf = '';
		foreach ($fields as $name => $value) {
			$buf .= self::lp((string)$name);
			if ($value === null) {
				$buf .= self::i64be(-1);
			} elseif (is_int($value)) {
				$buf .= self::i64be($value);
			} else {
				$buf .= self::lp($value);
			}
		}
		return hash('sha256', $buf);
	}

	public static function entryHashFor(string $prevHashHex, string $canonical): string
	{
		$raw = hex2bin($prevHashHex);
		return hash('sha256', ($raw === false ? '' : $raw) . $canonical);
	}

	/**
	 * Append a journal entry. MUST run inside the caller's DB transaction when
	 * the payload row is written in the same unit of work.
	 *
	 * @param array{roundId?:?int,kind:string,actorUid?:?string,memberId?:?int,body?:?string,payloadRef?:?string,payloadFields:array<string,int|string|null>} $args
	 */
	/**
	 * Dedupe probe for lightweight journal facts (e.g. briefing_shown):
	 * has this (site, round, kind, body, actor) already been chained?
	 */
	public function existsForRound(int $siteId, int $roundId, string $kind, string $body, string $actorUid): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from('tck_journal')
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->eq('round_id', $qb->createNamedParameter($roundId)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('body', $qb->createNamedParameter($body)))
			->andWhere($qb->expr()->eq('actor_uid', $qb->createNamedParameter($actorUid)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	public function append(int $siteId, array $args): JournalEntry
	{
		$lock = self::LOCK_PREFIX . $siteId;
		// acquireLock is non-blocking — retry briefly so a burst of same-site
		// confirms serializes instead of 409ing (lock is held <10ms).
		try {
			return LockGuard::run($this->locking, $lock, fn () => $this->appendLocked($siteId, $args));
		} catch (LockedException) {
			throw new DomainException('chain_busy', 'Journal is busy. Retry.', 409);
		}
	}

	/** @param array{roundId?:?int,kind:string,actorUid?:?string,memberId?:?int,body?:?string,payloadRef?:?string,payloadFields:array<string,int|string|null>} $args */
	private function appendLocked(int $siteId, array $args): JournalEntry
	{
		$head = $this->journal->findHead($siteId);
		$seq = $head === null ? 1 : (int)$head->getSeq() + 1;
		$prev = $head === null ? $this->settings->siteChainSeed($siteId) : (string)$head->getEntryHash();
		$ts = $this->timeFactory->getDateTime();

		$fields = [
			'seq' => $seq,
			'siteId' => $siteId,
			'roundId' => (int)($args['roundId'] ?? 0),
			'kind' => (string)$args['kind'],
			'actorUid' => (string)($args['actorUid'] ?? ''),
			'memberId' => (int)($args['memberId'] ?? 0),
			'body' => (string)($args['body'] ?? ''),
			'payloadRef' => (string)($args['payloadRef'] ?? ''),
			'payloadHash' => self::payloadHash($args['payloadFields']),
			'serverTs' => self::ts($ts),
		];
		$entry = new JournalEntry();
		$entry->setSiteId($siteId);
		$entry->setSeq($seq);
		$entry->setRoundId($args['roundId'] ?? null);
		$entry->setKind((string)$args['kind']);
		$entry->setActorUid($args['actorUid'] ?? null);
		$entry->setMemberId($args['memberId'] ?? null);
		$entry->setBody($args['body'] ?? null);
		$entry->setPayloadRef($args['payloadRef'] ?? null);
		$entry->setPayloadHash($fields['payloadHash']);
		$entry->setPrevHash($prev);
		$entry->setEntryHash(self::entryHashFor($prev, self::canonicalEntry($fields)));
		$entry->setServerTs($ts);
		try {
			return $this->journal->insert($entry);
		} catch (\OCP\DB\Exception $e) {
			// Unique (site_id,seq) violation = a writer raced the lock.
			$this->logger->warning('tourcheck chain seq collision', ['siteId' => $siteId, 'seq' => $seq]);
			throw new DomainException('chain_busy', 'Journal is busy. Retry.', 409);
		}
	}

	/**
	 * Verify the whole chain for a site.
	 *
	 * Two legs: (1) the chain itself — seq contiguity, prev_hash links,
	 * entry_hash recomputation; (2) payload binding — entries referencing a
	 * write-once evidence row (`check_event:N`) recompute the row's payload
	 * hash so an UPDATE on the evidence table surfaces as TAMPERED.
	 * Tombstoned rows (erasure/retention) report TOMBSTONED — content is
	 * intentionally gone, the hole itself is the evidence.
	 *
	 * @return array{ok:bool, entries:int, head:?string, break_at_seq:?int, gap_at_seq:?int, reason:?string, payload_checked:int, tombstoned:int, verdict:string}
	 */
	public function verify(int $siteId): array
	{
		$entries = $this->journal->findAllForSite($siteId);
		$prev = $this->settings->siteChainSeed($siteId);
		$expectSeq = 1;
		$payloadChecked = 0;
		$tombstoned = 0;
		$fail = static function (int $entries, JournalEntry $row, ?int $break, ?int $gap, string $reason, int $payloadChecked, int $tombstoned): array {
			return ['ok' => false, 'entries' => $entries, 'head' => $row->getEntryHash(),
				'break_at_seq' => $break, 'gap_at_seq' => $gap, 'reason' => $reason,
				'payload_checked' => $payloadChecked, 'tombstoned' => $tombstoned, 'verdict' => 'TAMPERED'];
		};
		foreach ($entries as $row) {
			$seq = (int)$row->getSeq();
			if ($seq !== $expectSeq) {
				return $fail(count($entries), $row, null, $expectSeq, 'seq_gap', $payloadChecked, $tombstoned);
			}
			if (!hash_equals($prev, (string)$row->getPrevHash())) {
				return $fail(count($entries), $row, $seq, null, 'prev_mismatch', $payloadChecked, $tombstoned);
			}
			$fields = [
				'seq' => $seq,
				'siteId' => (int)$row->getSiteId(),
				'roundId' => (int)($row->getRoundId() ?? 0),
				'kind' => (string)$row->getKind(),
				'actorUid' => (string)($row->getActorUid() ?? ''),
				'memberId' => (int)($row->getMemberId() ?? 0),
				'body' => (string)($row->getBody() ?? ''),
				'payloadRef' => (string)($row->getPayloadRef() ?? ''),
				'payloadHash' => (string)$row->getPayloadHash(),
				'serverTs' => self::ts($row->getServerTs()),
			];
			$expect = self::entryHashFor($prev, self::canonicalEntry($fields));
			if (!hash_equals($expect, (string)$row->getEntryHash())) {
				return $fail(count($entries), $row, $seq, null, 'hash_mismatch', $payloadChecked, $tombstoned);
			}
			// Payload binding: recompute the referenced write-once row.
			$ref = (string)($row->getPayloadRef() ?? '');
			if (preg_match('/^check_event:(\d+)$/', $ref, $m) === 1) {
				$ev = $this->events->find((int)$m[1]);
				if ($ev === null) {
					return $fail(count($entries), $row, $seq, null, 'payload_missing', $payloadChecked, $tombstoned);
				}
				if ((int)$ev->getTombstoned() === 1) {
					$tombstoned++; // content nulled by erasure — integrity intact
				} else {
					$payloadChecked++;
					$actual = self::payloadHash(EventService::eventPayloadFields($ev));
					if (!hash_equals($actual, (string)$row->getPayloadHash())) {
						return $fail(count($entries), $row, $seq, null, 'payload_mismatch', $payloadChecked, $tombstoned);
					}
				}
			}
			$prev = (string)$row->getEntryHash();
			$expectSeq++;
		}
		$head = $entries === [] ? null : (string)end($entries)->getEntryHash();
		return ['ok' => true, 'entries' => count($entries), 'head' => $head,
			'break_at_seq' => null, 'gap_at_seq' => null, 'reason' => null,
			'payload_checked' => $payloadChecked, 'tombstoned' => $tombstoned,
			'verdict' => $tombstoned > 0 ? 'TOMBSTONED' : 'VERIFIED'];
	}
}
