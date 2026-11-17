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
	 * @param string               $method       The HTTP method.
	 * @param array<string, mixed> $parameters   The query-string parameters.
	 * @param string               $rawBody      The request body.
	 * @param string               $contentType  The request content type.
	 * @param bool                 $isAdmin      Whether the caller is an administrator, who may always introspect.
	 *
	 * @return GraphqlOperation|JSONResponse The operation to forward, or the refusal.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function check(
		array $endpointData,
		string $method,
		array $parameters,
		string $rawBody,
		string $contentType,
		bool $isAdmin=false,
	): GraphqlOperation|JSONResponse {
		$request = $this->request(method: $method, parameters: $parameters, rawBody: $rawBody, contentType: $contentType);
		if ($request instanceof JSONResponse) {
			return $request;
		}

		try {
			$operation = $this->reader->read(document: $request['query'], operationName: $request['operationName']);
		} catch (UnexpectedValueException $e) {
			return self::refusal(message: $e->getMessage(), code: 'GRAPHQL_PARSE_FAILED', status: Http::STATUS_BAD_REQUEST);
		}

		if (strtoupper($method) === 'GET' && $operation->type !== 'query') {
			return self::refusal(
				message: 'A ' . $operation->type . ' cannot be sent with GET; use POST.',
				code: 'METHOD_NOT_ALLOWED',
				status: Http::STATUS_METHOD_NOT_ALLOWED
			);
		}

		return $this->policy(endpointData: $endpointData, operation: $operation, isAdmin: $isAdmin) ?? $operation;

	}//end check()

	/**
	 * The endpoint's refusal of an operation, or null when it may pass.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 * @param GraphqlOperation     $operation    The operation.
	 * @param bool                 $isAdmin      Whether the caller is an administrator.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function policy(array $endpointData, GraphqlOperation $operation, bool $isAdmin): ?JSONResponse {
		$introspection = $operation->isIntrospection();
		if ($introspection === true && $isAdmin === false && ($endpointData['introspection'] ?? false) !== true) {
			return self::refusal(message: 'Introspection is not allowed on this endpoint.', code: 'INTROSPECTION_NOT_ALLOWED', status: Http::STATUS_FORBIDDEN);
		}

		$allowed = $endpointData['allowedOperations'] ?? [];
		if (is_array($allowed) === true && $allowed !== [] && ($introspection === false || $isAdmin === false)) {
			if ($operation->name === null || in_array($operation->name, array_map('strval', $allowed), true) === false) {
				$name = $operation->name ?? 'anonymous';
				return self::refusal(
					message: 'The operation "' . $name . '" is not allowed on this endpoint.',
					code: 'OPERATION_NOT_ALLOWED',
					status: Http::STATUS_FORBIDDEN
				);
			}
		}

		$maxDepth = $endpointData['maxDepth'] ?? null;
		if (is_int($maxDepth) === true && $maxDepth > 0 && $operation->depth > $maxDepth) {
			return self::refusal(
				message: 'The query nests ' . $operation->depth . ' deep; this endpoint allows ' . $maxDepth . '.',
				code: 'MAX_DEPTH_EXCEEDED',
				status: Http::STATUS_BAD_REQUEST
			);
		}

		return null;

	}//end policy()

	/**
	 * The query and operation name of a GraphQL-over-HTTP request.
	 *
	 * @param string               $method      The HTTP method.
	 * @param array<string, mixed> $parameters  The query-string parameters.
	 * @param string               $rawBody     The request body.
	 * @param string               $contentType The content type.
	 *
	 * @return array{query: string, operationName: string|null}|JSONResponse The request, or the refusal.
	 */
	private function request(string $method, array $parameters, string $rawBody, string $contentType): array|JSONResponse {
		$method = strtoupper($method);
		if ($method === 'GET') {
			return $this->fields(query: ($parameters['query'] ?? null), operationName: ($parameters['operationName'] ?? null));
		}

		if ($method !== 'POST') {
			return self::refusal(message: 'A GraphQL endpoint takes GET or POST.', code: 'METHOD_NOT_ALLOWED', status: Http::STATUS_METHOD_NOT_ALLOWED);
		}

		if (str_starts_with(strtolower(trim($contentType)), 'application/graphql') === true) {
			return $this->fields(query: $rawBody, operationName: ($parameters['operationName'] ?? null));
		}

		$body = json_decode($rawBody, true);
		if (is_array($body) === false) {
			return self::refusal(message: 'The request body is not a GraphQL request.', code: 'BAD_REQUEST', status: Http::STATUS_BAD_REQUEST);
		}

		if (array_is_list($body) === true) {
			return self::refusal(message: 'Batched GraphQL requests are not accepted.', code: 'BATCH_NOT_ALLOWED', status: Http::STATUS_BAD_REQUEST);
		}

		return $this->fields(query: ($body['query'] ?? null), operationName: ($body['operationName'] ?? null));

	}//end request()

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
