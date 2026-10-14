<?php

declare(strict_types=1);

namespace OCA\TourCheck\Db;

use OCP\IDBConnection;
use Throwable;

/**
 * Portable column/table probes for Nextcloud ConnectionAdapter (no columnExists()).
 */
final class SchemaProbe
{
	/** @var array<string, bool> */
	private static array $tableCache = [];

	/** @var array<string, bool> */
	private static array $columnCache = [];

	/** @var array<string, bool> */
	private static array $indexCache = [];

	/** @var array<int, object|null> SchemaWrapper per connection object id */
	private static array $schemaWrappers = [];

	public static function tableExists(IDBConnection $db, string $table): bool
	{
		if (array_key_exists($table, self::$tableCache)) {
			return self::$tableCache[$table];
		}
		try {
			$schema = self::schemaWrapper($db);
			if ($schema !== null) {
				self::$tableCache[$table] = (bool) $schema->hasTable($table);
				return self::$tableCache[$table];
			}
			self::$tableCache[$table] = $db->tableExists($table);
		} catch (Throwable) {
			self::$tableCache[$table] = false;
		}
		return self::$tableCache[$table];
	}

	public static function hasColumn(IDBConnection $db, string $table, string $column): bool
	{
		$key = $table . '.' . $column;
		if (array_key_exists($key, self::$columnCache)) {
			return self::$columnCache[$key];
		}
		try {
			if (method_exists($db, 'columnExists')) {
				/** @var callable $fn */
				$fn = [$db, 'columnExists'];
				self::$columnCache[$key] = (bool) $fn($table, $column);
				return self::$columnCache[$key];
			}
			$schema = self::schemaWrapper($db);
			if ($schema !== null) {
				self::$columnCache[$key] = $schema->hasTable($table)
					&& $schema->getTable($table)->hasColumn($column);
				return self::$columnCache[$key];
			}
			$qb = $db->getQueryBuilder();
			$qb->select($column)->from($table)->setMaxResults(1);
			$qb->executeQuery()->closeCursor();
			self::$columnCache[$key] = true;
		} catch (Throwable) {
			self::$columnCache[$key] = false;
		}
		return self::$columnCache[$key];
	}

	public static function hasIndex(IDBConnection $db, string $table, string $indexName): bool
	{
		$key = $table . '#' . $indexName;
		if (array_key_exists($key, self::$indexCache)) {
			return self::$indexCache[$key];
		}
		try {
			$schema = self::schemaWrapper($db);
			if ($schema === null) {
				self::$indexCache[$key] = false;
				return false;
			}
			self::$indexCache[$key] = $schema->hasTable($table)
				&& $schema->getTable($table)->hasIndex($indexName);
		} catch (Throwable) {
			self::$indexCache[$key] = false;
		}
		return self::$indexCache[$key];
	}

	/** @internal tests */
	public static function resetCache(): void
	{
		self::$tableCache = [];
		self::$columnCache = [];
		self::$indexCache = [];
		self::$schemaWrappers = [];
	}

	private static function schemaWrapper(IDBConnection $db): ?object
	{
		if (!method_exists($db, 'getInner')) {
			return null;
		}
		$oid = spl_object_id($db);
		if (array_key_exists($oid, self::$schemaWrappers)) {
			return self::$schemaWrappers[$oid];
		}
		try {
			$inner = $db->getInner();
			self::$schemaWrappers[$oid] = new \OC\DB\SchemaWrapper($inner);
		} catch (Throwable) {
			self::$schemaWrappers[$oid] = null;
		}
		return self::$schemaWrappers[$oid];
	}
}
