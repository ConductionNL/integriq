<?php

/**
 * Unit tests for SynchronizationsController's test, run, statistics and log export actions.
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

use Exception;
use OCA\Integriq\Controller\SynchronizationsController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\SynchronizationRunProgressServiceInterface;
use OCA\Integriq\Service\SynchronizationServiceInterface;
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

/**
 * The test and run actions, the statistics, the log statistics and the log export.
 *
 * @covers \OCA\Integriq\Controller\SynchronizationsController
 */
final class SynchronizationsControllerActionsTest extends TestCase {

	/** @var OrObjectService&MockObject */
	private OrObjectService $objects;

	/** @var SynchronizationServiceInterface&MockObject */
	private SynchronizationServiceInterface $service;

	/** @var ActionAuthServiceInterface&MockObject */
	private ActionAuthServiceInterface $actionAuth;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var IUser&MockObject */
	private IUser $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects    = $this->createMock(OrObjectService::class);
		$this->service    = $this->createMock(SynchronizationServiceInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->user       = $this->createMock(IUser::class);
		$this->session    = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($this->user);
	}//end setUp()

	/**
	 * The controller for one request.
	 *
	 * @param array<string, mixed>                       $params   The request parameters.
	 * @param SynchronizationRunProgressServiceInterface $progress The run progress service, if any.
	 *
	 * @return SynchronizationsController
	 */
	private function controller(array $params = [], ?SynchronizationRunProgressServiceInterface $progress = null): SynchronizationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new SynchronizationsController(
			'integriq',
			$request,
			$this->objects,
			$this->service,
			$l,
			$this->logger,
			$this->session,
			$this->actionAuth,
			$progress
		);
	}//end controller()

	/**
	 * Make the synchronization lookup find one synchronization.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity The synchronization
	 */
	private function findsSynchronization(): \OCA\OpenRegister\Db\ObjectEntity {
		$sync = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'KVK pull'], 'sync-1');
		$this->objects->method('find')->willReturn($sync);
		return $sync;
	}//end findsSynchronization()

	/**
	 * A test run of a synchronization checks the action, looks it up without RBAC, and answers the engine's result.
	 *
	 * @return void
	 */
	public function testTestRunsTheSynchronizationAsATest(): void {
		$sync = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'KVK pull'], 'sync-1');
		$this->objects->expects($this->once())->method('find')
			->with($this->callback(static fn ($id): bool => $id === 'sync-1'))
			->willReturn($sync);
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'synchronization.test');
		$this->service->expects($this->once())->method('synchronize')
			->willReturnCallback(function ($synchronization, $isTest, $force) use ($sync): array {
				$this->assertSame($sync, $synchronization);
				$this->assertTrue($isTest);
				$this->assertTrue($force);
				return ['log' => ['result' => 'ok']];
			});

		$response = $this->controller()->test('sync-1', true);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['log' => ['result' => 'ok']], $response->getData());
	}//end testTestRunsTheSynchronizationAsATest()

	/**
	 * A test without a signed-in user is refused before anything is looked up.
	 *
	 * @return void
	 */
	public function testTestWithoutAUserIsUnauthorized(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->session = $session;
		$this->objects->expects($this->never())->method('find');
		$this->actionAuth->expects($this->never())->method('requireAction');

		$response = $this->controller()->test('sync-1');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testTestWithoutAUserIsUnauthorized()

	/**
	 * A test of an unknown synchronization answers 404 without running anything.
	 *
	 * @return void
	 */
	public function testTestOfAnUnknownSynchronizationIs404(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->service->expects($this->never())->method('synchronize');

		$response = $this->controller()->test('nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Not Found'], $response->getData());
	}//end testTestOfAnUnknownSynchronizationIs404()

	/**
	 * A failed test answers with the exception's code and message.
	 *
	 * @return void
	 */
	public function testAFailedTestAnswersWithTheExceptionCode(): void {
		$this->findsSynchronization();
		$this->service->method('synchronize')->willThrowException(new Exception('Source answered 503', 503));

		$response = $this->controller()->test('sync-1');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'Synchronization error', 'message' => 'Source answered 503'], $response->getData());
	}//end testAFailedTestAnswersWithTheExceptionCode()

	/**
	 * A failure without a code answers 400, never status 0.
	 *
	 * @return void
	 */
	public function testAFailedTestWithoutACodeAnswers400(): void {
		$this->findsSynchronization();
		$this->service->method('synchronize')->willThrowException(new Exception('broken'));

		$response = $this->controller()->test('sync-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('broken', $response->getData()['message']);
	}//end testAFailedTestWithoutACodeAnswers400()

	/**
	 * A failure that carries headers passes them on.
	 *
	 * @return void
	 */
	public function testAFailedTestPassesTheExceptionHeadersOn(): void {
		$this->findsSynchronization();
		$this->service->method('synchronize')->willThrowException(new class('slow down', 429) extends Exception {
			/**
			 * The headers the failure carries.
			 *
			 * @return array<string, string>
			 */
			public function getHeaders(): array {
				return ['Retry-After' => '30'];
			}//end getHeaders()
		});

		$response = $this->controller()->test('sync-1');

		$this->assertSame(429, $response->getStatus());
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame('30', $headers['Retry-After']);
	}//end testAFailedTestPassesTheExceptionHeadersOn()

	/**
	 * A run reads its flags from the request and passes them, the source and the data to the engine.
	 *
	 * @return void
	 */
	public function testRunPassesTheRequestFlagsToTheEngine(): void {
		$sync = $this->findsSynchronization();
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'synchronization.run');
		$this->service->expects($this->once())->method('synchronize')
			->willReturnCallback(function (...$args) use ($sync): array {
				$this->assertSame($sync, $args[0]);
				$this->assertTrue($args[1]);
				$this->assertFalse($args[2]);
				$this->assertSame('source-1', $args[5]);
				$this->assertSame(['a' => 1], $args[6]);
				$this->assertTrue($args[8]);
				$this->assertNull($args[11]);
				return ['result' => 'done'];
			});

		$response = $this->controller(['test' => 'true', 'force' => 'no', 'source' => 'source-1', 'data' => ['a' => 1], 'forceDeletion' => '1', 'triggeredBy' => 'cron'])->run('sync-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['result' => 'done'], $response->getData());
	}//end testRunPassesTheRequestFlagsToTheEngine()

	/**
	 * A run without flags is a real, unforced run with no data and no run id to name.
	 *
	 * @return void
	 */
	public function testRunDefaultsToARealRunWithoutARunId(): void {
		$this->findsSynchronization();
		$this->service->expects($this->once())->method('synchronize')
			->willReturnCallback(function (...$args): array {
				$this->assertFalse($args[1]);
				$this->assertFalse($args[2]);
				$this->assertNull($args[5]);
				$this->assertSame([], $args[6]);
				$this->assertFalse($args[8]);
				return ['result' => 'done'];
			});
		$progress = $this->createMock(SynchronizationRunProgressServiceInterface::class);
		$progress->method('lastRunId')->willReturn(null);

		$response = $this->controller([], $progress)->run('sync-1');

		$this->assertSame(['result' => 'done'], $response->getData());
	}//end testRunDefaultsToARealRunWithoutARunId()

	/**
	 * A run without a user, or of an unknown synchronization, never reaches the engine.
	 *
	 * @return void
	 */
	public function testRunRefusesWithoutAUserAndAnswers404ForAnUnknownSynchronization(): void {
		$this->service->expects($this->never())->method('synchronize');
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->assertSame(404, $this->controller()->run('nope')->getStatus());

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->session = $session;
		$this->assertSame(401, $this->controller()->run('sync-1')->getStatus());
	}//end testRunRefusesWithoutAUserAndAnswers404ForAnUnknownSynchronization()

	/**
	 * A failed run answers 400 and drops the run id when there is none.
	 *
	 * @return void
	 */
	public function testAFailedRunWithoutARunRecordOmitsTheRunId(): void {
		$this->findsSynchronization();
		$this->service->method('synchronize')->willThrowException(new Exception('Source down', 502));

		$response = $this->controller()->run('sync-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Synchronization error', 'message' => 'Source down'], $response->getData());
	}//end testAFailedRunWithoutARunRecordOmitsTheRunId()

	/**
	 * The statistics count all and enabled synchronizations and give the enabled share.
	 *
	 * @return void
	 */
	public function testStatisticsCountsEnabledAndDisabledSynchronizations(): void {
		$this->objects->expects($this->exactly(2))->method('findAll')
			->willReturnCallback(static function (array $config): array {
				if (isset($config['filters']['isEnabled']) === true) {
					return ['results' => [1, 2]];
				}
				return ['total' => 3];
			});

		$data = $this->controller()->statistics()->getData();

		$this->assertSame(3, $data['totalCount']);
		$this->assertSame(2, $data['enabledCount']);
		$this->assertSame(1, $data['disabledCount']);
		$this->assertSame(['enabled' => 2, 'disabled' => 1], $data['statusDistribution']);
		$this->assertSame(66.67, $data['enabledPercentage']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['generatedAt']);
	}//end testStatisticsCountsEnabledAndDisabledSynchronizations()

	/**
	 * Without synchronizations the enabled share is zero.
	 *
	 * @return void
	 */
	public function testStatisticsWithoutSynchronizationsIsZero(): void {
		$this->objects->method('findAll')->willReturn([]);

		$data = $this->controller()->statistics()->getData();

		$this->assertSame(0, $data['totalCount']);
		$this->assertSame(0, $data['enabledPercentage']);
	}//end testStatisticsWithoutSynchronizationsIsZero()

	/**
	 * A failing count is logged and answers 500.
	 *
	 * @return void
	 */
	public function testStatisticsFailureIsLoggedAnd500(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('Error fetching synchronization statistics: db down');

		$response = $this->controller()->statistics();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('Could not fetch synchronization statistics', $response->getData()['error']);
	}//end testStatisticsFailureIsLoggedAnd500()

	/**
	 * The log statistics count each status, the success rate and an estimate of recent activity.
	 *
	 * @return void
	 */
	public function testLogsStatisticsCountsEachStatus(): void {
		$counts = ['' => 20, 'success' => 15, 'error' => 3, 'warning' => 1, 'info' => 1, 'debug' => 0];
		$this->objects->expects($this->exactly(6))->method('findAll')
			->willReturnCallback(static function (array $config) use ($counts): array {
				$status = ($config['filters']['status'] ?? '');
				return ['total' => $counts[$status]];
			});

		$data = $this->controller()->logsStatistics()->getData();

		$this->assertSame(20, $data['totalCount']);
		$this->assertSame(['success' => 15, 'error' => 3, 'warning' => 1, 'info' => 1, 'debug' => 0], $data['statusDistribution']);
		$this->assertSame(75.0, $data['successRate']);
		$this->assertSame(2, $data['recentActivity']);
	}//end testLogsStatisticsCountsEachStatus()

	/**
	 * With few logs recent activity is at least one; without logs everything is zero.
	 *
	 * @return void
	 */
	public function testLogsStatisticsEdges(): void {
		$this->objects->method('findAll')->willReturnOnConsecutiveCalls(
			['total' => 3], ['total' => 0], ['total' => 0], ['total' => 0], ['total' => 0], ['total' => 0],
			[], [], [], [], [], []
		);
		$controller = $this->controller();

		$few = $controller->logsStatistics()->getData();
		$this->assertSame(1, $few['recentActivity']);
		$this->assertSame(0.0, $few['successRate']);

		$none = $controller->logsStatistics()->getData();
		$this->assertSame(0, $none['totalCount']);
		$this->assertSame(0, $none['successRate']);
		$this->assertSame(0, $none['recentActivity']);
	}//end testLogsStatisticsEdges()

	/**
	 * A failing log count is logged and answers 500.
	 *
	 * @return void
	 */
	public function testLogsStatisticsFailureIsLoggedAnd500(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('Error fetching synchronization logs statistics: db down');

		$response = $this->controller()->logsStatistics();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('Could not fetch synchronization logs statistics', $response->getData()['error']);
	}//end testLogsStatisticsFailureIsLoggedAnd500()

	/**
	 * The export filters on the request without its paging, and writes one escaped CSV row per log.
	 *
	 * @return void
	 */
	public function testLogsExportWritesOneCsvRowPerLog(): void {
		$logs = [
			ObjectServiceMockBuilder::objectEntity($this, ['status' => 'error', 'message' => 'Said "no", twice', 'synchronizationId' => 's-1', 'sourceId' => 'src', 'targetId' => 'tgt', 'userId' => 'ann', 'created' => '2026-01-02', 'syncTime' => '12'], 'log-1'),
			ObjectServiceMockBuilder::objectEntity($this, [], 'log-2'),
		];
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'synchronization_log', 'status' => 'error']])
			->willReturn(['results' => $logs]);

		$data = $this->controller(['status' => 'error', '_limit' => 10, '_page' => 2, '_sort' => 'created', '_order' => 'desc'])->logsExport()->getData();

		$this->assertSame(
			"UUID,Status,Message,Synchronization ID,Source ID,Target ID,User ID,Created,Execution Time\n"
			."log-1,error,\"Said \"\"no\"\", twice\",s-1,src,tgt,ann,2026-01-02,12\n"
			."log-2,,\"\",,,,,,\n",
			base64_decode($data['content'])
		);
		$this->assertSame(2, $data['recordCount']);
		$this->assertSame('text/csv', $data['contentType']);
		$this->assertMatchesRegularExpression('/^synchronization_logs_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.csv$/', $data['filename']);
	}//end testLogsExportWritesOneCsvRowPerLog()

	/**
	 * A failing export is logged and answers 500.
	 *
	 * @return void
	 */
	public function testLogsExportFailureIsLoggedAnd500(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('Error exporting synchronization logs: db down');

		$response = $this->controller()->logsExport();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('Could not export synchronization logs', $response->getData()['error']);
	}//end testLogsExportFailureIsLoggedAnd500()

	/**
	 * A delete that fails answers 500 with the reason.
	 *
	 * @return void
	 */
	public function testDeleteLogFailureAnswers500(): void {
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'log-9'));
		$this->objects->method('deleteObject')->willThrowException(new RuntimeException('locked'));

		$response = $this->controller()->deleteLog(9);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to delete log: %s'], $response->getData());
	}//end testDeleteLogFailureAnswers500()
}//end class
