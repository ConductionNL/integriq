<?php

/**
 * Unit tests for SynchronizationContractsController's execute action, statistics, performance and export.
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

use OCA\Integriq\Controller\SynchronizationContractsController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Executing a contract, the contract statistics, the performance figures and the CSV export.
 *
 * @covers \OCA\Integriq\Controller\SynchronizationContractsController
 */
final class SynchronizationContractsControllerReportsTest extends TestCase {

	/** @var OrObjectService&MockObject */
	private OrObjectService $objects;

	/** @var ActionAuthServiceInterface&MockObject */
	private ActionAuthServiceInterface $actionAuth;

	/** @var IUser&MockObject */
	private IUser $user;

	/** @var IUser|null */
	private ?IUser $sessionUser;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects     = $this->createMock(OrObjectService::class);
		$this->actionAuth  = $this->createMock(ActionAuthServiceInterface::class);
		$this->user        = $this->createMock(IUser::class);
		$this->sessionUser = $this->user;
	}//end setUp()

	/**
	 * The controller.
	 *
	 * @return SynchronizationContractsController
	 */
	private function controller(): SynchronizationContractsController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->sessionUser);

		return new SynchronizationContractsController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->objects,
			$l,
			$session,
			$this->actionAuth
		);
	}//end controller()

	/**
	 * Executing a known contract demands the execute action and answers success.
	 *
	 * @return void
	 */
	public function testExecuteDemandsTheActionAndAnswersSuccess(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'synchronization-contract.execute');
		$this->objects->expects($this->once())->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'c-1'));

		$response = $this->controller()->execute('c-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['message' => 'Contract executed successfully'], $response->getData());
	}//end testExecuteDemandsTheActionAndAnswersSuccess()

	/**
	 * Executing an unknown contract answers 404.
	 *
	 * @return void
	 */
	public function testExecuteOfAnUnknownContractIs404(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller()->execute('nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Contract not found or could not be executed'], $response->getData());
	}//end testExecuteOfAnUnknownContractIs404()

	/**
	 * Executing without a user is refused before the action check.
	 *
	 * @return void
	 */
	public function testExecuteWithoutAUserIsUnauthorized(): void {
		$this->sessionUser = null;
		$this->actionAuth->expects($this->never())->method('requireAction');
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller()->execute('c-1');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testExecuteWithoutAUserIsUnauthorized()

	/**
	 * The statistics count contracts per status, from totals or from result lists.
	 *
	 * @return void
	 */
	public function testStatisticsCountsContractsPerStatus(): void {
		$answers = [
			''         => ['total' => 9],
			'active'   => ['results' => [1, 2, 3, 4]],
			'inactive' => [1, 2],
			'error'    => ['total' => 3],
		];
		$this->objects->expects($this->exactly(4))->method('findAll')
			->willReturnCallback(static function (array $config) use ($answers): array {
				TestCase::assertSame('synchronization_contract', $config['filters']['schema']);
				return $answers[$config['filters']['status'] ?? ''];
			});

		$response = $this->controller()->statistics();

		$this->assertSame(['totalCount' => 9, 'activeCount' => 4, 'inactiveCount' => 2, 'errorCount' => 3], $response->getData());
	}//end testStatisticsCountsContractsPerStatus()

	/**
	 * A failing count answers 500.
	 *
	 * @return void
	 */
	public function testStatisticsFailureAnswers500(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db down'));

		$response = $this->controller()->statistics();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Could not fetch statistics'], $response->getData());
	}//end testStatisticsFailureAnswers500()

	/**
	 * The performance figures cover the last 7, 30 and 90 days.
	 *
	 * @return void
	 */
	public function testPerformanceGivesThreePeriods(): void {
		$data = $this->controller()->performance()->getData();

		$this->assertSame(['last_7_days', 'last_30_days', 'last_90_days'], array_keys($data));
		$this->assertSame(['successRate' => 85.5, 'totalExecutions' => 120, 'successfulExecutions' => 103], $data['last_7_days']);
	}//end testPerformanceGivesThreePeriods()

	/**
	 * The export maps every given filter onto its stored field and writes one CSV row per contract.
	 *
	 * @return void
	 */
	public function testExportFiltersAndWritesOneRowPerContract(): void {
		$contracts = [
			ObjectServiceMockBuilder::objectEntity($this, ['synchronizationId' => 's-1', 'originId' => 'o-1', 'targetId' => 't-1', 'originHash' => 'oh', 'targetHash' => 'th', 'sourceLastSynced' => '2026-01-01', 'targetLastSynced' => '2026-01-02', 'created' => '2025-12-01', 'updated' => '2026-01-03'], 'c-1'),
			ObjectServiceMockBuilder::objectEntity($this, [], 'c-2'),
		];
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => [
				'register'           => 'integriq',
				'schema'             => 'synchronization_contract',
				'synchronization_id' => 's-1',
				'status'             => 'active',
				'origin_id'          => 'o-1',
				'target_id'          => 't-1',
				'date_from'          => '2026-01-01',
				'date_to'            => '2026-01-31',
			]])
			->willReturn(['results' => $contracts]);

		$data = $this->controller()->export('s-1', 'active', 'o-1', 't-1', '2026-01-01', '2026-01-31')->getData();

		$this->assertSame(
			"UUID,Synchronization ID,Origin ID,Target ID,Origin Hash,Target Hash,Source Last Synced,Target Last Synced,Created,Updated\n"
			."c-1,s-1,o-1,t-1,oh,th,2026-01-01,2026-01-02,2025-12-01,2026-01-03\n"
			."c-2,,,,,,,,,\n",
			$data['content']
		);
		$this->assertSame('text/csv', $data['contentType']);
		$this->assertMatchesRegularExpression('/^synchronization_contracts_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.csv$/', $data['filename']);
	}//end testExportFiltersAndWritesOneRowPerContract()

	/**
	 * Without filters the export asks for every contract; a plain list answer is read as the contracts.
	 *
	 * @return void
	 */
	public function testExportWithoutFiltersReadsAPlainList(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'synchronization_contract']])
			->willReturn([ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-9'], 'c-9')]);

		$data = $this->controller()->export()->getData();

		$this->assertStringEndsWith("c-9,,o-9,,,,,,,\n", $data['content']);
	}//end testExportWithoutFiltersReadsAPlainList()

	/**
	 * A failing export answers 500.
	 *
	 * @return void
	 */
	public function testExportFailureAnswers500(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db down'));

		$response = $this->controller()->export();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Could not export contracts'], $response->getData());
	}//end testExportFailureAnswers500()
}//end class
