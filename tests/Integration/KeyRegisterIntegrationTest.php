<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Integration;

use OCA\TourCheck\Db\KeyLogMapper;
use OCA\TourCheck\Db\TckKey;
use OCA\TourCheck\Db\TckKeyMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * NH-10 key register against the real database: the take/return
 * transitions are single-statement CAS — a second take on an 'out' key
 * MUST lose (affected-rows check), and a double return likewise. Two
 * "concurrent" takers on the same key can never both end up stamped
 * as holder.
 */
final class KeyRegisterIntegrationTest extends TestCase
{
	private string $prefix;
	private array $creds;
	private array $keyIds = [];
	private array $logIds = [];
	private ?int $siteId = null;

	protected function setUp(): void
	{
		parent::setUp();
		if (!\class_exists(\OC::class) || !isset(\OC::$server)) {
			self::markTestSkipped('Nextcloud not bootstrapped');
		}
		$db = \OC::$server->get(IDBConnection::class);
		$config = \OC::$server->get(IConfig::class);
		$this->prefix = (string)$config->getSystemValue('dbtableprefix', 'oc_');
		$this->creds = [
			'host' => (string)$config->getSystemValue('dbhost', 'localhost'),
			'name' => (string)$config->getSystemValue('dbname'),
			'user' => (string)$config->getSystemValue('dbuser'),
			'pass' => (string)$config->getSystemValue('dbpassword'),
		];
	}

	protected function tearDown(): void
	{
		if (isset($this->creds['name'])) {
			$pdo = $this->pdo();
			$p = $this->prefix;
			foreach ($this->keyIds as $id) {
				$pdo->exec("DELETE FROM `{$p}tck_key` WHERE id = " . (int)$id);
			}
			foreach ($this->logIds as $id) {
				$pdo->exec("DELETE FROM `{$p}tck_key_log` WHERE id = " . (int)$id);
			}
			if ($this->siteId !== null) {
				$pdo->exec("DELETE FROM `{$p}tck_site` WHERE id = " . (int)$this->siteId);
			}
		}
		parent::tearDown();
	}

	private function pdo(): \PDO
	{
		$c = $this->creds;
		[$host, $port] = array_pad(explode(':', $c['host'], 2), 2, '3306');
		return new \PDO(
			"mysql:host={$host};port={$port};dbname={$c['name']};charset=utf8mb4",
			$c['user'],
			$c['pass'],
			[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
		);
	}

	private function seedKey(): int
	{
		$pdo = $this->pdo();
		$p = $this->prefix;
		if ($this->siteId === null) {
			$cols = $pdo->query("SHOW COLUMNS FROM `{$p}tck_site`")->fetchAll(\PDO::FETCH_COLUMN);
			$vals = [];
			foreach ($cols as $col) {
				$vals[$col] = match ($col) {
					'name' => 'KeySite-' . mt_rand(),
					'active' => '1', 'revision' => '1', 'tz' => 'Europe/Berlin',
					'live_position' => '0',
					'created_at', 'updated_at' => '2040-06-15 00:00:00',
					default => null,
				};
			}
			unset($vals['id']);
			$vals = array_filter($vals, static fn ($v) => $v !== null);
			$keys = implode(',', array_keys($vals));
			$ph = implode(',', array_fill(0, count($vals), '?'));
			$pdo->prepare("INSERT INTO `{$p}tck_site` ({$keys}) VALUES ({$ph})")->execute(array_values($vals));
			$this->siteId = (int)$pdo->lastInsertId();
		}
		$pdo->prepare("INSERT INTO `{$p}tck_key` (site_id, label, status, holder_uid, holder_member_id, revision, created_at, updated_at)
			VALUES (?, ?, 'in', NULL, NULL, 1, NOW(), NOW())")
			->execute([$this->siteId, 'Key-' . mt_rand()]);
		$id = (int)$pdo->lastInsertId();
		$this->keyIds[] = $id;
		return $id;
	}

	public function testConcurrentTakesSingleWinner(): void
	{
		$keyId = $this->seedKey();
		/** @var TckKeyMapper $mapper */
		$mapper = \OC::$server->get(TckKeyMapper::class);
		$now = new \DateTimeImmutable();

		self::assertTrue($mapper->atomicTake($keyId, 'u_a', null, $now));
		// Second "concurrent" take — same key already 'out' — must lose.
		self::assertFalse($mapper->atomicTake($keyId, 'u_b', null, $now));

		$k = $mapper->find($keyId);
		self::assertSame('out', $k->getStatus());
		self::assertSame('u_a', $k->getHolderUid());
		self::assertSame(2, $k->getRevision());
	}

	public function testConcurrentReturnsSingleWinner(): void
	{
		$keyId = $this->seedKey();
		$mapper = \OC::$server->get(TckKeyMapper::class);
		$now = new \DateTimeImmutable();

		$mapper->atomicTake($keyId, 'u_a', null, $now);
		self::assertTrue($mapper->atomicReturn($keyId, $now));
		self::assertFalse($mapper->atomicReturn($keyId, $now));

		$k = $mapper->find($keyId);
		self::assertSame('in', $k->getStatus());
		self::assertNull($k->getHolderUid());
		self::assertNull($k->getHolderMemberId());
		self::assertSame(3, $k->getRevision());
	}

	public function testTakeOnAlreadyOutKeyNeverRebindsHolder(): void
	{
		$keyId = $this->seedKey();
		$mapper = \OC::$server->get(TckKeyMapper::class);
		$now = new \DateTimeImmutable();

		$mapper->atomicTake($keyId, 'u_a', null, $now);
		// A losing take must NOT overwrite holder/revision.
		$mapper->atomicTake($keyId, 'u_b', 9, $now);

		$k = $mapper->find($keyId);
		self::assertSame('u_a', $k->getHolderUid());
		self::assertNull($k->getHolderMemberId());
	}

	public function testAppendOnlyLogInserts(): void
	{
		$keyId = $this->seedKey();
		/** @var KeyLogMapper $log */
		$log = \OC::$server->get(KeyLogMapper::class);
		$e = new \OCA\TourCheck\Db\KeyLog();
		$e->setKeyId($keyId);
		$e->setSiteId((int)$this->siteId);
		$e->setAction('take');
		$e->setActorUid('u_a');
		$e->setHolderUid('u_a');
		$e->setCreatedAt(new \DateTime());
		$inserted = $log->insert($e);
		$this->logIds[] = $inserted->getId();
		self::assertGreaterThan(0, $inserted->getId());
	}
}
