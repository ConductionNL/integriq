<?php

/**
 * Integriq GraphqlProxyHandler.
 *
 * The policy step of a `graphql` endpoint: before the request reaches the
 * source, read which operation it runs and refuse it when the endpoint does
 * not allow that operation, when it nests deeper than the endpoint allows, or
 * when a consumer asks for the schema while introspection is off.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use UnexpectedValueException;

/**
 * Reads and governs the GraphQL operation of a request (design D1).
 *
 * Every refusal is a GraphQL error body (`{"errors": [...]}`) so a GraphQL
 * client shows it as one, and every refusal happens before the source is
 * called. The handler holds no state and calls nothing.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */
class GraphqlProxyHandler {

	/**
	 * The endpoint target type this handler governs.
	 */
	public const TARGET_TYPE = 'graphql';

	/**
	 * A caller that introspects only where the endpoint allows it.
	 */
	public const CALLER_CLIENT = 'client';

	/**
	 * An administrator, who may always introspect.
	 */
	public const CALLER_ADMINISTRATOR = 'administrator';

	/**
	 * Constructor for GraphqlProxyHandler.
	 *
	 * @param GraphqlDocumentReader $reader Reads the operation out of the document.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GraphqlDocumentReader $reader = new GraphqlDocumentReader(),
	) {

	}//end __construct()

	/**
	 * Read the operation of a request and check it against the endpoint.
	 *
	 * @param array<string, mixed> $endpointData The endpoint: `allowedOperations`, `maxDepth`, `introspection`.
	 * @param GraphqlHttpRequest   $request      The inbound request.
	 * @param string               $caller       CALLER_CLIENT, or CALLER_ADMINISTRATOR for an administrator, who may always introspect.
	 *
	 * @return GraphqlOperation|JSONResponse The operation to forward, or the refusal.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function check(
		array $endpointData,
		GraphqlHttpRequest $request,
		string $caller=self::CALLER_CLIENT,
	): GraphqlOperation|JSONResponse {
		$operation = $this->operation(request: $request);
		if ($operation instanceof JSONResponse) {
			return $operation;
		}

		return $this->policyRefusal(endpointData: $endpointData, operation: $operation, caller: $caller) ?? $operation;

	}//end check()

	/**
	 * Read the operation a request runs, or refuse a request that cannot carry it.
	 *
	 * @param GraphqlHttpRequest $request The inbound request.
	 *
	 * @return GraphqlOperation|JSONResponse The operation, or the refusal.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	private function operation(GraphqlHttpRequest $request): GraphqlOperation|JSONResponse {
		$fields = $this->request(request: $request);
		if ($fields instanceof JSONResponse) {
			return $fields;
		}

		try {
			$operation = $this->reader->read(document: $fields['query'], operationName: $fields['operationName']);
		} catch (UnexpectedValueException $e) {
			return self::refusal(message: $e->getMessage(), code: 'GRAPHQL_PARSE_FAILED', status: Http::STATUS_BAD_REQUEST);
		}

		if (strtoupper($request->method) === 'GET' && $operation->type !== 'query') {
			return self::refusal(
				message: 'A ' . $operation->type . ' cannot be sent with GET; use POST.',
				code: 'METHOD_NOT_ALLOWED',
				status: Http::STATUS_METHOD_NOT_ALLOWED
			);
		}

		return $operation;

	}//end operation()

	/**
	 * The endpoint's refusal of an operation, or null when it may pass.
	 *
	 * An administrator's introspection skips the introspection setting and the allowlist.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 * @param GraphqlOperation     $operation    The operation.
	 * @param string               $caller       CALLER_CLIENT or CALLER_ADMINISTRATOR.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	private function policyRefusal(array $endpointData, GraphqlOperation $operation, string $caller): ?JSONResponse {
		if ($caller === self::CALLER_ADMINISTRATOR && $operation->isIntrospection() === true) {
			return $this->depthRefusal(endpointData: $endpointData, operation: $operation);
		}

		return $this->introspectionRefusal(endpointData: $endpointData, operation: $operation)
			?? $this->allowlistRefusal(endpointData: $endpointData, operation: $operation)
			?? $this->depthRefusal(endpointData: $endpointData, operation: $operation);

	}//end policyRefusal()

	/**
	 * The refusal of an introspection query the endpoint does not allow, or null.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 * @param GraphqlOperation     $operation    The operation.
	 *
	 * @return JSONResponse|null
	 */
	private function introspectionRefusal(array $endpointData, GraphqlOperation $operation): ?JSONResponse {
		if ($operation->isIntrospection() === false || ($endpointData['introspection'] ?? false) === true) {
			return null;
		}

		return self::refusal(message: 'Introspection is not allowed on this endpoint.', code: 'INTROSPECTION_NOT_ALLOWED', status: Http::STATUS_FORBIDDEN);

	}//end introspectionRefusal()

	/**
	 * The refusal of an operation the endpoint's allowlist does not name, or null.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 * @param GraphqlOperation     $operation    The operation.
	 *
	 * @return JSONResponse|null
	 */
	private function allowlistRefusal(array $endpointData, GraphqlOperation $operation): ?JSONResponse {
		$allowed = $endpointData['allowedOperations'] ?? [];
		if (is_array($allowed) === false || $allowed === [] || self::isListed(allowed: $allowed, name: $operation->name) === true) {
			return null;
		}

		return self::refusal(
			message: 'The operation "' . ($operation->name ?? 'anonymous') . '" is not allowed on this endpoint.',
			code: 'OPERATION_NOT_ALLOWED',
			status: Http::STATUS_FORBIDDEN
		);

	}//end allowlistRefusal()

	/**
	 * Whether an allowlist names this operation; an anonymous operation is never listed.
	 *
	 * @param array<mixed> $allowed The allowed operation names.
	 * @param string|null  $name    The operation name.
	 *
	 * @return bool
	 */
	private static function isListed(array $allowed, ?string $name): bool {
		return $name !== null && in_array($name, array_map('strval', $allowed), true) === true;

	}//end isListed()

	/**
	 * The refusal of an operation nested deeper than the endpoint allows, or null.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 * @param GraphqlOperation     $operation    The operation.
	 *
	 * @return JSONResponse|null
	 */
	private function depthRefusal(array $endpointData, GraphqlOperation $operation): ?JSONResponse {
		$maxDepth = $endpointData['maxDepth'] ?? null;
		if (is_int($maxDepth) === false || $maxDepth <= 0 || $operation->depth <= $maxDepth) {
			return null;
		}

		return self::refusal(
			message: 'The query nests ' . $operation->depth . ' deep; this endpoint allows ' . $maxDepth . '.',
			code: 'MAX_DEPTH_EXCEEDED',
			status: Http::STATUS_BAD_REQUEST
		);

	}//end depthRefusal()

	/**
	 * The query and operation name of a GraphQL-over-HTTP request.
	 *
	 * @param GraphqlHttpRequest $request The inbound request.
	 *
	 * @return array{query: string, operationName: string|null}|JSONResponse The request, or the refusal.
	 */
	private function request(GraphqlHttpRequest $request): array|JSONResponse {
		$method     = strtoupper($request->method);
		$parameters = $request->parameters;
		if ($method === 'GET') {
			return $this->fields(query: ($parameters['query'] ?? null), operationName: ($parameters['operationName'] ?? null));
		}

		if ($method !== 'POST') {
			return self::refusal(message: 'A GraphQL endpoint takes GET or POST.', code: 'METHOD_NOT_ALLOWED', status: Http::STATUS_METHOD_NOT_ALLOWED);
		}

		if (str_starts_with(strtolower(trim($request->contentType)), 'application/graphql') === true) {
			return $this->fields(query: $request->rawBody, operationName: ($parameters['operationName'] ?? null));
		}

		return $this->jsonFields(rawBody: $request->rawBody);

	}//end request()

	/**
	 * The query and operation name of a JSON request body.
	 *
	 * @param string $rawBody The request body.
	 *
	 * @return array{query: string, operationName: string|null}|JSONResponse The fields, or the refusal.
	 */
	private function jsonFields(string $rawBody): array|JSONResponse {
		$body = json_decode($rawBody, true);
		if (is_array($body) === false) {
			return self::refusal(message: 'The request body is not a GraphQL request.', code: 'BAD_REQUEST', status: Http::STATUS_BAD_REQUEST);
		}

		if (array_is_list($body) === true) {
			return self::refusal(message: 'Batched GraphQL requests are not accepted.', code: 'BATCH_NOT_ALLOWED', status: Http::STATUS_BAD_REQUEST);
		}

		return $this->fields(query: ($body['query'] ?? null), operationName: ($body['operationName'] ?? null));

	}//end jsonFields()

	/**
	 * Check the shape of the query and operation name.
	 *
	 * @param mixed $query         The query.
	 * @param mixed $operationName The operation name.
	 *
	 * @return array{query: string, operationName: string|null}|JSONResponse The fields, or the refusal.
	 */
	private function fields(mixed $query, mixed $operationName): array|JSONResponse {
		if (is_string($query) === false || trim($query) === '') {
			return self::refusal(message: 'The request carries no GraphQL query.', code: 'BAD_REQUEST', status: Http::STATUS_BAD_REQUEST);
		}

		if ($operationName !== null && is_string($operationName) === false) {
			return self::refusal(message: 'operationName must be a string.', code: 'BAD_REQUEST', status: Http::STATUS_BAD_REQUEST);
		}

		return ['query' => $query, 'operationName' => $operationName];

	}//end fields()

	/**
	 * A GraphQL error answer.
	 *
	 * @param string $message The message for the client.
	 * @param string $code    The machine-readable code, in `extensions.code`.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse The answer.
	 */
	private static function refusal(string $message, string $code, int $status): JSONResponse {
		return new JSONResponse(['errors' => [['message' => $message, 'extensions' => ['code' => $code]]]], $status);

	}//end refusal()
}//end class
