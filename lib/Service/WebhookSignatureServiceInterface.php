<?php

/**
 * Contract of the WebhookSignatureService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see WebhookSignatureService} that other components call.
 */
interface WebhookSignatureServiceInterface {
	/**
	 * Default inbound timestamp tolerance in seconds.
	 *
	 * @var integer
	 */
	public const DEFAULT_TOLERANCE_SECONDS = 300;

	/**
	 * Generate a new signing secret.
	 *
	 * @return string A `whsec_`-prefixed base64 secret with >= 32 bytes entropy.
	 *
	 * @throws \Exception When secure randomness is unavailable.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
	 */
	public function generateSecret(): string;

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
	public function sign(string $rawBody, string $secret, ?string $previousSecret = null, ?int $timestamp = null): string;

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
	public function verify(string $rawBody, string $headerValue, array $config): bool;

	/**
	 * Whether a rotation grace window is still active for a subscription.
	 *
	 * @param string|null $secretRotatedAt ISO 8601 rotation timestamp, or null.
	 *
	 * @return boolean True when the previous secret should still be used to sign.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-2
	 */
	public function isRotationGraceActive(?string $secretRotatedAt): bool;
}
