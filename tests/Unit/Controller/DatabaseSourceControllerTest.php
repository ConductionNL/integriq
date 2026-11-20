<?php

/**
 * The source page's database panel endpoints.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DatabaseSourceController;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseSourceException;
use OCA\Integriq\Service\Adapter\DataInfra\DatabaseAdapter;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(DatabaseSourceController::class)]
class DatabaseSourceControllerTest extends TestCase {

	/**
	 * The adapter double.
	 *
	 * @var DatabaseAdapter&MockObject
	 */
	private $adapter;

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * Build the controller over one stored source, or none.
	 *
	 * @param array<string,mixed>|null $source The stored source.
	 *
	 * @return DatabaseSourceController
	 */
	private function controller(?array $source): DatabaseSourceController {
		$entity = null;
		if ($source !== null) {
			$entity = new ObjectEntity();
			$entity->setUuid('src-1');
			$entity->setObject($source);
		}

		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('find')->willReturn($entity);

		$this->adapter = $this->createMock(DatabaseAdapter::class);
		$this->request = $this->createMock(IRequest::class);

		return new DatabaseSourceController(
			appName: 'integriq',
			request: $this->request,
			objectService: $objects,
			adapter: $this->adapter,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A test run shows the first page, whether more follow, and the duration.
	 *
	 * @return void
	 */
	public function testATestRunShowsTheFirstPageAndItsDuration(): void {
		$source = ['type' => 'database', 'name' => 'Zaken'];
		$controller = $this->controller(source: $source);
		$this->request->method('getParam')->with('parameters', [])->willReturn(['status' => 'open']);
		$rows = array_map(static fn (int $id): array => ['id' => $id], range(1, 50));
		$this->adapter->expects($this->once())->method('read')
			->with($source, 'zaken-sinds', ['status' => 'open'], null, 50)
			->willReturn(['rows' => $rows, 'next' => 50, 'pageSize' => 50, 'durationMs' => 12]);

		$response = $controller->testStatement(id: 'src-1', name: 'zaken-sinds');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['statement' => 'zaken-sinds', 'rows' => $rows, 'rowCount' => 50, 'more' => true, 'durationMs' => 12], $response->getData());
	}//end testATestRunShowsTheFirstPageAndItsDuration()

	/**
	 * A short first page says no more rows follow.
	 *
	 * @return void
	 */
	public function testAShortPageSaysNoMoreFollow(): void {
		$controller = $this->controller(source: ['type' => 'database']);
		$this->request->method('getParam')->willReturn('not a list');
		$this->adapter->method('read')->with($this->anything(), 'z', [], null, 50)->willReturn(['rows' => [['id' => 1]], 'next' => null, 'pageSize' => 50, 'durationMs' => 3]);

		$data = $controller->testStatement(id: 'src-1', name: 'z')->getData();

		self::assertSame(1, $data['rowCount']);
		self::assertFalse($data['more']);
	}//end testAShortPageSaysNoMoreFollow()

	/**
	 * The adapter's refusal comes back as a 400 with its reason.
	 *
	 * @return void
	 */
	public function testARefusalIsA400WithItsReason(): void {
		$controller = $this->controller(source: ['type' => 'database']);
		$this->request->method('getParam')->willReturn([]);
		$this->adapter->method('read')->willThrowException(new DatabaseSourceException('The source declares no statement "x".'));

		$response = $controller->testStatement(id: 'src-1', name: 'x');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'The source declares no statement "x".'], $response->getData());
	}//end testARefusalIsA400WithItsReason()

	/**
	 * Schema discovery answers the tables; an unexpected failure is a 500 without details.
	 *
	 * @return void
	 */
	public function testSchemaDiscovery(): void {
		$controller = $this->controller(source: ['type' => 'database']);
		$this->adapter->expects($this->exactly(2))->method('discover')->willReturnOnConsecutiveCalls(
			['tables' => [['name' => 'zaken', 'kind' => 'table', 'columns' => []]], 'durationMs' => 4],
			$this->throwException(new RuntimeException('connection reset by 10.0.0.5'))
		);

		$response = $controller->schema(id: 'src-1');
		self::assertSame(200, $response->getStatus());
		self::assertSame('zaken', $response->getData()['tables'][0]['name']);

		$failed = $controller->schema(id: 'src-1');
		self::assertSame(500, $failed->getStatus());
		self::assertSame(['error' => 'The database source could not be read.'], $failed->getData());
	}//end testSchemaDiscovery()

	/**
	 * No source is a 404, another kind of source a 400, and the adapter is not asked.
	 *
	 * @return void
	 */
	public function testOnlyAnExistingDatabaseSourceIsServed(): void {
		$controller = $this->controller(source: null);
		$this->adapter->expects($this->never())->method('discover');
		self::assertSame(404, $controller->schema(id: 'nope')->getStatus());

		$controller = $this->controller(source: ['type' => 'api']);
		$this->adapter->expects($this->never())->method('read');
		$response = $controller->testStatement(id: 'src-1', name: 'x');
		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'This source is not a database source.'], $response->getData());
	}//end testOnlyAnExistingDatabaseSourceIsServed()
}//end class
