<?php

/**
 * Characterization tests for JobService::executeJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTime;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Pins the job log, the job timeline and the trace that executeJob writes.
 */
class JobServiceExecuteJobTest extends TestCase {

	/**
	 * @var JobService
	 */
	private JobService $service;

	/**
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private $container;

	/**
	 * @var ExecutionTraceService&MockObject
	 */
	private $traceService;

	/**
	 * Saved objects keyed by schema, in call order.
	 *
	 * @var array<string, list<array>>
	 */
	private array $saved = [];

	/**
	 * Arguments the job action received.
	 *
	 * @var array|null
	 */
	private ?array $receivedArguments = null;

	/**
	 * The value the job action returns, or the exception it throws.
	 *
	 * @var mixed
	 */
	private mixed $actionOutcome = null;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				$this->saved[$args[2]][] = $args[0];
				return ObjectServiceMockBuilder::objectEntity($this, $args[0], 'saved-' . $args[2]);
			}
		);

		$this->traceService = $this->createMock(ExecutionTraceService::class);
		$action = new class($this) {
			public function __construct(private JobServiceExecuteJobTest $test) {
			}
			public function run(array $args): mixed {
				return $this->test->runAction($args);
			}
		};

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			fn (string $class) => $class === ExecutionTraceService::class ? $this->traceService : $action
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$this->service = new JobService(
			$this->createMock(IJobList::class),
			$this->objectService,
			$this->createMock(IDBConnection::class),
			$this->container,
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$appConfig,
		);
	}//end setUp()

	/**
	 * The fake job action: records its arguments and returns or throws the configured outcome.
	 *
	 * @param array $args The action arguments.
	 *
	 * @return mixed The configured outcome.
	 */
	public function runAction(array $args): mixed {
		$this->receivedArguments = $args;
		if ($this->actionOutcome instanceof \Throwable) {
			throw $this->actionOutcome;
		}

		return $this->actionOutcome;
	}//end runAction()

	/**
	 * Builds a job entity.
	 *
	 * @param array $overrides Job fields to override.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity The job.
	 */
	private function job(array $overrides = []): \OCA\OpenRegister\Db\ObjectEntity {
		$body = array_merge(
			['isEnabled' => true, 'jobClass' => 'Some\\Action', 'interval' => 3600, 'arguments' => ['a' => 1]],
			$overrides
		);

		return ObjectServiceMockBuilder::objectEntity($this, $body, 'job-1');
	}//end job()

	/**
	 * Test that a job whose next run lies in the future is skipped without a log.
	 *
	 * @return void
	 */
	public function testJobNotDueReturnsNullAndWritesNothing(): void {
		$result = $this->service->executeJob($this->job(['nextRun' => (new DateTime('+1 day'))->format('c')]));

		$this->assertNull($result);
		$this->assertSame([], $this->saved);
		$this->assertNull($this->receivedArguments);
	}//end testJobNotDueReturnsNullAndWritesNothing()

	/**
	 * Test that the action receives the job arguments plus the trace and job id.
	 *
	 * @return void
	 */
	public function testActionReceivesArgumentsWithTraceAndJobId(): void {
		$this->actionOutcome = ['level' => 'SUCCESS'];
		$this->service->executeJob($this->job());

		$this->assertSame(1, $this->receivedArguments['a']);
		$this->assertSame('job-1', $this->receivedArguments['_jobId']);
		$this->assertInstanceOf(ExecutionTraceContext::class, $this->receivedArguments['_executionTrace']);
	}//end testActionReceivesArgumentsWithTraceAndJobId()

	/**
	 * Test that a non-array argument field is replaced by an empty list.
	 *
	 * @return void
	 */
	public function testNonArrayArgumentsAreReplaced(): void {
		$this->service->executeJob($this->job(['arguments' => 'oops']));

		$this->assertSame(['_executionTrace', '_jobId'], array_keys($this->receivedArguments));
	}//end testNonArrayArgumentsAreReplaced()

	/**
	 * Test the log and timeline of a plain successful run.
	 *
	 * @return void
	 */
	public function testSuccessfulRunWritesSuccessLogAndAdvancesTimeline(): void {
		$this->actionOutcome = 'not-an-array';
		$this->traceService->expects($this->once())->method('persist')
			->with($this->isInstanceOf(ExecutionTraceContext::class), 'success', null);

		$before = new DateTime('now + 3540 seconds');
		$this->service->executeJob($this->job(['logRetention' => 5]));

		$log = $this->saved['job_log'][0];
		$this->assertSame('SUCCESS', $log['level']);
		$this->assertSame('Success', $log['message']);
		$this->assertIsInt($log['executionTime']);
		$this->assertNull($log['stackTrace']);
		$this->assertNotNull($log['expires']);

		$job = $this->saved['job'][0];
		$this->assertArrayHasKey('lastRun', $job);
		$nextRun = new DateTime($job['nextRun']);
		$this->assertSame('00', $nextRun->format('s'));
		$this->assertGreaterThan($before, $nextRun);
		$this->assertTrue($job['isEnabled']);
		$this->assertSame(['job_log', 'job'], array_keys($this->saved));
	}//end testSuccessfulRunWritesSuccessLogAndAdvancesTimeline()

	/**
	 * Test that the result level, message and stack trace end up in the log.
	 *
	 * @return void
	 */
	public function testResultLevelMessageAndStackTraceAreLogged(): void {
		$this->actionOutcome = ['level' => 'WARNING', 'message' => 'careful', 'stackTrace' => ['one', 'two']];
		$this->service->executeJob($this->job(['errorRetention' => 0]));

		$log = $this->saved['job_log'][0];
		$this->assertSame('WARNING', $log['level']);
		$this->assertSame('careful', $log['message']);
		$this->assertSame(['frame_0' => 'one', 'frame_1' => 'two'], $log['stackTrace']);
		$expires = new DateTime($log['expires']);
		$this->assertGreaterThan(new DateTime('+2 days'), $expires);
		$this->assertLessThan(new DateTime('+4 days'), $expires);
	}//end testResultLevelMessageAndStackTraceAreLogged()

	/**
	 * Test that a non-success level uses the error retention for its expiry.
	 *
	 * @return void
	 */
	public function testNonSuccessLevelUsesErrorRetention(): void {
		$this->actionOutcome = ['level' => 'INFO'];
		$this->service->executeJob($this->job(['errorRetention' => 1]));

		$expires = new DateTime($this->saved['job_log'][0]['expires']);
		$this->assertGreaterThan(new DateTime('+29 days'), $expires);
	}//end testNonSuccessLevelUsesErrorRetention()

	/**
	 * Test that a single-run job is disabled after a successful run.
	 *
	 * @return void
	 */
	public function testIsSingleRunSpellingDisablesTheJob(): void {
		$this->service->executeJob($this->job(['isSingleRun' => true]));

		$this->assertFalse($this->saved['job'][0]['isEnabled']);
	}//end testIsSingleRunSpellingDisablesTheJob()

	/**
	 * Test that a resumeSoon result schedules the next run now.
	 *
	 * @return void
	 */
	public function testResumeSoonSchedulesNow(): void {
		$this->actionOutcome = ['resumeSoon' => true];
		$this->service->executeJob($this->job(['interval' => 86400]));

		$nextRun = new DateTime($this->saved['job'][0]['nextRun']);
		$this->assertLessThanOrEqual(new DateTime(), $nextRun);
	}//end testResumeSoonSchedulesNow()

	/**
	 * Test that a later rate-limit nextRun wins and is rounded up to the minute.
	 *
	 * @return void
	 */
	public function testRateLimitNextRunWinsWhenLater(): void {
		$limit = (time() + 7200);
		if ($limit % 60 === 0) {
			$limit++;
		}

		$this->actionOutcome = ['nextRun' => $limit];
		$this->service->executeJob($this->job(['interval' => 60]));

		$nextRun = new DateTime($this->saved['job'][0]['nextRun']);
		$this->assertSame((int) (ceil($limit / 60) * 60), $nextRun->getTimestamp());
	}//end testRateLimitNextRunWinsWhenLater()

	/**
	 * Test that an earlier rate-limit nextRun is ignored.
	 *
	 * @return void
	 */
	public function testRateLimitNextRunIgnoredWhenEarlier(): void {
		$this->actionOutcome = ['nextRun' => (time() - 7200)];
		$this->service->executeJob($this->job(['interval' => 3600]));

		$nextRun = new DateTime($this->saved['job'][0]['nextRun']);
		$this->assertGreaterThan(new DateTime('+3500 seconds'), $nextRun);
	}//end testRateLimitNextRunIgnoredWhenEarlier()

	/**
	 * Test the log, timeline and trace of a run whose action throws.
	 *
	 * @return void
	 */
	public function testThrowingActionWritesErrorLogAndFailedTrace(): void {
		$this->actionOutcome = new RuntimeException('boom');
		$this->traceService->expects($this->once())->method('persist')
			->with(
				$this->isInstanceOf(ExecutionTraceContext::class),
				'failed',
				['message' => 'boom', 'ruleType' => null, 'ruleName' => null]
			);

		$this->service->executeJob($this->job(['singleRun' => true, 'errorRetention' => 1, 'interval' => 7200]));

		$log = $this->saved['job_log'][0];
		$this->assertSame('ERROR', $log['level']);
		$this->assertSame('RuntimeException: boom', $log['message']);
		$this->assertNotEmpty($log['stackTrace']);
		$this->assertLessThanOrEqual(50, count($log['stackTrace']));
		$this->assertGreaterThan(new DateTime('+29 days'), new DateTime($log['expires']));

		$job = $this->saved['job'][0];
		$this->assertTrue($job['isEnabled']);
		$this->assertGreaterThan(new DateTime('+7100 seconds'), new DateTime($job['nextRun']));
	}//end testThrowingActionWritesErrorLogAndFailedTrace()

	/**
	 * Test that a force run logs the force-run note, keeps the timeline and ignores disabled.
	 *
	 * @return void
	 */
	public function testForceRunKeepsTimelineAndNotesTheForceRun(): void {
		$this->actionOutcome = ['stackTrace' => ['step']];
		$future = (new DateTime('+1 day'))->format('c');
		$this->service->executeJob($this->job(['isEnabled' => false, 'nextRun' => $future, 'singleRun' => true]), true);

		$log = $this->saved['job_log'][0];
		$this->assertSame(
			['frame_0' => 'Doing a force run for this job, ignoring "enabled" & "nextRun" check...', 'frame_1' => 'step'],
			$log['stackTrace']
		);
		$job = $this->saved['job'][0];
		$this->assertSame($future, $job['nextRun']);
		$this->assertFalse($job['isEnabled']);
	}//end testForceRunKeepsTimelineAndNotesTheForceRun()

	/**
	 * Test that a supplied trace is reused and not persisted by executeJob.
	 *
	 * @return void
	 */
	public function testSuppliedTraceIsReusedAndNotPersisted(): void {
		$trace = new ExecutionTraceContext(entryPoint: 'job', entryPointId: 'job-1', triggeredBy: 'replay');
		$this->traceService->expects($this->never())->method('persist');

		$this->service->executeJob($this->job(), false, $trace);

		$this->assertSame($trace, $this->receivedArguments['_executionTrace']);
	}//end testSuppliedTraceIsReusedAndNotPersisted()

	/**
	 * Test that a failing trace persistence does not fail the run.
	 *
	 * @return void
	 */
	public function testTracePersistenceFailureIsSwallowed(): void {
		$this->traceService->method('persist')->willThrowException(new RuntimeException('db down'));

		$result = $this->service->executeJob($this->job());

		$this->assertNotNull($result);
	}//end testTracePersistenceFailureIsSwallowed()
}//end class
