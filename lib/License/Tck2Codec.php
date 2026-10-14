<?php

declare(strict_types=1);

namespace OCA\TourCheck\License;

use OCA\TourCheck\Config\VendorPublicKey;

/**
 * TCK2 wire format: canonical JSON bytes, base64url, Ed25519 verify.
 *
 * Canonical key order: v, product, customerId, issuedAt, validUntil,
 * mobileSeats, terminalDevices (station devices), bundle (only when true).
 * product must be "tourcheck". No instanceId on the wire.
 */
final class Tck2Codec
{
	public const FORMAT = 'TCK2';
	public const VERSION = 2;
	public const PRODUCT = 'tourcheck';

	public const ERROR_INVALID_FORMAT = 'INVALID_FORMAT';
	public const ERROR_INVALID_SIGNATURE = 'INVALID_SIGNATURE';
	public const ERROR_EXPIRED = 'EXPIRED';
	public const ERROR_NO_PRODUCTS = 'NO_PRODUCTS';
	public const ERROR_INVALID_PAYLOAD = 'INVALID_PAYLOAD';

	public static function normalizeWireKey(string $wireKey): string
	{
		return preg_replace('/\s+/u', '', $wireKey) ?? trim($wireKey);
	}

	/**
	 * @return array{payload: array<string, mixed>, payloadBytes: string, payloadB64: string, signatureB64: string}|null
	 */
	public static function parseAndVerify(string $wireKey): ?array
	{
		$wireKey = self::normalizeWireKey($wireKey);
		$parts = explode('.', $wireKey);
		if (count($parts) !== 3 || $parts[0] !== self::FORMAT) {
			return null;
		}

		$payloadBytes = VendorPublicKey::base64urlDecode($parts[1]);
		$signature = VendorPublicKey::base64urlDecode($parts[2]);
		if ($payloadBytes === false || $signature === false) {
			return null;
		}
		if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			return null;
		}

		$publicKey = VendorPublicKey::bytes();
		if (!sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
			return null;
		}

		try {
			/** @var array<string, mixed>|null $payload */
			$payload = json_decode($payloadBytes, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($payload)) {
			return null;
		}

		if (!self::validatePayloadFields($payload)) {
			return null;
		}

		if (!hash_equals(self::canonicalJson($payload), $payloadBytes)) {
			return null;
		}

		return [
			'payload' => $payload,
			'payloadBytes' => $payloadBytes,
			'payloadB64' => $parts[1],
			'signatureB64' => $parts[2],
		];
	}

	/** @param array<string, mixed> $payload */
	public static function validatePayloadFields(array $payload): bool
	{
		if (($payload['v'] ?? null) !== self::VERSION) {
			return false;
		}
		if (($payload['product'] ?? null) !== self::PRODUCT) {
			return false;
		}
		$customerId = $payload['customerId'] ?? '';
		if (!is_string($customerId) || !preg_match('/^[a-z0-9-]{3,64}$/', $customerId)) {
			return false;
		}
		foreach (['issuedAt', 'validUntil'] as $dateField) {
			$val = $payload[$dateField] ?? '';
			if (!is_string($val) || !self::isValidYmd($val)) {
				return false;
			}
		}
		if ($payload['validUntil'] < $payload['issuedAt']) {
			return false;
		}
		$mobile = $payload['mobileSeats'] ?? -1;
		$terminal = $payload['terminalDevices'] ?? -1;
		if (!is_int($mobile) || !is_int($terminal)) {
			return false;
		}
		if ($mobile < 0 || $mobile > 10000 || $terminal < 0 || $terminal > 1000) {
			return false;
		}
		if ($mobile + $terminal <= 0) {
			return false;
		}
		if (array_key_exists('bundle', $payload) && $payload['bundle'] !== true) {
			return false;
		}
		if (array_key_exists('instanceId', $payload)) {
			return false;
		}
		return true;
	}

	/** @param array<string, mixed> $payload */
	public static function isExpired(array $payload, ?\DateTimeImmutable $today = null): bool
	{
		$today ??= new \DateTimeImmutable('today');
		$until = \DateTimeImmutable::createFromFormat('Y-m-d', (string)$payload['validUntil']);
		if ($until === false) {
			return true;
		}
		return $until < $today;
	}

	/** @param array<string, mixed> $payload */
	public static function canonicalJson(array $payload): string
	{
		$ordered = [
			'v' => (int)$payload['v'],
			'product' => (string)$payload['product'],
			'customerId' => (string)$payload['customerId'],
			'issuedAt' => (string)$payload['issuedAt'],
			'validUntil' => (string)$payload['validUntil'],
			'mobileSeats' => (int)$payload['mobileSeats'],
			'terminalDevices' => (int)$payload['terminalDevices'],
		];
		if (($payload['bundle'] ?? false) === true) {
			$ordered['bundle'] = true;
		}
		$json = json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new \RuntimeException('TCK2 canonical JSON encode failed.');
		}
		return $json;
	}

	public static function classifyApplyError(string $wireKey): string
	{
		$wireKey = self::normalizeWireKey($wireKey);
		$parts = explode('.', $wireKey);
		if (count($parts) !== 3 || $parts[0] !== self::FORMAT) {
			return self::ERROR_INVALID_FORMAT;
		}

		$payloadBytes = VendorPublicKey::base64urlDecode($parts[1]);
		$signature = VendorPublicKey::base64urlDecode($parts[2]);
		if ($payloadBytes === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			return self::ERROR_INVALID_FORMAT;
		}

		$publicKey = VendorPublicKey::bytes();
		if (!sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
			return self::ERROR_INVALID_SIGNATURE;
		}

		try {
			/** @var array<string, mixed>|null $payload */
			$payload = json_decode($payloadBytes, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return self::ERROR_INVALID_PAYLOAD;
		}

		if (!self::validatePayloadFields($payload)) {
			$mobile = (int)($payload['mobileSeats'] ?? 0);
			$terminal = (int)($payload['terminalDevices'] ?? 0);
			$productOk = ($payload['product'] ?? null) === self::PRODUCT;
			if ($productOk && $mobile + $terminal <= 0) {
				return self::ERROR_NO_PRODUCTS;
			}
			return self::ERROR_INVALID_PAYLOAD;
		}

		if (!hash_equals(self::canonicalJson($payload), $payloadBytes)) {
			return self::ERROR_INVALID_PAYLOAD;
		}

		if (self::isExpired($payload)) {
			return self::ERROR_EXPIRED;
		}

		return '';
	}

	private static function isValidYmd(string $value): bool
	{
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
		return $dt !== false && $dt->format('Y-m-d') === $value;
	}
}
