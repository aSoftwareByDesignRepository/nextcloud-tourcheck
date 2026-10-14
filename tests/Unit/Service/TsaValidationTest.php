<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Exception\DomainException;
use OCA\TourCheck\Service\TsaClient;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * MH-14/HIGH-9: the TSA token must bind OUR chain head inside the SIGNED
 * TSTInfo — a hash smuggled into unsigned CMS attributes is not evidence.
 */
class TsaValidationTest extends TestCase
{
	private const VICTIM = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
	private const OTHER  = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';
	private const OID_SHA256 = "\x60\x86\x48\x01\x65\x03\x04\x02\x01";
	private const OID_SIGNED_DATA = "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02";
	private const OID_DATA = "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x01";

	private TsaClient $tsa;

	protected function setUp(): void
	{
		$this->tsa = new TsaClient($this->createMock(IClientService::class), $this->createMock(LoggerInterface::class));
	}

	private static function tlv(string $tag, string $v): string
	{
		$n = strlen($v);
		$len = $n < 0x80 ? chr($n) : ($n < 0x100 ? "\x81" . chr($n) : "\x82" . chr($n >> 8) . chr($n & 0xff));
		return $tag . $len . $v;
	}

	/** TSTInfo ::= SEQ { v INT, policy OID, msgImprint SEQ{algoId SEQ{OID,NULL}, OCTET hash}, serial, genTime } */
	private static function tstInfo(string $hashHex, string $algoOid = self::OID_SHA256): string
	{
		$algoId = self::tlv("\x30", self::tlv("\x06", $algoOid) . "\x05\x00");
		$imprint = self::tlv("\x30", $algoId . self::tlv("\x04", hex2bin($hashHex)));
		return self::tlv("\x30",
			self::tlv("\x02", "\x01")
			. self::tlv("\x06", "\x2a\x03")                            // policy OID (dummy)
			. $imprint
			. self::tlv("\x02", "\x07")                                // serial
			. self::tlv("\x18", '20261020000000Z'));                   // genTime
	}

	/** ContentInfo { OID signedData, [0] SignedData { v, algos SET, eci { OID data, [0] OCTET tst } [, signer stub] [unsigned] } } */
	private static function token(string $tstInfo, string $extraUnsigned = ''): string
	{
		$eci = self::tlv("\x30", self::tlv("\x06", self::OID_DATA) . self::tlv("\xa0", self::tlv("\x04", $tstInfo)));
		$signerInfo = self::tlv("\x31", self::tlv("\x30", self::tlv("\x02", "\x01"))); // empty-ish signerInfo SET
		$sd = self::tlv("\x30", self::tlv("\x02", "\x03") . self::tlv("\x31", '') . $eci . $signerInfo . $extraUnsigned);
		return self::tlv("\x30", self::tlv("\x06", self::OID_SIGNED_DATA) . self::tlv("\xa0", $sd));
	}

	/** TimeStampResp ::= SEQ { PKIStatusInfo SEQ{ INT status }, token? } */
	private static function resp(string $tokenDer = '', int $status = 0): string
	{
		return self::tlv("\x30", self::tlv("\x30", self::tlv("\x02", chr($status))) . $tokenDer);
	}

	public function testBuildRequestIsWellFormedDer(): void
	{
		$req = TsaClient::buildRequest(self::VICTIM);
		self::assertSame("\x30", $req[0]);
		// Request must literally carry our imprint (SHA-256 OID + 32-byte hash).
		self::assertStringContainsString(hex2bin(self::VICTIM), $req);
		self::assertStringContainsString("\x06\x09" . self::OID_SHA256, $req);
		self::assertStringContainsString("\x01\x01\xff", $req); // certReq TRUE
	}

	public function testImprintInUnsignedAttributeIsNotAccepted(): void
	{
		// The victim hash rides in an UNauthenticated attribute ([1] IMPLICIT,
		// outside the signed content) while the signed TSTInfo binds a foreign
		// hash — the exact attack strpos-anywhere accepted.
		$unsigned = self::tlv("\xa1", self::tlv("\x30", self::tlv("\x04", hex2bin(self::VICTIM))));
		$token = self::token(self::tstInfo(self::OTHER), $unsigned);
		try {
			$this->tsa->validate(self::resp($token), self::VICTIM);
			self::fail('smuggled imprint must not validate');
		} catch (DomainException $e) {
			self::assertSame('tsa_imprint_mismatch', $e->errorCode);
		}
	}

	public function testCorrectImprintInsideSignedTstInfoReachesSignatureVerify(): void
	{
		$token = self::token(self::tstInfo(self::VICTIM));
		try {
			$this->tsa->validate(self::resp($token), self::VICTIM);
			self::fail('unsigned fake token must not validate');
		} catch (DomainException $e) {
			// Parse + imprint binding PASSED → dies at signature verify.
			self::assertSame('tsa_bad_signature', $e->errorCode);
		}
	}

	public function testForeignHashInTstInfoRejected(): void
	{
		$token = self::token(self::tstInfo(self::OTHER));
		try {
			$this->tsa->validate(self::resp($token), self::VICTIM);
			self::fail('foreign imprint must not validate');
		} catch (DomainException $e) {
			self::assertSame('tsa_imprint_mismatch', $e->errorCode);
		}
	}

	public function testNonSha256ImprintRejected(): void
	{
		$token = self::token(self::tstInfo(self::VICTIM, "\x60\x86\x48\x01\x65\x03\x04\x02\x0c")); // sha-512 oid value
		try {
			$this->tsa->validate(self::resp($token), self::VICTIM);
			self::fail('non-SHA-256 imprint must not validate');
		} catch (DomainException $e) {
			self::assertSame('tsa_imprint_algo', $e->errorCode);
		}
	}

	public function testRejectedStatus(): void
	{
		$token = self::token(self::tstInfo(self::VICTIM));
		try {
			$this->tsa->validate(self::resp($token, 2), self::VICTIM); // status=rejection
			self::fail('rejected status must fail');
		} catch (DomainException $e) {
			self::assertSame('tsa_rejected', $e->errorCode);
		}
	}

	public function testGrantedWithoutTokenRejected(): void
	{
		try {
			$this->tsa->validate(self::resp('', 0), self::VICTIM);
			self::fail('granted-but-tokenless must fail');
		} catch (DomainException $e) {
			self::assertSame('tsa_no_token', $e->errorCode);
		}
	}

	public function testTruncatedDerRejected(): void
	{
		$token = self::token(self::tstInfo(self::VICTIM));
		try {
			$this->tsa->validate(self::resp(substr($token, 0, strlen($token) - 10)), self::VICTIM);
			self::fail('truncated token must fail');
		} catch (DomainException $e) {
			self::assertStringStartsWith('tsa_', $e->errorCode);
		}
	}
}
