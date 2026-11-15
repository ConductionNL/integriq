<?php

/**
 * Unit tests for TablesBridgeController's status, discovery and error mapping.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\TablesBridgeController;
use OCA\Integriq\Exception\TablesConfigException;
use OCA\Integriq\Exception\TablesFeatureDisabledException;
use OCA\Integriq\Exception\TablesNotFoundException;
use OCA\Integriq\Exception\TablesPermissionDeniedException;
use OCA\Integriq\Exception\TablesUpstreamException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Tables\TablesSyncAdapterInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * the Tables status flag, table and column discovery for a source, and how each failure is answered.
 *
 * @covers \OCA\Integriq\Controller\TablesBridgeController
 * @uses   \OCA\Integriq\Exception\TablesConfigException
 * @uses   \OCA\Integriq\Exception\TablesFeatureDisabledException
 * @uses   \OCA\Integriq\Exception\TablesNotFoundException
 * @uses   \OCA\Integriq\Exception\TablesPermissionDeniedException
 * @uses   \OCA\Integriq\Exception\TablesUpstreamException
 */
final class TablesBridgeControllerDiscoveryTest extends TestCase {

	/** @var TablesSyncAdapterInterface&MockObject */
	private TablesSyncAdapterInterface $adapter;

	/** @var OrObjectService&MockObject */
	private OrObjectService $objects;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IUser|null */
	private ?IUser $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->adapter = $this->createMock(TablesSyncAdapterInterface::class);
		$this->objects = $this->createMock(OrObjectService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->user    = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The controller.
	 *
	 * @return TablesBridgeController
	 */
	private function controller(): TablesBridgeController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new TablesBridgeController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->adapter,
			$this->objects,
			$l,
			$this->logger,
			$session,
			$this->createMock(ActionAuthServiceInterface::class)
		);
	}//end controller()

	/**
	 * The status says whether Tables is enabled for the user, and refuses without one.
	 *
	 * @return void
	 */
	public function testStatusSaysWhetherTablesIsEnabledForTheUser(): void {
		$this->adapter->expects($this->once())->method('isEnabled')->with($this->user)->willReturn(true);
		$this->assertSame(['enabled' => true], $this->controller()->status()->getData());

		$this->user = null;
		$response   = $this->controller()->status();
		$this->assertSame(401, $response->getStatus());
	}//end testStatusSaysWhetherTablesIsEnabledForTheUser()

	/**
	 * The tables of a source are listed after checking Tables is enabled.
	 *
	 * @return void
	 */
	public function testTablesListsTheTablesOfTheSource(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Tables'], 'src-1');
		$this->objects->expects($this->once())->method('find')->willReturn($source);
		$this->adapter->expects($this->once())->method('assertEnabled')->with($this->user);
		$this->adapter->expects($this->once())->method('listTablesForEditor')->with($source)->willReturn([['id' => 3, 'title' => 'Cases']]);

		$response = $this->controller()->tables('src-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [['id' => 3, 'title' => 'Cases']]], $response->getData());
	}//end testTablesListsTheTablesOfTheSource()

	/**
	 * The columns of a table are listed for the source and table.
	 *
	 * @return void
	 */
	public function testColumnsListsTheColumnsOfTheTable(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, [], 'src-1');
		$this->objects->method('find')->willReturn($source);
		$this->adapter->expects($this->once())->method('listColumnsForEditor')->with($source, 7)->willReturn([['id' => 1]]);

		$this->assertSame(['results' => [['id' => 1]]], $this->controller()->columns(7, 'src-1')->getData());
	}//end testColumnsListsTheColumnsOfTheTable()

	/**
	 * Columns need a source id.
	 *
	 * @return void
	 */
	public function testColumnsNeedASourceId(): void {
		$this->adapter->expects($this->never())->method('listColumnsForEditor');

		$response = $this->controller()->columns(7);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'sourceId is required'], $response->getData());
	}//end testColumnsNeedASourceId()

	/**
	 * An unknown source answers 404 naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceIs404(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->adapter->expects($this->never())->method('listTablesForEditor');

		$response = $this->controller()->tables('src-9');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Source not found: src-9'], $response->getData());
	}//end testAnUnknownSourceIs404()

	/**
	 * The status and body each failure kind is answered with.
	 *
	 * @return array<string, array{Throwable, int, array<string, string>, string|null}>
	 */
	public static function failures(): array {
		return [
			'Tables disabled'    => [new TablesFeatureDisabledException(), 409, ['error' => 'The Tables app is not enabled'], 'info'],
			'table not found'    => [new TablesNotFoundException('Table 7 not found'), 404, ['error' => 'Table 7 not found'], null],
			'permission denied' => [new TablesPermissionDeniedException('No access to table 7', 403), 403, ['error' => 'No access to table 7'], null],
			'config problem'    => [new TablesConfigException('No base URL', 422), 422, ['error' => 'No base URL'], null],
			'upstream failure'  => [new TablesUpstreamException('Tables said 500'), 502, ['error' => 'Upstream Tables call failed'], 'warning'],
			'anything else'     => [new RuntimeException('boom'), 502, ['error' => 'Unexpected error'], 'error'],
		];
	}//end failures()

	/**
	 * Each failure of the discovery is mapped to its status, and the noteworthy ones are logged.
	 *
	 * @dataProvider failures
	 *
	 * @param Throwable            $failure The failure.
	 * @param int                  $status  The expected status.
	 * @param array<string,string> $body    The expected body.
	 * @param string|null          $level   The log level it is logged at, if any.
	 *
	 * @return void
	 */
	public function testEachFailureIsMappedToItsStatus(Throwable $failure, int $status, array $body, ?string $level): void {
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'src-1'));
		$this->adapter->method('listColumnsForEditor')->willThrowException($failure);
		foreach (['info', 'warning', 'error'] as $method) {
			$this->logger->expects($method === $level ? $this->once() : $this->never())
				->method($method)
				->with($this->stringStartsWith('TablesBridgeController: '), ['message' => $failure->getMessage()]);
		}

		$response = $this->controller()->columns(7, 'src-1');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}//end testEachFailureIsMappedToItsStatus()

	/**
	 * A disabled Tables app stops the discovery before the source is looked up.
	 *
	 * @return void
	 */
	public function testADisabledTablesAppStopsBeforeTheSourceLookup(): void {
		$this->adapter->method('assertEnabled')->willThrowException(new TablesFeatureDisabledException());
		$this->objects->expects($this->never())->method('find');

		$this->assertSame(409, $this->controller()->tables('src-1')->getStatus());
	}//end testADisabledTablesAppStopsBeforeTheSourceLookup()
}//end class
