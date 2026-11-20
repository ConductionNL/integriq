<?php

/**
 * Integriq GraphqlOperation.
 *
 * What a GraphQL request runs, as the gateway governs it: the operation type
 * and name, how deep it nests and which fields it asks for at the root.
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
 * The operation of one GraphQL request.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */
final class GraphqlOperation {

	/**
	 * Constructor for GraphqlOperation.
	 *
	 * @param string       $type       `query`, `mutation` or `subscription`.
	 * @param string|null  $name       The operation name; null for an anonymous one.
	 * @param int          $depth      The deepest field nesting, fragments expanded.
	 * @param list<string> $rootFields The fields asked for at the root.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $name,
		public readonly int $depth,
		public readonly array $rootFields,
	) {

	}//end __construct()

	/**
	 * Whether the operation asks for the schema itself (`__schema` or `__type` at the root).
	 *
	 * @return bool True for an introspection query.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function isIntrospection(): bool {
		return array_intersect(['__schema', '__type'], $this->rootFields) !== [];

	}//end isIntrospection()
}//end class
