<?php

/**
 * Integriq ProtectedResourceMetadataInterface
 *
 * The OAuth protected resource metadata (RFC 9728) of an endpoint.
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
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OAuth;

use RuntimeException;

/**
 * The OAuth protected resource metadata (RFC 9728) of an endpoint.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
 */
interface ProtectedResourceMetadataInterface {
	/**
	 * The metadata of the endpoint with this path, or null when it is not token-protected or does not exist.
	 *
	 * @param string $endpointPath The endpoint's path.
	 *
	 * @return array<string, mixed>|null resource, authorization_servers, scopes_supported and bearer_methods_supported.
	 *
	 * @throws RuntimeException When an endpoint rule could not be read.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
	 */
	public function forPath(string $endpointPath): ?array;
}//end interface
