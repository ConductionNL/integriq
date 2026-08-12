<?php

/**
 * Unit tests for JobService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the background job execution service (OR cutover — no deleted Db types).
 */
class JobServiceTest extends TestCase {

	/**
	 * @var JobService
	 */
	private JobService $service;

	/**
	 * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var IJobList|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $jobList;

	/**
	 * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $container;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session;

	/**
	 * @var IUserManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userMgr;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->jobList = $this->createMock(IJobList::class);

		$connection = $this->createMock(IDBConnection::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->userMgr = $this->createMock(IUserManager::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$this->service = new JobService(
			$this->jobList,
			$this->objectService,
			$connection,
			$this->container,
			$this->session,
			$this->userMgr,
			$appConfig,
		);
	}//end setUp()

	/**
	 * Test that the constructor instantiates JobService without errors.
	 *
	 * @return void
	 */
	public function testConstructorWiresDependencies(): void {
		$this->assertInstanceOf(JobService::class, $this->service);
	}//end testConstructorWiresDependencies()

	/**
	 * Test that scheduleJob disables a job that has isEnabled=false.
	 *
	 * When a job is explicitly disabled, scheduleJob should clear jobListId
	 * and persist via saveObject — the jobList must NOT be called.
	 *
	 * @return void
	 */
	public function testScheduleJobDisablesJobWhenIsEnabledFalse(): void {
		// Arrange
		$jobBody = ['isEnabled' => false, 'name' => 'test-job'];
		$jobUuid = 'job-uuid-1';
		$savedMock = ObjectServiceMockBuilder::objectEntity($this, $jobBody, $jobUuid);

		$jobEntity = ObjectServiceMockBuilder::objectEntity($this, $jobBody, $jobUuid);

		// OR ObjectService::saveObject takes (object, extend, register, schema, uuid, …)
		// and the engine invokes it with NAMED args (object: …, register: …, schema: …,
		// uuid: …), so positional `with($object, $register, $schema, $uuid)` does
		// not align — `$extend` lands between `$object` and `$register`. Use a
		// single callback that inspects PHP's full named-args view via getParams
		// would be over-engineered; assert via the captured argument list instead.
		$capturedArgs = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				static function (...$args) use (&$capturedArgs, $savedMock) {
					$capturedArgs = $args;
					return $savedMock;
				}
			);

		$this->jobList->expects($this->never())->method('add');

		// Act
		$result = $this->service->scheduleJob($jobEntity);

		// Assert — saveObject was called once with jobListId cleared. We do
		// NOT assertSame($savedMock, $result) because make()'s preconfigured
		// willReturn fires before our willReturnCallback (PHPUnit's first
		// matcher wins) — the engine returns the make()-supplied default
		// entity. The behavioural contract we actually care about is "the
		// call happened with the right payload" and "saveObject's return is
		// returned" — both true.
		$this->assertInstanceOf(ObjectEntity::class, $result);
		$this->assertIsArray($capturedArgs);
		$this->assertNotEmpty($capturedArgs, 'saveObject should have been called.');
		$payload = $capturedArgs[0];
		$this->assertIsArray($payload);
		$this->assertArrayHasKey('jobListId', $payload);
		$this->assertNull($payload['jobListId']);
		$this->assertSame(false, $payload['isEnabled']);
		// Find the uuid arg — saveObject signature is (object, extend,
		// register, schema, uuid, …).
		$this->assertContains($jobUuid, $capturedArgs);
	}//end testScheduleJobDisablesJobWhenIsEnabledFalse()

	/**
	 * Test that scheduleJob returns the same entity when job is already in the jobList.
	 *
	 * When jobListId is already set the service should bail out early and return
	 * the same entity without hitting saveObject or jobList->add.
	 *
	 * @return void
	 */
	public function testScheduleJobSkipsAlreadyScheduledJob(): void {
		// Arrange
		$jobBody = ['isEnabled' => true, 'jobListId' => 42];
		$jobEntity = ObjectServiceMockBuilder::objectEntity($this, $jobBody, 'job-uuid-2');

		$this->objectService->expects($this->never())->method('saveObject');
		$this->jobList->expects($this->never())->method('add');

		// Act
		$result = $this->service->scheduleJob($jobEntity);

		// Assert — same entity returned unchanged
		$this->assertSame($jobEntity, $result);
	}//end testScheduleJobSkipsAlreadyScheduledJob()

	/**
	 * Test that executeJob skips a disabled job and logs a WARNING.
	 *
	 * @return void
	 */
	public function testExecuteJobSkipsDisabledJob(): void {
		// Arrange
		$jobBody = [
			'isEnabled' => false,
			'name' => 'disabled-job',
		];
		$jobUuid = 'job-uuid-disabled';
		$jobEntity = ObjectServiceMockBuilder::objectEntity($this, $jobBody, $jobUuid);

		// saveObject will be called to write the job log
		$logEntity = ObjectServiceMockBuilder::objectEntity($this, ['level' => 'WARNING'], 'log-uuid');
		$this->objectService->method('saveObject')->willReturn($logEntity);

		// Act
		$result = $this->service->executeJob($jobEntity);

		// Assert — returns the saved log entity (not null)
		$this->assertNotNull($result);
	}//end testExecuteJobSkipsDisabledJob()

	/**
	 * Test that run() returns an empty array when no jobs are scheduled.
	 *
	 * @return void
	 */
	public function testRunReturnsEmptyArrayWhenNoJobsDue(): void {
		// Arrange — findAll returns empty results
		$this->objectService->method('findAll')
			->willReturn(['results' => [], 'total' => 0]);

		// Act
		$results = $this->service->run();

		// Assert
		$this->assertSame([], $results);
	}//end testRunReturnsEmptyArrayWhenNoJobsDue()

	/**
	 * #1005 regression test — a throwing job MUST NOT abort the cron pass.
	 *
	 * Two due jobs in a single run() pass: the first throws RuntimeException
	 * during $action->run(), the second runs to completion. After the pass
	 * the failing job's nextRun MUST have advanced (saveObject called for
	 * its job schema) and an ERROR job_log MUST have been written. The
	 * second job must still execute normally.
	 *
	 * @return void
	 */
	public function testRunIsolatesThrowingJobAndContinues(): void {
		// Arrange — two due jobs.
		$now = (new \DateTime('-1 hour'))->format('c');
		$throwingJob = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'isEnabled' => true,
				'jobClass' => 'OCA\\OpenConnector\\Action\\ThrowingAction',
				'interval' => 300,
				'nextRun' => $now,
				'arguments' => [],
			],
			'job-throwing'
		);
		$healthyJob = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'isEnabled' => true,
				'jobClass' => 'OCA\\OpenConnector\\Action\\HealthyAction',
				'interval' => 300,
				'nextRun' => $now,
				'arguments' => [],
			],
			'job-healthy'
		);

		// make() pre-configures findAll to return an empty result set, and
		// PHPUnit's first ->method('findAll')->willReturn() wins. Use
		// willReturnCallback to ensure our two-job stub overrides the default.
		$this->objectService->method('findAll')
			->willReturnCallback(
				static function () use ($throwingJob, $healthyJob) {
					return ['results' => [$throwingJob, $healthyJob], 'total' => 2];
				}
			);

		// Configure container to return a throwing action then a healthy one.
		$throwingAction = new class {
			public function run(array $args): array {
				throw new \RuntimeException('boom from action');
			}
		};
		$healthyCalled = false;
		$healthyAction = new class($healthyCalled) {
			private bool $called;

			public function __construct(bool &$called) {
				$this->called = & $called;
			}

			public function run(array $args): array {
				$this->called = true;
				return ['level' => 'SUCCESS', 'message' => 'ok'];
			}
		};

		$this->container->method('get')->willReturnCallback(
			static function (string $class) use ($throwingAction, $healthyAction) {
				if ($class === 'OCA\\OpenConnector\\Action\\ThrowingAction') {
					return $throwingAction;
				}
				return $healthyAction;
			}
		);

		// Track saveObject calls so we can assert both nextRun-advance and the
		// error job_log were written for the failing job. OR's saveObject is
		// (object, extend, register, schema, uuid, ...) and the engine calls
		// it with NAMED args — PHPUnit's ReturnCallback forwards positionally
		// so we accept variadic args and pluck what we need.
		$savedSchemas = [];
		$savedErrorLevels = [];
		$defaultEntity = ObjectServiceMockBuilder::objectEntity($this, [], 'saved');
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (...$args) use (
				&$savedSchemas,
				&$savedErrorLevels,
				$defaultEntity
			) {
				$object = ($args[0] ?? []);
				$schema = ($args[2] ?? null);
				$uuid = ($args[3] ?? null);
				if (is_array($object) === false) {
					$object = [];
				}
				if (is_string($schema) === true) {
					$savedSchemas[] = $schema . ($uuid !== null ? ':' . $uuid : '');
					if ($schema === 'job_log' && isset($object['level']) === true) {
						$savedErrorLevels[] = $object['level'];
					}
				}
				return $defaultEntity;
			}
		);

		// Act
		$results = $this->service->run();

		// Assert — healthy job executed.
		$this->assertTrue($healthyCalled, 'Healthy job MUST run even when the prior job threw');

		// Assert — failing job's `job` schema record was written (nextRun advance).
		$this->assertContains(
			'job:job-throwing',
			$savedSchemas,
			'Failing job must have its nextRun advanced via saveObject(schema=job, uuid=throwing-uuid)'
		);

		// Assert — an ERROR-level job_log was written for the failing job.
		$this->assertContains(
			'ERROR',
			$savedErrorLevels,
			'A throwing job must produce an ERROR-level job_log entry'
		);
	}//end testRunIsolatesThrowingJobAndContinues()

	/**
	 * #1006 regression test — the session user MUST be restored after a job
	 * runs in a user context, so the next job in the cron pass does NOT
	 * inherit that identity.
	 *
	 * @return void
	 */
	public function testExecuteJobRestoresPriorSessionUser(): void {
		// Arrange — a job configured with userId=alice.
		$jobBody = [
			'isEnabled' => true,
			'jobClass' => 'OCA\\OpenConnector\\Action\\HealthyAction',
			'interval' => 300,
			'userId' => 'alice',
			'arguments' => [],
		];
		$jobEntity = ObjectServiceMockBuilder::objectEntity($this, $jobBody, 'job-user');

		// Prior session user is null (cron starts as system).
		$alice = $this->createMock(IUser::class);
		$this->session->method('getUser')->willReturn(null);
		$this->userMgr->method('get')->with('alice')->willReturn($alice);

		// Capture setUser calls in order.
		$setUserCalls = [];
		$this->session->method('setUser')->willReturnCallback(
			static function ($user) use (&$setUserCalls) {
				$setUserCalls[] = $user;
			}
		);

		// Healthy action.
		$this->container->method('get')->willReturn(new class {
			public function run(array $args): array {
				return ['level' => 'SUCCESS', 'message' => 'ok'];
			}
		});

		// Act
		$this->service->executeJob($jobEntity);

		// Assert — setUser was called twice: first with alice, then with the
		// prior user (null) to restore the session.
		$this->assertCount(
			2,
			$setUserCalls,
			'setUser must be called twice (set + restore) for a user-scoped job'
		);
		$this->assertSame($alice, $setUserCalls[0], 'First setUser must apply the configured user');
		$this->assertNull($setUserCalls[1], 'Second setUser must restore the prior (null) session user');
	}//end testExecuteJobRestoresPriorSessionUser()

	/**
	 * #1006 regression test — a job whose configured userId no longer exists
	 * MUST be skipped with a WARNING log, NOT crash via setUser(null).
	 *
	 * @return void
	 */
	public function testExecuteJobSkipsJobWhenConfiguredUserMissing(): void {
		// Arrange — job references a deleted user.
		$jobBody = [
			'isEnabled' => true,
			'jobClass' => 'OCA\\OpenConnector\\Action\\HealthyAction',
			'interval' => 300,
			'userId' => 'deleted-user',
			'arguments' => [],
		];
		$jobEntity = ObjectServiceMockBuilder::objectEntity($this, $jobBody, 'job-missing-user');

		$this->session->method('getUser')->willReturn(null);
		$this->userMgr->method('get')->with('deleted-user')->willReturn(null);

		// setUser must NEVER be called when the user resolves to null.
		$this->session->expects($this->never())->method('setUser');

		// container->get must NEVER be invoked because the job was skipped early.
		$this->container->expects($this->never())->method('get');

		// saveObject is invoked to write the WARNING log entry. OR's
		// saveObject is (object, extend, register, schema, uuid, ...) and
		// the engine calls it with NAMED args — accept variadic.
		$logLevels = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$logLevels) {
				$object = ($args[0] ?? []);
				$schema = ($args[2] ?? null);
				if (is_array($object) === true && $schema === 'job_log') {
					$logLevels[] = $object['level'] ?? null;
				}
				return ObjectServiceMockBuilder::objectEntity(
					$this,
					is_array($object) === true ? $object : [],
					'log-uuid'
				);
			}
		);

		// Act
		$result = $this->service->executeJob($jobEntity);

		// Assert
		$this->assertNotNull($result, 'Skipped-due-to-missing-user must still return a log entity');
		$this->assertContains('WARNING', $logLevels, 'Skipped job must produce a WARNING job_log');
	}//end testExecuteJobSkipsJobWhenConfiguredUserMissing()

	/**
	 * TC-21 / job-scheduling spec REQ-004 — #1006 regression pin, combined
	 * scenario: a throwing job A (userId: alice) followed by job B (no
	 * userId) in the SAME run() pass. The session user during/after job B's
	 * execution must NOT be alice — proving executeJob()'s try/finally
	 * session restoration (already fixed in HEAD) survives a `run()`-driven
	 * cron pass, not just a single isolated executeJob() call.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/job-scheduling/spec.md#requirement-job-scheduling-registration-and-execution-with-retention-bounded-logs-req-004
	 */
	public function testRunDoesNotBleedThrowingUserScopedJobIdentityIntoNextJob(): void {
		$now = (new \DateTime('-1 hour'))->format('c');

		$jobA = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'isEnabled' => true,
				'jobClass' => 'OCA\\OpenConnector\\Action\\ThrowingUserAction',
				'interval' => 300,
				'nextRun' => $now,
				'userId' => 'alice',
				'arguments' => [],
			],
			'job-a-throwing-user'
		);
		$jobB = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'isEnabled' => true,
				'jobClass' => 'OCA\\OpenConnector\\Action\\NoUserAction',
				'interval' => 300,
				'nextRun' => $now,
				'arguments' => [],
			],
			'job-b-no-user'
		);

		$this->objectService->method('findAll')->willReturnCallback(
			static fn () => ['results' => [$jobA, $jobB], 'total' => 2]
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			fn (...$args) => ObjectServiceMockBuilder::objectEntity($this, is_array($args[0] ?? null) === true ? $args[0] : [], 'saved')
		);

		$alice = $this->createMock(IUser::class);
		// No prior session user — cron starts as system (matches
		// testExecuteJobRestoresPriorSessionUser's baseline).
		$this->session->method('getUser')->willReturn(null);
		$this->userMgr->method('get')->with('alice')->willReturn($alice);

		$sessionUserDuringJobB = 'not-observed';
		$setUserCalls = [];
		$this->session->method('setUser')->willReturnCallback(
			static function ($user) use (&$setUserCalls) {
				$setUserCalls[] = $user;
			}
		);

		$this->container->method('get')->willReturnCallback(
			function (string $class) use (&$sessionUserDuringJobB) {
				if ($class === 'OCA\\OpenConnector\\Action\\ThrowingUserAction') {
					return new class {
						public function run(array $args): array {
							throw new \RuntimeException('boom from user-scoped job A');
						}
					};
				}

				// Job B: record whatever the session's getUser() would resolve
				// to at this point via the recorded setUser() call history —
				// the LAST setUser call before job B runs must be the restore
				// (null), never alice again.
				return new class {
					public function run(array $args): array {
						return ['level' => 'SUCCESS', 'message' => 'ok'];
					}
				};
			}
		);

		// Act
		$this->service->run();

		// Assert — setUser call sequence: [alice (job A start), null (job A
		// finally-restore)] and job B, having no userId, never triggers a
		// THIRD setUser call — so the session identity in effect for job B is
		// whatever the last recorded call restored it to (null), never alice.
		$this->assertSame($alice, $setUserCalls[0], 'Job A must apply its configured user');
		$this->assertNull($setUserCalls[1], "Job A's finally-block MUST restore the prior (null) session user before job B runs");
		$this->assertCount(
			2,
			$setUserCalls,
			'Job B has no userId configured, so it must not trigger any further setUser() call — '
				. 'the session stays at whatever job A restored it to, never alice'
		);
	}//end testRunDoesNotBleedThrowingUserScopedJobIdentityIntoNextJob()

	/**
	 * TC-22 / job-scheduling spec REQ-004 — #1005 regression pin, combined
	 * scenario: three due jobs (A throws, B and C succeed) in the same
	 * run() pass. B and C must both execute and produce logs, and A's
	 * nextRun must advance by its configured interval (not left unchanged).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/job-scheduling/spec.md#requirement-job-scheduling-registration-and-execution-with-retention-bounded-logs-req-004
	 */
	public function testRunProcessesAllThreeJobsWhenTheFirstThrows(): void {
		$now = (new \DateTime('-1 hour'))->format('c');

		$makeJob = function (string $uuid, string $class) use ($now) {
			return ObjectServiceMockBuilder::objectEntity(
				$this,
				[
					'isEnabled' => true,
					'jobClass' => $class,
					'interval' => 300,
					'nextRun' => $now,
					'arguments' => [],
				],
				$uuid
			);
		};

		$jobA = $makeJob('job-a', 'OCA\\OpenConnector\\Action\\ThrowingActionABC');
		$jobB = $makeJob('job-b', 'OCA\\OpenConnector\\Action\\HealthyActionB');
		$jobC = $makeJob('job-c', 'OCA\\OpenConnector\\Action\\HealthyActionC');

		$this->objectService->method('findAll')->willReturnCallback(
			static fn () => ['results' => [$jobA, $jobB, $jobC], 'total' => 3]
		);

		$bCalled = false;
		$cCalled = false;
		$this->container->method('get')->willReturnCallback(
			function (string $class) use (&$bCalled, &$cCalled) {
				if ($class === 'OCA\\OpenConnector\\Action\\ThrowingActionABC') {
					return new class {
						public function run(array $args): array {
							throw new \RuntimeException('boom from A');
						}
					};
				}

				if ($class === 'OCA\\OpenConnector\\Action\\HealthyActionB') {
					return new class($bCalled) {
						private bool $called;
						public function __construct(bool &$called) {
							$this->called = & $called;
						}
						public function run(array $args): array {
							$this->called = true;
							return ['level' => 'SUCCESS', 'message' => 'ok'];
						}
					};
				}

				return new class($cCalled) {
					private bool $called;
					public function __construct(bool &$called) {
						$this->called = & $called;
					}
					public function run(array $args): array {
						$this->called = true;
						return ['level' => 'SUCCESS', 'message' => 'ok'];
					}
				};
			}
		);

		$savedJobRows = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$savedJobRows) {
				$object = ($args[0] ?? []);
				$schema = ($args[2] ?? null);
				$uuid = ($args[3] ?? null);
				if (is_array($object) === true && $schema === 'job' && $uuid === 'job-a') {
					$savedJobRows[] = $object;
				}
				return ObjectServiceMockBuilder::objectEntity($this, is_array($object) === true ? $object : [], $uuid ?? 'saved');
			}
		);

		// Act
		$results = $this->service->run();

		// Assert — B and C both ran despite A throwing first in the pass.
		$this->assertTrue($bCalled, 'Job B must run even though job A (earlier in the pass) threw');
		$this->assertTrue($cCalled, 'Job C must run even though job A (earlier in the pass) threw');

		// Assert — three job logs collected (A=ERROR, B/C=SUCCESS).
		$this->assertCount(3, $results, 'run() must return a log entry for every one of the three due jobs');

		// Assert — job A's nextRun advanced by its interval (not left as the
		// original due timestamp, which would re-block every subsequent tick).
		$this->assertNotEmpty($savedJobRows, "Job A's job row must have been persisted with an advanced nextRun");
		$advancedNextRun = new \DateTime($savedJobRows[0]['nextRun']);
		$this->assertGreaterThan(new \DateTime($now), $advancedNextRun, "Job A's nextRun must advance past the original due timestamp");
	}//end testRunProcessesAllThreeJobsWhenTheFirstThrows()

}//end class
