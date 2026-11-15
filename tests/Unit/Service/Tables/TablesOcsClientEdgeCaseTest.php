<?php

/**
 * Edge-case unit tests for TablesOcsClient: view-scoped and paged row
 * listing, single-row reads, malformed list entries and column subtypes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Tables;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\OcsApi;
use OCA\Integriq\Service\Helper\OcsApiDispatcher;
use OCA\Integriq\Service\Helper\OcsPayload;
use OCA\Integriq\Service\Helper\OcsRequest;
use OCA\Integriq\Service\Tables\TablesOcsClient;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the request shapes and normalisation branches the main client test leaves out.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
 */
#[CoversClass(TablesOcsClient::class)]
#[UsesClass(OcsApi::class)]
#[UsesClass(OcsApiDispatcher::class)]
#[UsesClass(OcsPayload::class)]
#[UsesClass(OcsRequest::class)]
class TablesOcsClientEdgeCaseTest extends TestCase {

	/**
	 * @var CallService&MockObject
	 */
	private $callService;

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
		$this->client = new TablesOcsClient($this->callService, $this->createMock(LoggerInterface::class));
		$this->source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');

	}//end setUp()

	/**
	 * A call log answering 200 with the given decoded body.
	 *
	 * @param mixed $body The body to JSON-encode.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function okCallLog(mixed $body) {
		$callLog = $this->createMock(ObjectEntity::class);
		$callLog->method('getUuid')->willReturn('call-log-uuid-1');
		$callLog->method('getObject')->willReturn(['response' => ['statusCode' => 200, 'body' => json_encode($body)]]);

		return $callLog;

	}//end okCallLog()

	/**
	 * Non-array entries in the table list are skipped.
	 *
	 * @return void
	 */
	public function testListTablesSkipsNonArrayEntries(): void {
		$this->callService->expects($this->once())->method('call')
			->with($this->source, '/index.php/apps/tables/api/1/tables', 'GET', [])
			->willReturn($this->okCallLog(['junk', 5, ['id' => '3', 'title' => 'Leads', 'ownerType' => 'group']]));

		$this->assertSame([['id' => 3, 'title' => 'Leads', 'ownerType' => 'group']], $this->client->listTables(source: $this->source));

	}//end testListTablesSkipsNonArrayEntries()

	/**
	 * Columns keep a string subtype, default missing fields and skip non-array entries.
	 *
	 * @return void
	 */
	public function testListColumnsKeepsSubtypeAndDefaultsMissingFields(): void {
		$this->callService->expects($this->once())->method('call')
			->with($this->source, $this->stringEndsWith('/tables/42/columns'), 'GET', [])
			->willReturn(
				$this->okCallLog(
					[
						null,
						['id' => 9, 'title' => 'Due', 'type' => 'datetime', 'subtype' => 'date', 'datetimeDefault' => 'today', 'unknownKey' => 'x'],
						[],
					]
				)
			);

		$this->assertSame(
			[
				['id' => 9, 'title' => 'Due', 'type' => 'datetime', 'subtype' => 'date', 'mandatory' => false, 'constraints' => ['datetimeDefault' => 'today']],
				['id' => 0, 'title' => '', 'type' => '', 'subtype' => null, 'mandatory' => false, 'constraints' => []],
			],
			$this->client->listColumns(source: $this->source, tableId: 42)
		);

	}//end testListColumnsKeepsSubtypeAndDefaultsMissingFields()

	/**
	 * With a view id the rows come from the view endpoint, offset by page.
	 *
	 * @return void
	 */
	public function testListRowsWithViewUsesViewEndpointAndPageOffset(): void {
		$this->callService->expects($this->once())->method('call')
			->with(
				$this->source,
				$this->stringEndsWith('/views/9/rows'),
				'GET',
				['query' => ['limit' => 25, 'offset' => 50]]
			)
			->willReturn($this->okCallLog(['skip-me', ['id' => 4, 'tableId' => 42, 'data' => 'not-an-array']]));

		$this->assertSame(
			[['id' => 4, 'tableId' => 42, 'data' => []]],
			$this->client->listRows(source: $this->source, tableId: 42, viewId: 9, page: 3, pageSize: 25)
		);

	}//end testListRowsWithViewUsesViewEndpointAndPageOffset()

	/**
	 * getRow() reads the row endpoint and normalises the row.
	 *
	 * @return void
	 */
	public function testGetRowReadsRowEndpoint(): void {
		$this->callService->expects($this->once())->method('call')
			->with($this->source, '/index.php/apps/tables/api/1/rows/5', 'GET', [])
			->willReturn($this->okCallLog(['id' => '5', 'tableId' => '42', 'data' => ['7' => 'x']]));

		$this->assertSame(['id' => 5, 'tableId' => 42, 'data' => ['7' => 'x']], $this->client->getRow(source: $this->source, rowId: 5));

	}//end testGetRowReadsRowEndpoint()

	/**
	 * A non-object body for a single row normalises to an empty row.
	 *
	 * @return void
	 */
	public function testGetRowWithNonObjectBodyNormalisesToEmptyRow(): void {
		$this->callService->method('call')->willReturn($this->okCallLog('nope'));

		$this->assertSame(['id' => 0, 'tableId' => 0, 'data' => []], $this->client->getRow(source: $this->source, rowId: 5));

	}//end testGetRowWithNonObjectBodyNormalisesToEmptyRow()
}//end class
