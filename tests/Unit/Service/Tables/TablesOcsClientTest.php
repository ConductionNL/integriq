<?php

/**
 * Unit tests for TablesOcsClient.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/tables-bridge/tasks.md#task-11-unit-tests--coercion-contract-mapping-adapter-stubbed-client
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Tables;

use OCA\Integriq\Exception\TablesNotFoundException;
use OCA\Integriq\Exception\TablesPermissionDeniedException;
use OCA\Integriq\Exception\TablesUpstreamException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Tables\TablesOcsClient;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the v1-REST Tables API client.
 *
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md
 */
class TablesOcsClientTest extends TestCase {

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var TablesOcsClient
	 */
	private TablesOcsClient $client;

	/**
	 * @var ObjectEntity
	 */
	private ObjectEntity $source;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->callService = $this->createMock(CallService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->client = new TablesOcsClient($this->callService, $this->logger);
		$this->source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');

	}//end setUp()

	/**
	 * Build a mocked CallLog ObjectEntity whose `getObject()` returns the given
	 * status code + JSON-encoded body, matching CallService::call()'s real shape.
	 *
	 * @param int $statusCode The HTTP status code to report.
	 * @param mixed $body The decoded body to JSON-encode (or null for an empty body).
	 *
	 * @return ObjectEntity|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mockCallLog(int $statusCode, mixed $body = null) {
		$callLog = $this->createMock(ObjectEntity::class);
		$callLog->method('getUuid')->willReturn('call-log-uuid-1');
		$callLog->method('getObject')->willReturn(
			[
				'response' => [
					'statusCode' => $statusCode,
					'body' => ($body === null ? '' : json_encode($body)),
				],
			]
		);

		return $callLog;
	}//end mockCallLog()

	/**
	 * A successful listTables() call normalises each Table entry.
	 *
	 * @return void
	 */
	public function testListTablesReturnsNormalisedEntries(): void {
		$this->callService->method('call')->willReturn(
			$this->mockCallLog(200, [['id' => 42, 'title' => 'Vendor Invoices', 'ownerType' => 'user']])
		);

		$result = $this->client->listTables(source: $this->source);

		$this->assertSame([['id' => 42, 'title' => 'Vendor Invoices', 'ownerType' => 'user']], $result);

	}//end testListTablesReturnsNormalisedEntries()

	/**
	 * A successful listColumns() call normalises type/subtype/constraints.
	 *
	 * @return void
	 */
	public function testListColumnsNormalisesConstraints(): void {
		$this->callService->method('call')->willReturn(
			$this->mockCallLog(
				200,
				[
					[
						'id' => 7,
						'title' => 'Amount',
						'type' => 'number',
						'subtype' => null,
						'mandatory' => true,
						'numberDecimals' => 2,
						'numberMin' => 0,
					],
				]
			)
		);

		$result = $this->client->listColumns(source: $this->source, tableId: 42);

		$this->assertCount(1, $result);
		$this->assertSame(7, $result[0]['id']);
		$this->assertSame('number', $result[0]['type']);
		$this->assertNull($result[0]['subtype']);
		$this->assertTrue($result[0]['mandatory']);
		$this->assertEqualsCanonicalizing(['numberDecimals' => 2, 'numberMin' => 0], $result[0]['constraints']);

	}//end testListColumnsNormalisesConstraints()

	/**
	 * listRows() forwards limit/offset and normalises each row's `data`.
	 *
	 * @return void
	 */
	public function testListRowsNormalisesRows(): void {
		$this->callService->expects($this->once())->method('call')
			->with(
				$this->anything(),
				$this->stringContains('/tables/42/rows'),
				'GET',
				$this->callback(static fn (array $config) => ($config['query']['limit'] ?? null) === 10 && ($config['query']['offset'] ?? null) === 0)
			)
			->willReturn($this->mockCallLog(200, [['id' => 1, 'tableId' => 42, 'data' => ['7' => '19.99']]]));

		$result = $this->client->listRows(source: $this->source, tableId: 42, viewId: null, page: 1, pageSize: 10);

		$this->assertSame([['id' => 1, 'tableId' => 42, 'data' => ['7' => '19.99']]], $result);

	}//end testListRowsNormalisesRows()

	/**
	 * createRow() sends the columnId-keyed `data` write shape.
	 *
	 * @return void
	 */
	public function testCreateRowSendsColumnIdKeyedPayload(): void {
		$this->callService->expects($this->once())->method('call')
			->with(
				$this->anything(),
				$this->stringContains('/tables/42/rows'),
				'POST',
				$this->callback(static fn (array $config) => ($config['json']['data'] ?? null) === ['7' => 19.99])
			)
			->willReturn($this->mockCallLog(200, ['id' => 5, 'tableId' => 42, 'data' => ['7' => 19.99]]));

		$result = $this->client->createRow(source: $this->source, tableId: 42, data: ['7' => 19.99]);

		$this->assertSame('5', (string)$result['id']);

	}//end testCreateRowSendsColumnIdKeyedPayload()

	/**
	 * updateRow() targets `/rows/{rowId}` via PUT.
	 *
	 * @return void
	 */
	public function testUpdateRowTargetsRowEndpoint(): void {
		$this->callService->expects($this->once())->method('call')
			->with($this->anything(), $this->stringContains('/rows/5'), 'PUT', $this->anything())
			->willReturn($this->mockCallLog(200, ['id' => 5, 'tableId' => 42, 'data' => ['7' => 20.0]]));

		$result = $this->client->updateRow(source: $this->source, rowId: 5, data: ['7' => 20.0]);

		$this->assertSame(5, $result['id']);

	}//end testUpdateRowTargetsRowEndpoint()

	/**
	 * deleteRow() issues a DELETE against `/rows/{rowId}` and returns void.
	 *
	 * @return void
	 */
	public function testDeleteRowIssuesDelete(): void {
		$this->callService->expects($this->once())->method('call')
			->with($this->anything(), $this->stringContains('/rows/5'), 'DELETE', $this->anything())
			->willReturn($this->mockCallLog(200, null));

		$this->client->deleteRow(source: $this->source, rowId: 5);

		$this->addToAssertionCount(1);

	}//end testDeleteRowIssuesDelete()

	/**
	 * A 403 response is mapped to TablesPermissionDeniedException.
	 *
	 * @return void
	 */
	public function test403IsMappedToPermissionDeniedException(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(403, ['message' => 'forbidden']));

		$this->expectException(TablesPermissionDeniedException::class);

		$this->client->updateRow(source: $this->source, rowId: 5, data: ['7' => 1]);

	}//end test403IsMappedToPermissionDeniedException()

	/**
	 * A 401 response is mapped to TablesPermissionDeniedException.
	 *
	 * @return void
	 */
	public function test401IsMappedToPermissionDeniedException(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(401, ['message' => 'unauthenticated']));

		$this->expectException(TablesPermissionDeniedException::class);

		$this->client->createRow(source: $this->source, tableId: 42, data: ['7' => 1]);

	}//end test401IsMappedToPermissionDeniedException()

	/**
	 * A 404 response is mapped to TablesNotFoundException.
	 *
	 * @return void
	 */
	public function test404IsMappedToNotFoundException(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(404, ['message' => 'not found']));

		$this->expectException(TablesNotFoundException::class);

		$this->client->getRow(source: $this->source, rowId: 999);

	}//end test404IsMappedToNotFoundException()

	/**
	 * A 500 response is mapped to TablesUpstreamException.
	 *
	 * @return void
	 */
	public function test500IsMappedToUpstreamException(): void {
		$this->callService->method('call')->willReturn($this->mockCallLog(500, ['message' => 'boom']));

		$this->expectException(TablesUpstreamException::class);

		$this->client->listTables(source: $this->source);

	}//end test500IsMappedToUpstreamException()

	/**
	 * A transport-level failure (DB persistence error surfaced by CallService)
	 * is mapped to TablesUpstreamException, never left to crash uncaught.
	 *
	 * @return void
	 */
	public function testTransportFailureIsMappedToUpstreamException(): void {
		$this->callService->method('call')->willThrowException(new \OCP\DB\Exception('connection refused'));

		$this->expectException(TablesUpstreamException::class);

		$this->client->listTables(source: $this->source);

	}//end testTransportFailureIsMappedToUpstreamException()
}//end class
