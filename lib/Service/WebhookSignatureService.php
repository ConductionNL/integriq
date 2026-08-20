<?php

/**
 * OpenConnector WebhookSignatureService.
 *
 * Single owner of the HMAC crypto for both outbound webhook signing and
 * inbound webhook verification, so the two implementations cannot drift.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use Psr\Log\LoggerInterface;

/**
 * HMAC-SHA256 signing and verification for outbound and inbound webhooks.
 *
 * Native scheme (`openconnector`) is Stripe-style and timestamped:
 *   X-OpenConnector-Signature: t=<unix>,v1=<hex>
 * with v1 = HMAC-SHA256(secret, "<t>." + rawBody). The timestamp inside the
 * signed string gives stateless replay protection. During rotation a second
 * v1 pair over the previous secret is appended; receivers that match ANY v1
 * pair accept.
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
 */
class WebhookSignatureService {

	/**
	 * Recognizable prefix for generated secrets (secret-scanner friendly).
	 *
	 * @var string
	 */
	public const SECRET_PREFIX = 'whsec_';

	/**
	 * Rotation grace window in seconds (24h) during which the previous secret
	 * is still used for outbound dual-signing.
	 *
	 * @var integer
	 */
	public const ROTATION_GRACE_SECONDS = 86400;

	/**
	 * Default inbound timestamp tolerance in seconds.
	 *
	 * @var integer
	 */
	public const DEFAULT_TOLERANCE_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for non-fatal scheme warnings.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Generate a new signing secret.
	 *
	 * @return string A `whsec_`-prefixed base64 secret with >= 32 bytes entropy.
	 *
	 * @throws \Exception When secure randomness is unavailable.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	public function generateSecret(): string {
		return self::SECRET_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}//end generateSecret()

	/**
	 * Build the outbound `X-OpenConnector-Signature` header value.
	 *
	 * Signs the exact body bytes. When `$previousSecret` is supplied (active
	 * rotation grace) a second `v1` pair over it is appended.
	 *
	 * @param string $rawBody The exact serialized HTTP body bytes.
	 * @param string $secret The current signing secret.
	 * @param string|null $previousSecret The previous secret during rotation grace, or null.
	 * @param integer|null $timestamp Unix timestamp to sign with (defaults to now; injectable for tests).
	 *
	 * @return string The header value (`t=<unix>,v1=<hex>[,v1=<hex>]`).
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-2
	 */
	public function sign(string $rawBody, string $secret, ?string $previousSecret = null, ?int $timestamp = null): string {
		$ts = ($timestamp ?? time());
		$signed = $ts . '.' . $rawBody;

		$pairs = [];
		$pairs[] = 'v1=' . hash_hmac('sha256', $signed, $secret);

		if ($previousSecret !== null && $previousSecret !== '') {
			$pairs[] = 'v1=' . hash_hmac('sha256', $signed, $previousSecret);
		}

		return 't=' . $ts . ',' . implode(',', $pairs);
	}//end sign()

	/**
	 * Verify an inbound signature header against the raw body.
	 *
	 * Constant-time end to end. A malformed header, missing header, stale
	 * timestamp, or bad digest all return false (the caller emits one
	 * undifferentiated 401).
	 *
	 * @param string $rawBody The raw request body bytes (pre-decode).
	 * @param string $headerValue The received signature header value.
	 * @param array $config Rule config: {scheme, secret, toleranceSeconds}.
	 *
	 * @return boolean True when the signature verifies.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	public function verify(string $rawBody, string $headerValue, array $config): bool {
		$scheme = ($config['scheme'] ?? 'openconnector');
		$secret = (string)($config['secret'] ?? '');
		$tolerance = (int)($config['toleranceSeconds'] ?? self::DEFAULT_TOLERANCE_SECONDS);

		if ($secret === '' || $headerValue === '') {
			return false;
		}

		if ($scheme === 'github') {
			// GitHub: X-Hub-Signature-256: sha256=<hex over body>. No timestamp.
			if ($tolerance !== self::DEFAULT_TOLERANCE_SECONDS) {
				$this->logger->warning(
					'webhook_signature: toleranceSeconds is ignored for scheme "github" (the scheme carries no timestamp).'
				);
			}

			return $this->verifyGithub(rawBody: $rawBody, headerValue: $headerValue, secret: $secret);
		}

		// Timestamped schemes: openconnector / stripe (t=<unix>,v1=<hex>).
		$parsed = $this->parseTimestampedHeader(headerValue: $headerValue);
		if ($parsed === null) {
			return false;
		}

		[$timestamp, $signatures] = $parsed;

		if (abs(time() - $timestamp) > $tolerance) {
			return false;
		}

		$expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
		foreach ($signatures as $candidate) {
			if (hash_equals($expected, $candidate) === true) {
				return true;
			}
		}

		return false;
	}//end verify()

	/**
	 * Verify a GitHub `sha256=<hex>` style signature over the raw body.
	 *
	 * @param string $rawBody The raw request body bytes.
	 * @param string $headerValue The received header value.
	 * @param string $secret The shared secret.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	private function verifyGithub(string $rawBody, string $headerValue, string $secret): bool {
		$value = $headerValue;
		if (str_starts_with($value, 'sha256=') === true) {
			$value = substr($value, strlen('sha256='));
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $value);
	}//end verifyGithub()

	/**
	 * Parse a timestamped `t=<unix>,v1=<hex>[,v1=<hex>]` header.
	 *
	 * @param string $headerValue The header value.
	 *
	 * @return array{0: int, 1: string[]}|null [timestamp, [v1 hex, ...]] or null when unparseable.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	private function parseTimestampedHeader(string $headerValue): ?array {
		$timestamp = null;
		$signatures = [];

		foreach (explode(',', $headerValue) as $part) {
			$part = trim($part);
			$eq = strpos($part, '=');
			if ($eq === false) {
				continue;
			}

			$key = substr($part, 0, $eq);
			$value = substr($part, ($eq + 1));

			if ($key === 't' && ctype_digit($value) === true) {
				$timestamp = (int)$value;
			} elseif ($key === 'v1' && $value !== '') {
				$signatures[] = $value;
			}
		}

		if ($timestamp === null || empty($signatures) === true) {
			return null;
		}

		return [$timestamp, $signatures];
	}//end parseTimestampedHeader()

	/**
	 * Whether a rotation grace window is still active for a subscription.
	 *
	 * @param string|null $secretRotatedAt ISO 8601 rotation timestamp, or null.
	 *
	 * @return boolean True when the previous secret should still be used to sign.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-2
	 */
	public function isRotationGraceActive(?string $secretRotatedAt): bool {
		if ($secretRotatedAt === null || $secretRotatedAt === '') {
			return false;
		}

		$rotatedTs = strtotime($secretRotatedAt);
		if ($rotatedTs === false) {
			return false;
		}

		return (time() - $rotatedTs) < self::ROTATION_GRACE_SECONDS;
	}//end isRotationGraceActive()
}//end class
