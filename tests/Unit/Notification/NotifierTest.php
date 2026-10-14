<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Notification;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Every subject NotificationService can emit must render — a subject the
 * Notifier cannot prepare is a notification the server silently drops.
 */
final class NotifierTest extends TestCase
{
	private IURLGenerator&MockObject $urls;
	private Notifier $notifier;

	protected function setUp(): void
	{
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, $p = []) => vsprintf($t, (array)$p));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []) => 'https://nc.test/' . $route . '/' . implode(',', $params)
		);
		$this->urls->method('imagePath')->willReturn('img/app.svg');
		$this->urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $p) => 'https://nc.test/' . $p);
		$this->notifier = new Notifier($factory, $this->urls);
	}

	private function notification(string $subject, array $params = [], string $objectType = 'site', string $objectId = '9'): INotification&MockObject
	{
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn(Constants::APP_ID);
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn($params);
		$n->method('getObjectType')->willReturn($objectType);
		$n->method('getObjectId')->willReturn($objectId);
		$n->method('setParsedSubject')->willReturnSelf();
		$n->method('setParsedMessage')->willReturnSelf();
		$n->method('setLink')->willReturnSelf();
		$n->method('setIcon')->willReturnSelf();
		return $n;
	}

	public static function allSubjects(): array
	{
		return [
			['round_missed', ['site' => 'Alpha']],
			['deadman_alert', ['site' => 'Alpha', 'minutes' => 30, 'round' => 5]],
			['anchor_failed', ['site' => 'Alpha']],
			['threshold_alert', ['site' => 'Alpha', 'detail' => 'temp=12 (min 2–8 °C)']],
			['threshold_alert', ['site' => 'Alpha']],          // detail missing → fallback text
			['token_anomaly_burst', ['site' => 'Alpha']],
			['token_invalid_burst', ['scope' => 'ip'], 'security', 'x'],
			['help_request', ['site' => 'Alpha', 'actor' => 'w1', 'signal' => 'help_request']],
			['panic_alert', ['site' => 'Alpha', 'actor' => 'w1']],
			['key_overdue', ['site' => 'Alpha', 'key' => 'Front door', 'since' => 'yesterday']],
		];
	}

	/** @dataProvider allSubjects */
	public function testEverySubjectRenders(string $subject, array $params, string $objType = 'site', string $objId = '9'): void
	{
		$n = $this->notification($subject, $params, $objType, $objId);
		$rendered = [];
		$n->method('setParsedSubject')->willReturnCallback(function ($s) use (&$rendered, $n) {
			$rendered['subject'] = $s;
			return $n;
		});
		$n->method('setParsedMessage')->willReturnCallback(function ($m) use (&$rendered, $n) {
			$rendered['message'] = $m;
			return $n;
		});
		$out = $this->notifier->prepare($n, 'en');
		self::assertNotEmpty($rendered['subject']);
		self::assertNotEmpty($rendered['message']);
		self::assertStringNotContainsString('%s', (string)$rendered['subject']);
		self::assertStringNotContainsString('%d', (string)$rendered['message']);
		self::assertStringNotContainsString('%s', (string)$rendered['message']);
		self::assertSame($n, $out);
	}

	public function testForeignAppRejected(): void
	{
		$n = $this->notification('round_missed');
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('otherapp');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}

	public function testUnknownSubjectRejected(): void
	{
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($this->notification('never_seen'), 'en');
	}
}
