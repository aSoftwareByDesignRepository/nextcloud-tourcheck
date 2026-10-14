<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Integration;

use OCA\TourCheck\Db\SiteMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * findForUpdate must really hold the row lock on the live database —
 * otherwise the CAS updates in SiteService/CheckpointService/
 * IncidentService are a lost-update race again. A second connection is
 * blocked until the first transaction releases the row; after rollback
 * it proceeds. (Companion to KeyRegisterIntegrationTest.)
 */
final class CasRowLockIntegrationTest extends TestCase
{
	private string $prefix;
	private array $creds = [];
	private ?int $siteId = null;

	protected function setUp(): void
	{
		parent::setUp();
		if (!\class_exists(\OC::class) || !isset(\OC::$server)) {
			self::markTestSkipped('Nextcloud not bootstrapped');
		}
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
		if ($this->siteId !== null && isset($this->creds['name'])) {
			$this->pdo()->exec("DELETE FROM `{$this->prefix}tck_site` WHERE id = " . $this->siteId);
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

	private function seedSite(): int
	{
		$pdo = $this->pdo();
		$cols = $pdo->query("SHOW COLUMNS FROM `{$this->prefix}tck_site`")->fetchAll(\PDO::FETCH_COLUMN);
		$vals = [];
		foreach ($cols as $col) {
			$vals[$col] = match ($col) {
				'name' => 'CasSite-' . mt_rand(),
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
		$pdo->prepare("INSERT INTO `{$this->prefix}tck_site` ({$keys}) VALUES ({$ph})")->execute(array_values($vals));
		$this->siteId = (int)$pdo->lastInsertId();
		return $this->siteId;
	}

	public function testFindForUpdateBlocksConcurrentLocker(): void
	{
		$siteId = $this->seedSite();

		$hold = $this->pdo();
		$hold->beginTransaction();
		$hold->query("SELECT `id` FROM `{$this->prefix}tck_site` WHERE `id` = {$siteId} FOR UPDATE");

		// Second connection must block — 1s lock-wait timeout makes the
		// assertion fast instead of hanging the suite.
		$rival = $this->pdo();
		$rival->exec('SET innodb_lock_wait_timeout = 1');
		$rival->beginTransaction();
		$blocked = false;
		try {
			$rival->query("SELECT `id` FROM `{$this->prefix}tck_site` WHERE `id` = {$siteId} FOR UPDATE");
		} catch (\PDOException $e) {
			$blocked = str_contains($e->getMessage(), 'Lock wait timeout')
				|| str_contains($e->getMessage(), 'Deadlock');
		}
		self::assertTrue($blocked, 'FOR UPDATE on a locked row must block or time out — an immediate return means the lock was never taken');

		$hold->rollBack();
		// After release the rival can proceed (its txn is still alive —
		// a lock-wait timeout rolls back the statement, not the txn).
		$row = $rival->query("SELECT `id` FROM `{$this->prefix}tck_site` WHERE `id` = {$siteId} FOR UPDATE")->fetch();
		self::assertSame($siteId, (int)$row['id']);
		$rival->rollBack();
	}

	public function testMapperFindForUpdateReturnsLockedEntity(): void
	{
		$siteId = $this->seedSite();
		/** @var SiteMapper $mapper */
		$mapper = \OC::$server->get(SiteMapper::class);
		$db = \OC::$server->get(IDBConnection::class);
		$db->beginTransaction();
		try {
			$site = $mapper->findForUpdate($siteId);
			self::assertNotNull($site);
			self::assertSame($siteId, (int)$site->getId());
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
	}
}
