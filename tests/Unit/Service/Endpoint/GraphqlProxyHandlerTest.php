<?php

/**
 * Unit tests for the policy step of a graphql endpoint.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use OCA\Integriq\Service\Endpoint\GraphqlDocumentReader;
use OCA\Integriq\Service\Endpoint\GraphqlOperation;
use OCA\Integriq\Service\Endpoint\GraphqlProxyHandler;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint allows zaakDetails and zakenLijst, nests at most 8 deep and
 * keeps introspection off.
 */
#[CoversClass(GraphqlProxyHandler::class)]
#[UsesClass(GraphqlDocumentReader::class)]
#[UsesClass(GraphqlOperation::class)]
class GraphqlProxyHandlerTest extends TestCase {

	private const ENDPOINT = ['targetType' => 'graphql', 'allowedOperations' => ['zaakDetails', 'zakenLijst'], 'maxDepth' => 8];

	/**
	 * Check a POSTed JSON GraphQL request against the endpoint.
	 *
	 * @param string               $query         The query.
	 * @param string|null          $operationName The operation name.
	 * @param array<string, mixed> $endpoint      The endpoint.
	 * @param bool                 $isAdmin       Whether an administrator asks.
	 *
	 * @return GraphqlOperation|JSONResponse
	 */
	private static function post(string $query, ?string $operationName=null, array $endpoint=self::ENDPOINT, bool $isAdmin=false): GraphqlOperation|JSONResponse {
		return (new GraphqlProxyHandler())->check(
			endpointData: $endpoint,
			method: 'POST',
			parameters: [],
			rawBody: (string)json_encode(['query' => $query, 'operationName' => $operationName, 'variables' => ['id' => '1']]),
			contentType: 'application/json',
			isAdmin: $isAdmin
		);
	}

	/**
	 * Assert a refusal with its status and code.
	 *
	 * @param mixed  $answer The answer.
	 * @param int    $status The HTTP status.
	 * @param string $code   The GraphQL error code.
	 *
	 * @return void
	 */
	private function assertRefused(mixed $answer, int $status, string $code): void {
		$this->assertInstanceOf(JSONResponse::class, $answer);
		$this->assertSame($status, $answer->getStatus());
		$this->assertSame($code, $answer->getData()['errors'][0]['extensions']['code']);
		$this->assertIsString($answer->getData()['errors'][0]['message']);
	}

	/**
	 * An allowed operation passes with what it is.
	 *
	 * @return void
	 */
	public function testAnAllowedOperationPasses(): void {
		$operation = self::post(query: 'query zaakDetails($id: ID!) { zaak(id: $id) { identificatie } }');

		$this->assertInstanceOf(GraphqlOperation::class, $operation);
		$this->assertSame('zaakDetails', $operation->name);
		$this->assertSame('query', $operation->type);
	}

	/**
	 * An unlisted mutation is a 403 GraphQL error.
	 *
	 * @return void
	 */
	public function testAnUnlistedOperationIsRefused(): void {
		$answer = self::post(query: 'mutation verwijderZaak { verwijder(id: 1) { ok } }');

		$this->assertRefused($answer, 403, 'OPERATION_NOT_ALLOWED');
		$this->assertStringContainsString('verwijderZaak', $answer->getData()['errors'][0]['message']);
	}

	/**
	 * An allowed name used for a different operation in the same document does not let the other through.
	 *
	 * @return void
	 */
	public function testTheOperationRunIsTheOneChecked(): void {
		$answer = self::post(
			query: 'query zaakDetails { zaak { id } } mutation verwijderZaak { verwijder { ok } }',
			operationName: 'verwijderZaak'
		);

		$this->assertRefused($answer, 403, 'OPERATION_NOT_ALLOWED');
	}

	/**
	 * An anonymous operation is refused while an allowlist is set.
	 *
	 * @return void
	 */
	public function testAnAnonymousOperationIsRefusedUnderAnAllowlist(): void {
		$this->assertRefused(self::post(query: '{ zaken { id } }'), 403, 'OPERATION_NOT_ALLOWED');
	}

	/**
	 * Without an allowlist any operation passes.
	 *
	 * @return void
	 */
	public function testWithoutAnAllowlistEveryOperationPasses(): void {
		$this->assertInstanceOf(GraphqlOperation::class, self::post(query: '{ zaken { id } }', endpoint: ['maxDepth' => 8]));
	}

	/**
	 * A query twelve deep is refused under a limit of eight; eight deep passes.
	 *
	 * @return void
	 */
	public function testAQueryDeeperThanTheLimitIsRefused(): void {
		$twelve = 'query zakenLijst ' . str_repeat('{ a ', 12) . str_repeat('}', 12);
		$eight  = 'query zakenLijst ' . str_repeat('{ a ', 8) . str_repeat('}', 8);

		$this->assertRefused(self::post(query: $twelve), 400, 'MAX_DEPTH_EXCEEDED');
		$this->assertInstanceOf(GraphqlOperation::class, self::post(query: $eight));
	}

	/**
	 * Depth hidden in a fragment still counts.
	 *
	 * @return void
	 */
	public function testDepthHiddenInAFragmentCounts(): void {
		$query = 'query zakenLijst { zaken { ...deep } } fragment deep on Zaak ' . str_repeat('{ a ', 9) . str_repeat('}', 9);

		$this->assertRefused(self::post(query: $query), 400, 'MAX_DEPTH_EXCEEDED');
	}

	/**
	 * A consumer may not introspect; an administrator may; the endpoint can allow it.
	 *
	 * @return void
	 */
	public function testIntrospectionIsForAdministratorsUnlessAllowed(): void {
		$query = 'query IntrospectionQuery { __schema { queryType { name } } }';

		$this->assertRefused(self::post(query: $query), 403, 'INTROSPECTION_NOT_ALLOWED');
		$this->assertInstanceOf(GraphqlOperation::class, self::post(query: $query, isAdmin: true));
		$this->assertRefused(self::post(query: $query, endpoint: ['introspection' => true, 'allowedOperations' => ['zakenLijst']]), 403, 'OPERATION_NOT_ALLOWED');
		$this->assertInstanceOf(GraphqlOperation::class, self::post(query: $query, endpoint: ['introspection' => true]));
	}

	/**
	 * A query sent with GET is read from the query string; a mutation over GET is refused.
	 *
	 * @return void
	 */
	public function testGetCarriesQueriesOnly(): void {
		$handler = new GraphqlProxyHandler();

		$query = $handler->check(endpointData: self::ENDPOINT, method: 'GET', parameters: ['query' => 'query zakenLijst { zaken { id } }'], rawBody: '', contentType: '');
		$this->assertInstanceOf(GraphqlOperation::class, $query);

		$mutation = $handler->check(endpointData: [], method: 'GET', parameters: ['query' => 'mutation m { x }'], rawBody: '', contentType: '');
		$this->assertRefused($mutation, 405, 'METHOD_NOT_ALLOWED');
	}

	/**
	 * An `application/graphql` body is the document itself.
	 *
	 * @return void
	 */
	public function testAnApplicationGraphqlBodyIsTheDocument(): void {
		$answer = (new GraphqlProxyHandler())->check(
			endpointData: self::ENDPOINT,
			method: 'POST',
			parameters: [],
			rawBody: 'query zakenLijst { zaken { id } }',
			contentType: 'application/graphql; charset=utf-8'
		);

		$this->assertInstanceOf(GraphqlOperation::class, $answer);
		$this->assertSame('zakenLijst', $answer->name);
	}

	/**
	 * Requests that are not one readable GraphQL request never reach the source.
	 *
	 * @return void
	 */
	public function testMalformedRequestsAreRefused(): void {
		$handler = new GraphqlProxyHandler();

		$this->assertRefused($handler->check(endpointData: [], method: 'POST', parameters: [], rawBody: 'not json', contentType: 'application/json'), 400, 'BAD_REQUEST');
		$this->assertRefused($handler->check(endpointData: [], method: 'POST', parameters: [], rawBody: '[{"query":"{ a }"}]', contentType: 'application/json'), 400, 'BATCH_NOT_ALLOWED');
		$this->assertRefused($handler->check(endpointData: [], method: 'POST', parameters: [], rawBody: '{"query":""}', contentType: 'application/json'), 400, 'BAD_REQUEST');
		$this->assertRefused($handler->check(endpointData: [], method: 'POST', parameters: [], rawBody: '{"query":"{ a }","operationName":5}', contentType: 'application/json'), 400, 'BAD_REQUEST');
		$this->assertRefused($handler->check(endpointData: [], method: 'PUT', parameters: [], rawBody: '{"query":"{ a }"}', contentType: 'application/json'), 405, 'METHOD_NOT_ALLOWED');
		$this->assertRefused(self::post(query: 'query zakenLijst { zaken { id }'), 400, 'GRAPHQL_PARSE_FAILED');
	}
}
