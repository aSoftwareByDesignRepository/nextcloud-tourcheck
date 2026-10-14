<?php

declare(strict_types=1);

/**
 * Host unit runs: OCP stubs only (no Nextcloud DB).
 * Docker integration: load lib/base.php when under /var/www/html or TOURCHECK_INTEGRATION=1.
 */

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}
if (!defined('PHPUNIT_RUNNING')) {
	define('PHPUNIT_RUNNING', true);
}

$inDockerTree = str_starts_with(__DIR__, '/var/www/html/');
$loadNc = $inDockerTree || getenv('TOURCHECK_INTEGRATION') === '1';

$base = null;
if ($loadNc) {
	$candidates = [];
	$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '';
	if ($nextcloudRoot !== '') {
		$candidates[] = rtrim($nextcloudRoot, '/\\') . '/lib/base.php';
	}
	$candidates[] = __DIR__ . '/../../../lib/base.php';
	$candidates[] = '/var/www/html/lib/base.php';
	foreach ($candidates as $candidate) {
		if (is_file($candidate)) {
			$base = $candidate;
			break;
		}
	}
}

if ($base !== null) {
	require_once $base;
	$integrationBootstrap = dirname(__DIR__, 3) . '/scripts/phpunit-integration-bootstrap.php';
	if (is_file($integrationBootstrap)) {
		require_once $integrationBootstrap;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\Test\TestCase::class)) {
	$shim = __DIR__ . '/shim/TestCase.php';
	if (is_file($shim)) {
		require_once $shim;
	}
}
if (!class_exists(\Test\TestCase::class)) {
	eval('namespace Test; class TestCase extends \\PHPUnit\\Framework\\TestCase {}');
}

if (!class_exists(\Symfony\Component\Console\Command\Command::class, false)) {
	eval('namespace Symfony\\Component\\Console\\Command; class Command {}');
}

if ($base === null && !interface_exists(\OC\Hooks\Emitter::class, false)) {
	eval('namespace OC\\Hooks; interface Emitter {}');
}

$ocpStubs = dirname(__DIR__, 3) . '/scripts/phpunit-ocp-doctrine-stubs.php';
if ($base === null && is_file($ocpStubs)) {
	require_once $ocpStubs;
} elseif ($base === null) {
if (!class_exists(\Doctrine\DBAL\ParameterType::class)) {
	eval('namespace Doctrine\\DBAL; final class ParameterType { public const NULL = 0; public const INTEGER = 1; public const STRING = 2; public const LARGE_OBJECT = 3; }');
}
if (!class_exists(\Doctrine\DBAL\ArrayParameterType::class)) {
	eval('namespace Doctrine\\DBAL; final class ArrayParameterType { public const INTEGER = 1; public const STRING = 2; public const ASCII = 3; public const BINARY = 4; }');
}
if (!class_exists(\Doctrine\DBAL\Connection::class)) {
	eval('namespace Doctrine\\DBAL; class Connection {}');
}
if (!class_exists(\Doctrine\DBAL\Types\Types::class)) {
	eval("namespace Doctrine\\DBAL\\Types; final class Types { public const BOOLEAN = 'boolean'; public const DATETIME_MUTABLE = 'datetime'; public const TIME_MUTABLE = 'time'; public const DATE_MUTABLE = 'date'; public const DATE_IMMUTABLE = 'date_immutable'; public const DATETIME_IMMUTABLE = 'datetime_immutable'; public const DATETIMETZ_MUTABLE = 'datetimetz'; public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable'; public const BIGINT = 'bigint'; public const BINARY = 'binary'; public const BLOB = 'blob'; public const DATEINTERVAL = 'dateinterval'; public const DECIMAL = 'decimal'; public const FLOAT = 'float'; public const GUID = 'guid'; public const JSON = 'json'; public const SIMPLE_ARRAY = 'simple_array'; public const SMALLFLOAT = 'smallfloat'; public const SMALLINT = 'smallint'; public const STRING = 'string'; public const TEXT = 'text'; }");
}
if (!class_exists(\Doctrine\DBAL\Query\Expression\ExpressionBuilder::class)) {
	eval('namespace Doctrine\\DBAL\\Query\\Expression; final class ExpressionBuilder { public const EQ = \'=\'; public const NEQ = \'<>\'; public const LT = \'<\'; public const LTE = \'<=\'; public const GT = \'>\'; public const GTE = \'>=\'; }');
}
}
