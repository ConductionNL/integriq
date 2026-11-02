<?php

/**
 * Contract of the EudiCredentialOfferService.
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

use OCA\Integriq\Exception\EudiIssuanceException;

/**
 * Public operations of {@see EudiCredentialOfferService} that other components call.
 */
interface EudiCredentialOfferServiceInterface {
	/**
	 * Resolve the active OpenRegister organisation id, or null when
	 * unavailable (falls back to a single default-organisation scope
	 * everywhere this value is consumed — design.md D-KEY).
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function resolveOrganisationId(): ?string;

	/**
	 * Create a credential offer (app-facing, consumer-gated).
	 *
	 * The caller (controller) MUST have already authenticated the consumer
	 * (REQ-CON-001 + authorization-jwt REQ-001) before invoking this. A
	 * missing/mismatching required field is rejected with a 400 before any
	 * row is persisted (REQ-EUDI-004).
	 *
	 * @param array $data Request body: `credentialPayload`, `format`, `subjectId`, `consumerId`,
	 *                    optional `credentialConfigurationId`, `txCode`, `callbackUrl`.
	 * @param string $consumerId The authenticated consumer's uuid (from the controller's auth gate).
	 *
	 * @return array{uuid: string, offerCode: string} The offer's uuid and its plaintext
	 *                                                pre-authorized_code (embedded by the
	 *                                                controller into offerUrl/credentialOfferUri).
	 *
	 * @throws EudiIssuanceException On a missing/invalid field (HTTP 400).
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-offer-creation-is-consumer-gated-and-app-facing-req-eudi-004
	 */
	public function createOffer(array $data, string $consumerId): array;

	/**
	 * Resolve a credential offer for wallet consumption (single-fetch,
	 * atomic consume-on-read).
	 *
	 * @param string $uuid The offer uuid.
	 *
	 * @return array|null The OpenID4VCI `credential_offer` object, or null when
	 *                    not found / expired / already consumed (REQ-EUDI-005).
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-offer-resolution-is-public-single-fetch-short-ttl-req-eudi-005
	 */
	public function resolveOfferForWallet(string $uuid): ?array;

	/**
	 * Exchange a pre-authorized_code for an access token
	 * (`urn:ietf:params:oauth:grant-type:pre-authorized_code` only).
	 *
	 * @param array $data Request body: `grant_type`, `pre-authorized_code`, optional `tx_code`.
	 *
	 * @return array{access_token: string, token_type: string, c_nonce: string, c_nonce_expires_in: int, expires_in: int}
	 *
	 * @throws EudiIssuanceException On any failure (`invalid_grant`/`invalid_request`, HTTP 400).
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-token-endpoint-issues-a-single-use-pre-authorized-code-grant-req-eudi-006
	 */
	public function exchangeToken(array $data): array;

	/**
	 * Issue a credential (Bearer + proof-of-possession).
	 *
	 * Dispatches by the session's `format`: `jwt_vc_json` returns the
	 * consuming app's already-signed payload verbatim; `dc+sd-jwt` mints
	 * and signs a fresh SD-JWT VC with the resolved organisation's active
	 * issuer key (design.md D-SIGN).
	 *
	 * @param string $bearerAccessToken The presented (plaintext) access token.
	 * @param array $data Request body: `proof` => `['jwt' => string]`.
	 *
	 * @return array{format: string, credential: string}
	 *
	 * @throws EudiIssuanceException On any failure (HTTP 400/401), never returning credential material.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-endpoint-verifies-proof-of-possession-and-dispatches-by-format-req-eudi-007
	 */
	public function issueCredential(string $bearerAccessToken, array $data): array;

	/**
	 * Revoke a credential offer's issued credential (consumer-gated,
	 * ownership-checked, idempotent).
	 *
	 * @param string $offerUuid The offer uuid to revoke.
	 * @param string $consumerId The authenticated consumer's uuid (from the controller's auth gate).
	 *
	 * @return array{alreadyRevoked: boolean}
	 *
	 * @throws EudiIssuanceException 404 when the offer does not exist, 403 when
	 *                               the authenticated consumer does not own it.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-revocation-flips-one-status-list-bit-and-fires-a-signed-callback-req-eudi-009
	 */
	public function revoke(string $offerUuid, string $consumerId): array;
}
