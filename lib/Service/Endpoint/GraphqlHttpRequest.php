<?php

/**
 * Integriq GraphqlHttpRequest.
 *
 * The parts of an inbound GraphQL-over-HTTP request the gateway reads: the
 * method, the query-string parameters, the body and its content type.
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
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

/**
 * One inbound GraphQL-over-HTTP request.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */
final class GraphqlHttpRequest {

	/**
	 * Constructor for GraphqlHttpRequest.
	 *
	 * @param string               $method      The HTTP method.
	 * @param array<string, mixed> $parameters  The query-string parameters.
	 * @param string               $rawBody     The request body.
	 * @param string               $contentType The request content type.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $method,
		public readonly array $parameters,
		public readonly string $rawBody,
		public readonly string $contentType,
	) {

	}//end __construct()
}//end class
