<?php

/**
 * Integriq UpstreamRequest.
 *
 * One inbound request of an endpoint with several targets, as the router needs it.
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
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The endpoint, the method, the rendered upstream path, the parameters,
 * headers and raw body, and the resolved consumer for sticky routing.
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
final class UpstreamRequest {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $endpoint     The endpoint being served.
	 * @param string               $method       The request method.
	 * @param string               $upstreamPath The path rendered for the upstream call.
	 * @param array<string, mixed> $parameters   The query and form parameters.
	 * @param array<string, mixed> $headers      The headers forwarded upstream.
	 * @param string               $rawBody      The raw request body.
	 * @param string|null          $consumer     The resolved consumer uuid, or null.
	 */
	public function __construct(
		public readonly ObjectEntity $endpoint,
		public readonly string $method,
		public readonly string $upstreamPath,
		public readonly array $parameters = [],
		public readonly array $headers = [],
		public readonly string $rawBody = '',
		public readonly ?string $consumer = null,
	) {
	}//end __construct()
}//end class
