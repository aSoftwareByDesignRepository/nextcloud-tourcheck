<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Controller;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Controller\ApiJsonTrait;
use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Test\TestCase;

/** Fixture exposing the trait's protected helpers for direct testing. */
final class TraitHarness
{
	use ApiJsonTrait {
		bodyBool as public;
		strictId as public;
		revisionPrecondition as public;
		ok as public;
		fail as public;
		notFound as public;
		fromThrowable as public;
	}
	public ?IRequest $request = null;
}

final class ApiJsonTraitTest extends TestCase
{
	private TraitHarness $h;

	protected function setUp(): void
	{
		parent::setUp();
		$this->h = new TraitHarness();
		$this->h->request = $this->createMock(IRequest::class);
	}

	/** @return array<string,array{0:mixed}> */
	public static function badIds(): array
	{
		return [
			'float_string' => ['2.9'],
			'scientific' => ['2e3'],
			'padded' => [' 5'],
			'empty' => [''],
			'alpha' => ['abc'],
			'negative' => ['-3'],
			'null' => [null],
			'float' => [2.9],
			'bool' => [true],
		];
	}

	/** @dataProvider badIds */
	public function testStrictIdRejectsMalformed(mixed $raw): void
	{
		$this->expectException(ValidationException::class);
		$this->h->strictId($raw, 'site_id');
	}

	public function testStrictIdAcceptsDigits(): void
	{
		self::assertSame(42, $this->h->strictId('42', 'site_id'));
		self::assertSame(7, $this->h->strictId(7, 'site_id'));
	}

	public function testStrictIdErrorCarriesField(): void
	{
		try {
			$this->h->strictId('2.9', 'checkpoint_id');
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame(['checkpoint_id' => 'invalid'], $e->fields);
		}
	}

	public function testBodyBoolStringFalseIsNotTruthy(): void
	{
		self::assertFalse($this->h->bodyBool(['flag' => 'false'], 'flag'));
		self::assertFalse($this->h->bodyBool(['flag' => '0'], 'flag'));
		self::assertFalse($this->h->bodyBool(['flag' => 0], 'flag'));
	}

	public function testBodyBoolTruthyValues(): void
	{
		self::assertTrue($this->h->bodyBool(['flag' => '1'], 'flag'));
		self::assertTrue($this->h->bodyBool(['flag' => 'true'], 'flag'));
		self::assertTrue($this->h->bodyBool(['flag' => 1], 'flag'));
		self::assertTrue($this->h->bodyBool(['flag' => true], 'flag'));
	}

	public function testBodyBoolDefault(): void
	{
		self::assertTrue($this->h->bodyBool([], 'flag', true));
		self::assertFalse($this->h->bodyBool([], 'flag', false));
	}

	public function testOkShape(): void
	{
		$r = $this->h->ok(['id' => 5]);
		self::assertSame(Http::STATUS_OK, $r->getStatus());
		$d = $r->getData();
		self::assertTrue($d['success']);
		self::assertSame(5, $d['data']['id']);
	}

	public function testCanonicalNotFound(): void
	{
		$r = $this->h->notFound();
		self::assertSame(Http::STATUS_NOT_FOUND, $r->getStatus());
		// Assert the serialized wire form — fields must be a JSON object, not [].
		self::assertSame(
			'{"success":false,"error":"NOT_FOUND","message":"Not found.","fields":{}}',
			json_encode($r->getData(), JSON_UNESCAPED_SLASHES),
		);
	}

	public function testFromThrowableCollapsesNotFoundAndAccessDenied(): void
	{
		$a = $this->h->fromThrowable(new NotFoundException())->getData();
		$b = $this->h->fromThrowable(new DomainException(Constants::ERR_ACCESS_DENIED, 'not yours', 403))->getData();
		self::assertEquals($a, $b, 'not-found and access-denied must be wire-identical (existence oracle)');
		self::assertSame('NOT_FOUND', $a['error']);
	}

	public function testFromThrowableValidationKeepsFields(): void
	{
		$e = new ValidationException(Constants::ERR_VALIDATION, 'Bad.', ['name' => 'required']);
		$d = $this->h->fromThrowable($e)->getData();
		self::assertFalse($d['success']);
		self::assertSame('VALIDATION', $d['error']);
		self::assertSame(['name' => 'required'], $d['fields']);
	}

	public function testFromThrowableDomainUsesHttpStatus(): void
	{
		$e = new DomainException('license_required', 'pay up', 402);
		$r = $this->h->fromThrowable($e);
		self::assertSame(402, $r->getStatus());
		self::assertSame('license_required', $r->getData()['error']);
	}

	public function testFromThrowableLockedIsRetryableConflict(): void
	{
		/* A contended LockGuard (retries exhausted) must map to a retryable
		   409 — never the opaque 500 bucket (lock_contention_opaque_error). */
		$r = $this->h->fromThrowable(new \OCP\Lock\LockedException('tck-x'));
		self::assertSame(Http::STATUS_CONFLICT, $r->getStatus());
		self::assertSame(Constants::ERR_BUSY, $r->getData()['error']);
		self::assertSame('1', $r->getHeaders()['Retry-After'] ?? null);
	}

	public function testFromThrowableUnexpectedIsServerError(): void
	{
		$r = $this->h->fromThrowable(new \RuntimeException('sql exploded'));
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $r->getStatus());
		$d = $r->getData();
		self::assertSame('SERVER_ERROR', $d['error']);
		self::assertStringNotContainsString('sql', $d['message']);
		self::assertStringNotContainsString('RuntimeException', (string)json_encode($d));
	}
}
