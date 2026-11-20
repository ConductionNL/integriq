<?php

/**
 * Integriq IssuerKeyServiceInterface
 *
 * Integriq's own issuer key: the published key set and the creation of a new key.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OAuth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OAuth;

use RuntimeException;

/**
 * Integriq's own issuer key: the published key set and the creation of a new key.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 */
interface IssuerKeyServiceInterface {
	/**
	 * The published key set: the public JWK, or none before a key was created.
	 *
	 * @return array{keys: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function jwks(): array;

	/**
	 * Create (or replace) the issuer key: mint the private key into the broker, keep the public half.
	 *
	 * @param string $owner The admin who creates it; the credential's owner.
	 *
	 * @return array<string, string> The public JWK.
	 *
	 * @throws RuntimeException When no broker or organisation is available, or the key cannot be made.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function create(string $owner): array;
}//end interface
