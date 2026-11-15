<?php

/**
 * Integriq WebhookSignatureService.
 *
 * Single owner of the HMAC crypto for both outbound webhook signing and
 * inbound webhook verification, so the two implementations cannot drift.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
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
class WebhookSignatureService implements WebhookSignatureServiceInterface {

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
		$check = new SignatureCheck(rawBody: $rawBody, headerValue: $headerValue);
		$check->scheme = (string)($config['scheme'] ?? 'openconnector');
		$check->secret = (string)($config['secret'] ?? '');
		$check->tolerance = (int)($config['toleranceSeconds'] ?? self::DEFAULT_TOLERANCE_SECONDS);

		// A secret equal to the export placeholder is a public string: a
		// signature over it proves nothing, so it never verifies.
		if ($check->secret === '' || $check->secret === SensitiveFieldRegistry::PLACEHOLDER || $headerValue === '') {
			return false;
		}

		$untimestamped = $this->verifyUntimestamped(check: $check);
		if ($untimestamped !== null) {
			return $untimestamped;
		}

		return $this->verifyTimestamped(check: $check);
	}//end verify()

	/**
	 * Verify the timestamped schemes: openconnector / stripe (t=<unix>,v1=<hex>).
	 *
	 * @param SignatureCheck $check The verification.
	 *
	 * @return boolean True when a fresh timestamp carries a matching digest.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	private function verifyTimestamped(SignatureCheck $check): bool {
		$parsed = $this->parseTimestampedHeader(headerValue: $check->headerValue);
		if ($parsed === null) {
			return false;
		}

		[$timestamp, $signatures] = $parsed;

		if (abs(time() - $timestamp) > $check->tolerance) {
			return false;
		}

		$expected = hash_hmac('sha256', $timestamp . '.' . $check->rawBody, $check->secret);
		foreach ($signatures as $candidate) {
			if (hash_equals($expected, $candidate) === true) {
				return true;
			}
		}

		return false;
	}//end verifyTimestamped()

	/**
	 * Verify the schemes that carry no timestamp.
	 *
	 * `github` and `teams` both sign the raw body alone, so neither can use
	 * `toleranceSeconds`. Both ignore it with a logged warning rather than
	 * refusing the rule, because a rule refused for a setting that does not
	 * apply is a rule an administrator cannot fix by reading the error.
	 *
	 * @param SignatureCheck $check The verification.
	 *
	 * @return boolean|null The verdict, or null when the scheme is not one of these.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md#requirement-inbound-verification-reads-the-microsoft-teams-scheme-req-whs-005
	 */
	private function verifyUntimestamped(SignatureCheck $check): ?bool {
		$scheme = $check->scheme;
		if (in_array($scheme, ['github', 'teams'], true) === false) {
			return null;
		}

		if ($check->tolerance !== self::DEFAULT_TOLERANCE_SECONDS) {
			$this->logger->warning(
				'webhook_signature: toleranceSeconds is ignored for scheme "' . $scheme
				. '" (the scheme carries no timestamp).'
			);
		}

		if ($scheme === 'teams') {
			// Microsoft Teams: Authorization: HMAC <base64 over the raw body>,
			// keyed with the base64-DECODED shared secret.
			return $this->verifyTeams(check: $check);
		}

		// GitHub: X-Hub-Signature-256: sha256=<hex over body>.
		return $this->verifyGithub(check: $check);
	}//end verifyUntimestamped()

	/**
	 * Verify a GitHub `sha256=<hex>` style signature over the raw body.
	 *
	 * @param SignatureCheck $check The verification.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	private function verifyGithub(SignatureCheck $check): bool {
		$value = $check->headerValue;
		if (str_starts_with($value, 'sha256=') === true) {
			$value = substr($value, strlen('sha256='));
		}

		$expected = hash_hmac('sha256', $check->rawBody, $check->secret);
		return hash_equals($expected, $value);
	}//end verifyGithub()

	/**
	 * Verify a Microsoft Teams `HMAC <base64>` signature over the raw body.
	 *
	 * Teams signs an outgoing webhook with the base64-DECODED bytes of the
	 * shared secret, not with the secret string. Signing under the literal
	 * string produces a different digest, so a sender that gets this wrong
	 * fails verification rather than being quietly accepted.
	 *
	 * @param SignatureCheck $check The verification; its secret is base64 as Teams issues it.
	 *
	 * @return boolean True when the signature verifies.
	 *
	 * @spec openspec/specs/webhook-signing/spec.md#requirement-inbound-verification-reads-the-microsoft-teams-scheme-req-whs-005
	 */
	private function verifyTeams(SignatureCheck $check): bool {
		$value = trim($check->headerValue);
		if (str_starts_with($value, 'HMAC ') === false) {
			return false;
		}

		$value = trim(substr($value, strlen('HMAC ')));
		if ($value === '') {
			return false;
		}

		$key = base64_decode($check->secret, true);
		if ($key === false || $key === '') {
			return false;
		}

		$expected = base64_encode(hash_hmac('sha256', $check->rawBody, $key, true));
		return hash_equals($expected, $value);
	}//end verifyTeams()

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
		$pairs = $this->headerPairs(headerValue: $headerValue);
		$timestamp = $this->headerTimestamp(pairs: $pairs);
		$signatures = $this->headerSignatures(pairs: $pairs);

		if ($timestamp === null || empty($signatures) === true) {
			return null;
		}

		return [$timestamp, $signatures];
	}//end parseTimestampedHeader()

	/**
	 * Split a comma-separated header into its `key=value` pairs, skipping parts without `=`.
	 *
	 * @param string $headerValue The header value.
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs, in order.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	private function headerPairs(string $headerValue): array {
		$pairs = [];
		foreach (explode(',', $headerValue) as $part) {
			$part = trim($part);
			$eq = strpos($part, '=');
			if ($eq === false) {
				continue;
			}

			$pairs[] = [substr($part, 0, $eq), substr($part, ($eq + 1))];
		}

		return $pairs;
	}//end headerPairs()

	/**
	 * The header's timestamp: the last all-digit `t` value.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs The header pairs.
	 *
	 * @return integer|null The timestamp, or null when there is none.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	private function headerTimestamp(array $pairs): ?int {
		$timestamp = null;
		foreach ($pairs as [$key, $value]) {
			if ($key === 't' && ctype_digit($value) === true) {
				$timestamp = (int)$value;
			}
		}

		return $timestamp;
	}//end headerTimestamp()

	/**
	 * The header's non-empty `v1` signatures, in order.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs The header pairs.
	 *
	 * @return string[] The signatures.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	private function headerSignatures(array $pairs): array {
		$signatures = [];
		foreach ($pairs as [$key, $value]) {
			if ($key === 'v1' && $value !== '') {
				$signatures[] = $value;
			}
		}

		return $signatures;
	}//end headerSignatures()

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
