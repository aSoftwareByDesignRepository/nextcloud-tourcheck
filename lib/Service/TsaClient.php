<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Exception\DomainException;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * RFC 3161 client. Builds a minimal DER TimeStampReq (SHA-256 imprint,
 * certReq=true), posts it, and VALIDATES the TimeStampResp:
 *  1. status field == granted/grantedWithMods
 *  2. TSTInfo.messageImprint hashedMessage == our hash (binds the token to
 *     THIS chain head — a captured/foreign token fails)
 *  3. CMS signature verified via openssl_cms_verify when the function and a
 *     cert store exist; otherwise imprint-match is the floor and the gap is
 *     logged loudly (never silently "verified").
 */
class TsaClient
{
	private const OID_SHA256 = "\x60\x86\x48\x01\x65\x03\x04\x02\x01";
	private const OID_SIGNED_DATA = "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02"; // 1.2.840.113549.1.7.2
	private const OID_DATA = "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x01";        // 1.2.840.113549.1.7.1
	private const MAX_RESPONSE_BYTES = 1_048_576;

	public function __construct(
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}

	private static function derLen(int $n): string
	{
		if ($n < 0x80) {
			return chr($n);
		}
		if ($n < 0x100) {
			return "\x81" . chr($n);
		}
		return "\x82" . chr($n >> 8) . chr($n & 0xff);
	}

	private static function tlv(string $tag, string $value): string
	{
		return $tag . self::derLen(strlen($value)) . $value;
	}

	public static function buildRequest(string $hashHex): string
	{
		$algoId = self::tlv("\x30", self::tlv("\x06", self::OID_SHA256) . "\x05\x00");
		$imprint = self::tlv("\x30", $algoId . self::tlv("\x04", hex2bin($hashHex) ?: ''));
		$req = self::tlv("\x02", "\x01") . $imprint . "\x01\x01\xff"; // v1 + certReq TRUE
		return self::tlv("\x30", $req);
	}

	public function request(string $tsaUrl, string $hashHex): string
	{
		if (!str_starts_with($tsaUrl, 'https://')) {
			throw new DomainException('tsa_insecure', 'TSA URL must be HTTPS.', 422);
		}
		$client = $this->clientService->newClient();
		$response = $client->post($tsaUrl, [
			'body' => self::buildRequest($hashHex),
			'headers' => ['Content-Type' => 'application/timestamp-query', 'Accept' => 'application/timestamp-reply'],
			'timeout' => 10,
		]);
		if ($response->getStatusCode() !== 200) {
			throw new DomainException('tsa_http', 'TSA responded ' . $response->getStatusCode(), 502);
		}
		$body = $response->getBody();
		if (!is_string($body) || strlen($body) < 20 || strlen($body) > self::MAX_RESPONSE_BYTES) {
			throw new DomainException('tsa_bad_response', 'TSA response malformed.', 502);
		}
		return $body;
	}

	/**
	 * Minimal DER walker — returns [tag, content-offset, content-length, next-offset].
	 * @return array{0:string,1:int,2:int,3:int}
	 */
	private static function readTlv(string $der, int $off): array
	{
		$n = strlen($der);
		if ($off + 2 > $n) {
			throw new DomainException('tsa_bad_response', 'DER truncated.', 502);
		}
		$tag = $der[$off];
		$lenByte = ord($der[$off + 1]);
		$pos = $off + 2;
		if ($lenByte === 0x80) {
			// Indefinite length is BER, never DER — reject outright.
			throw new DomainException('tsa_bad_response', 'Indefinite-length DER.', 502);
		}
		if ($lenByte & 0x80) {
			$num = $lenByte & 0x7f;
			if ($num > 4 || $pos + $num > $n) {
				throw new DomainException('tsa_bad_response', 'Bad DER length.', 502);
			}
			$len = 0;
			for ($i = 0; $i < $num; $i++) {
				$len = ($len << 8) | ord($der[$pos + $i]);
			}
			$pos += $num;
		} else {
			$len = $lenByte;
		}
		if ($len < 0 || $pos + $len > $n) {
			throw new DomainException('tsa_bad_response', 'DER length overruns buffer.', 502);
		}
		return [$tag, $pos, $len, $pos + $len];
	}

	/** @return array{0:int,1:int,2:int} [content-pos, content-len, next-off] */
	private static function expectTlv(string $der, int $off, string $tag, string $what): array
	{
		[$t, $pos, $len, $end] = self::readTlv($der, $off);
		if ($t !== $tag) {
			throw new DomainException('tsa_bad_response', 'Expected ' . $what . ' in TSA token.', 502);
		}
		return [$pos, $len, $end];
	}

	/**
	 * Extract and validate. Returns the raw token bytes on success.
	 * The imprint is verified STRUCTURALLY inside the signed TSTInfo — a hash
	 * smuggled into unsigned CMS attributes does not count (it never did).
	 * @throws DomainException on any structural, imprint, or signature failure.
	 */
	public function validate(string $responseDer, string $expectedHashHex, ?string $caBundle = null): string
	{
		// TimeStampResp ::= SEQUENCE { status PKIStatusInfo, timeStampToken }
		[$tag, $pos, $len, $end] = self::readTlv($responseDer, 0);
		if ($tag !== "\x30") {
			throw new DomainException('tsa_bad_response', 'Not a TimeStampResp.', 502);
		}
		// PKIStatusInfo ::= SEQUENCE { status INTEGER, ... }
		[$stag, $spos, $slen, $sn] = self::readTlv($responseDer, $pos);
		if ($stag !== "\x30") {
			throw new DomainException('tsa_bad_response', 'Missing PKIStatusInfo.', 502);
		}
		[$itag, $ipos, $ilen] = self::readTlv($responseDer, $spos);
		if ($itag !== "\x02" || $ilen !== 1) {
			throw new DomainException('tsa_bad_response', 'Bad status field.', 502);
		}
		$status = ord($responseDer[$ipos]);
		if ($status !== 0 && $status !== 1) { // granted | grantedWithMods
			throw new DomainException('tsa_rejected', 'TSA rejected the request (status ' . $status . ').', 502);
		}
		if ($sn >= $end) {
			throw new DomainException('tsa_no_token', 'TSA granted but returned no token.', 502);
		}
		$tokenDer = substr($responseDer, $sn, $end - $sn);

		// Imprint MUST live inside the signed TSTInfo — parsed, not strpos'd:
		// an attacker can embed the victim hash in CMS unauthenticatedAttributes
		// (outside the signature); searching the whole token accepts that lie.
		$tstInfo = self::extractTstInfo($tokenDer);
		[$algoOid, $imprint] = self::extractMessageImprint($tstInfo);
		$bin = hex2bin($expectedHashHex);
		if ($bin === false || strlen($bin) !== 32) {
			throw new DomainException('tsa_imprint_mismatch', 'Expected hash is not a SHA-256 digest.', 502);
		}
		if ($algoOid !== self::OID_SHA256) {
			throw new DomainException('tsa_imprint_algo', 'TSA token uses a different hash algorithm.', 502);
		}
		if (!hash_equals($bin, $imprint)) {
			throw new DomainException('tsa_imprint_mismatch', 'TSA token does not contain our chain head.', 502);
		}

		$this->verifyCmsSignature($tokenDer, $caBundle);
		return $tokenDer;
	}

	/**
	 * ContentInfo { OID signedData, [0] SignedData { version, digestAlgos,
	 * encapContentInfo { OID data, [0] OCTET STRING eContent } } } — returns
	 * the eContent bytes, which ARE the signed TSTInfo.
	 */
	private static function extractTstInfo(string $tokenDer): string
	{
		[$pos, , ] = self::expectTlv($tokenDer, 0, "\x30", 'ContentInfo');
		[$oidPos, $oidLen, $afterOid] = self::expectTlv($tokenDer, $pos, "\x06", 'contentType');
		if (substr($tokenDer, $oidPos, $oidLen) !== self::OID_SIGNED_DATA) {
			throw new DomainException('tsa_bad_response', 'Token is not CMS signedData.', 502);
		}
		[$sdPos, , ] = self::expectTlv($tokenDer, $afterOid, "\xa0", 'signedData [0]');
		[$seqPos, , ] = self::expectTlv($tokenDer, $sdPos, "\x30", 'SignedData');
		[, , $afterVer] = self::expectTlv($tokenDer, $seqPos, "\x02", 'SignedData.version');
		[, , $afterAlgos] = self::expectTlv($tokenDer, $afterVer, "\x31", 'digestAlgorithms');
		[$eciPos, , ] = self::expectTlv($tokenDer, $afterAlgos, "\x30", 'encapContentInfo');
		[$eoidPos, $eoidLen, $afterEoid] = self::expectTlv($tokenDer, $eciPos, "\x06", 'eContentType');
		if (substr($tokenDer, $eoidPos, $eoidLen) !== self::OID_DATA) {
			throw new DomainException('tsa_bad_response', 'eContentType is not id-data.', 502);
		}
		[$exPos, , ] = self::expectTlv($tokenDer, $afterEoid, "\xa0", 'eContent [0]');
		[$ocPos, $ocLen, ] = self::expectTlv($tokenDer, $exPos, "\x04", 'eContent OCTET STRING');
		return substr($tokenDer, $ocPos, $ocLen);
	}

	/**
	 * TSTInfo { version, policy OID, messageImprint { algoId { OID, params },
	 * OCTET STRING hashedMessage }, ... } — returns [hash-algo OID value,
	 * hashedMessage bytes].
	 * @return array{0:string,1:string}
	 */
	private static function extractMessageImprint(string $tstInfo): array
	{
		[$pos, , ] = self::expectTlv($tstInfo, 0, "\x30", 'TSTInfo');
		[, , $afterVer] = self::expectTlv($tstInfo, $pos, "\x02", 'TSTInfo.version');
		[, , $afterPolicy] = self::expectTlv($tstInfo, $afterVer, "\x06", 'policy OID');
		[$miPos, , ] = self::expectTlv($tstInfo, $afterPolicy, "\x30", 'messageImprint');
		[$aiPos, , $afterAi] = self::expectTlv($tstInfo, $miPos, "\x30", 'AlgorithmIdentifier');
		[$oidPos, $oidLen, ] = self::expectTlv($tstInfo, $aiPos, "\x06", 'hash-algo OID');
		[$hmPos, $hmLen, ] = self::expectTlv($tstInfo, $afterAi, "\x04", 'hashedMessage');
		return [substr($tstInfo, $oidPos, $oidLen), substr($tstInfo, $hmPos, $hmLen)];
	}

	private function verifyCmsSignature(string $tokenDer, ?string $caBundle): void
	{
		// Fail closed (HIGH-9): "anchored" is only honest when the signature
		// was actually verified — a missing ext or temp failure must fail the
		// anchor (retried → UNANCHORED + notify), never store a blind token.
		if (!function_exists('openssl_cms_verify')) {
			$this->logger->error('tourcheck: openssl_cms_verify unavailable — cannot verify TSA token', ['app' => 'tourcheck']);
			throw new DomainException('tsa_verify_unavailable', 'CMS verification unavailable.', 502);
		}
		$in = tempnam(sys_get_temp_dir(), 'tck_tsa_');
		if ($in === false || file_put_contents($in, $tokenDer) === false) {
			if ($in !== false) {
				@unlink($in);
			}
			throw new DomainException('tsa_verify_unavailable', 'Could not stage TSA token for verification.', 502);
		}
		try {
			$flags = OPENSSL_CMS_BINARY;
			$caInfo = [];
			if ($caBundle !== null && $caBundle !== '') {
				// Deployment-pinned TSA CA: verify the signer chain against the
				// configured bundle instead of skipping cert validation.
				$caInfo = [$caBundle];
			} else {
				// Default: signature + imprint binding only. CA-chain trust
				// stays an explicit deployment decision (TSAs often run
				// private roots) — set tsa_ca_bundle to enable it.
				$flags |= defined('OPENSSL_CMS_NO_SIGNER_CERT_VERIFY')
					? OPENSSL_CMS_NO_SIGNER_CERT_VERIFY
					: OPENSSL_CMS_NOVERIFY;
			}
			$ok = @openssl_cms_verify($in, $flags, null, $caInfo);
			if ($ok !== true) {
				$this->logger->warning('tourcheck: TSA CMS signature verification failed', ['app' => 'tourcheck']);
				throw new DomainException('tsa_bad_signature', 'TSA token signature invalid.', 502);
			}
		} finally {
			@unlink($in);
		}
	}
}
