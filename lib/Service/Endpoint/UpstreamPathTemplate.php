<?php

/**
 * Integriq UpstreamPathTemplate.
 *
 * The upstream path template an `api` endpoint proxies to: its `upstreamPath`
 * when it names one, else its gateway route.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

/**
 * Reads the upstream path template of an endpoint.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
class UpstreamPathTemplate {

	/**
	 * The upstream path template an `api` endpoint proxies to.
	 *
	 * `endpoint` is both the gateway route (the routing fields are derived
	 * from it) and, by default, the upstream path. An endpoint whose gateway
	 * route differs from the upstream path, such as one imported from an
	 * OpenAPI document under a prefix, names the upstream path in
	 * `upstreamPath`; without it nothing changes.
	 *
	 * @param array<string,mixed> $endpointData The endpoint's object data.
	 *
	 * @return string The template.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function forEndpoint(array $endpointData): string {
		$upstream = ($endpointData['upstreamPath'] ?? null);
		if (is_string($upstream) === true && trim($upstream) !== '') {
			return $upstream;
		}

		return (string)($endpointData['endpoint'] ?? '');

	}//end forEndpoint()
}//end class
