<?php

/**
 * Integriq TokenIssuerInterface
 *
 * Grants client credentials tokens to consumers and sets their client secrets.
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

/**
 * Grants client credentials tokens to consumers and sets their client secrets.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 */
interface TokenIssuerInterface {
	/**
	 * Grant a token for the client credentials grant.
	 *
	 * @param string      $grantType    The grant type; only client_credentials.
	 * @param string      $clientId     The consumer's uuid.
	 * @param string      $clientSecret The consumer's secret.
	 * @param string|null $scope        Space-separated scopes asked for; null asks for all the consumer has.
	 * @param string|null $resource     The resource the token is for; its `aud`. Null is the issuer.
	 *
	 * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
	 *
	 * @throws OAuthError When the grant, the client or the scope is refused, or no key exists.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function issue(string $grantType, string $clientId, string $clientSecret, ?string $scope = null, ?string $resource = null): array;

	/**
	 * Set a new client secret on a client credentials consumer and answer it, once.
	 *
	 * @param string $consumerId The consumer.
	 *
	 * @return string|null The secret, or null when the consumer does not exist or is not a client credentials consumer.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function setClientSecret(string $consumerId): ?string;
}//end interface
