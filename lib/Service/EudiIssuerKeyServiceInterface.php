<?php

/**
 * Contract of the EudiIssuerKeyService.
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

use Jose\Component\Signature\Algorithm\ES256;

/**
 * Public operations of {@see EudiIssuerKeyService} that other components call.
 */
interface EudiIssuerKeyServiceInterface {
	/**
	 * Signing algorithm for the issuer key (OpenID4VCI/EUDI convention: ES256/P-256).
	 *
	 * @var string
	 */
	public const ALGORITHM = 'ES256';

	/**
	 * Generate the first issuer signing key for an organisation.
	 *
	 * Fails loudly (thrown exception, no partial key persisted) if key
	 * generation fails. Silently replaces any existing key if called again
	 * without going through {@see rotateKey()} — callers that want rotation
	 * semantics (archiving the previous key) MUST call rotateKey() instead.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array{kid: string, publicKeyPem: string, algorithm: string} Public material only.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function generateKey(?string $organisationId = null): array;

	/**
	 * Rotate an organisation's issuer signing key.
	 *
	 * Archives the current active public key (capped at
	 * {@see MAX_ARCHIVED_KEYS}, oldest pruned first) so credentials already
	 * issued and status-list tokens already published under it remain
	 * verifiable, then generates a new active key. The rotated-out private
	 * key is discarded, never archived.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array{kid: string, publicKeyPem: string, algorithm: string} Public material only.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function rotateKey(?string $organisationId = null): array;

	/**
	 * Resolve the active signing key (including decrypted private material).
	 *
	 * Internal use only (signing) — never exposed on a controller response.
	 * Generates a fresh key on first use so callers never have to
	 * pre-provision a key before this adapter can sign anything.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array{kid: string, publicKeyPem: string, privateKeyPem: string}
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function resolveActiveKey(?string $organisationId = null): array;

	/**
	 * Resolve a public key (active or archived) by its `kid` fingerprint.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 * @param string $kid The `kid` fingerprint to resolve.
	 *
	 * @return string|null The public key PEM, or null when not found.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function resolvePublicKeyByFingerprint(?string $organisationId, string $kid): ?string;

	/**
	 * Build the publishable JWKS document for an organisation (active + archived).
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array A `{"keys": [...]}` JWKS document of public JWKs only.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-metadata-endpoint-req-eudi-003
	 */
	public function getJwks(?string $organisationId = null): array;

	/**
	 * Get the active key's public JWK only (used by the credential issuer
	 * metadata endpoint's `credential_configurations_supported` binding).
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array The active public JWK.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-metadata-endpoint-req-eudi-003
	 */
	public function getActivePublicJwk(?string $organisationId = null): array;

	/**
	 * Sign a JWT payload with the organisation's active issuer key.
	 *
	 * The SOLE code path in this adapter that touches decrypted private key
	 * material outside {@see resolveActiveKey()} itself — every credential
	 * (dc+sd-jwt) and every OAuth Status List Token this adapter mints is
	 * signed here.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 * @param array $payload The JWT payload (claims) to sign.
	 * @param array $extraHeaderClaims Additional protected header claims (e.g. `typ`).
	 *
	 * @return string The compact-serialized signed JWT.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-endpoint-verifies-proof-of-possession-and-dispatches-by-format-req-eudi-007
	 */
	public function signJwt(?string $organisationId, array $payload, array $extraHeaderClaims = []): string;
}
