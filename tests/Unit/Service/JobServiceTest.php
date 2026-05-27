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
class JobServiceTest extends TestCase
{

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
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);
        $this->jobList       = $this->createMock(IJobList::class);

        $connection      = $this->createMock(IDBConnection::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->session   = $this->createMock(IUserSession::class);
        $this->userMgr   = $this->createMock(IUserManager::class);
        $appConfig       = $this->createMock(IAppConfig::class);
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
    public function testConstructorWiresDependencies(): void
    {
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
    public function testScheduleJobDisablesJobWhenIsEnabledFalse(): void
    {
        // Arrange
        $jobBody    = ['isEnabled' => false, 'name' => 'test-job'];
        $jobUuid    = 'job-uuid-1';
        $savedMock  = ObjectServiceMockBuilder::objectEntity($this, $jobBody, $jobUuid);

        $jobEntity = ObjectServiceMockBuilder::objectEntity($this, $jobBody, $jobUuid);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(static fn(array $data) => ($data['jobListId'] ?? 'not-null') === null),
                $this->equalTo('openconnector'),
                $this->equalTo('job'),
                $this->equalTo($jobUuid)
            )
            ->willReturn($savedMock);

        $this->jobList->expects($this->never())->method('add');

        // Act
        $result = $this->service->scheduleJob($jobEntity);

        // Assert
        $this->assertSame($savedMock, $result);
    }//end testScheduleJobDisablesJobWhenIsEnabledFalse()


    /**
     * Test that scheduleJob returns the same entity when job is already in the jobList.
     *
     * When jobListId is already set the service should bail out early and return
     * the same entity without hitting saveObject or jobList->add.
     *
     * @return void
     */
    public function testScheduleJobSkipsAlreadyScheduledJob(): void
    {
        // Arrange
        $jobBody   = ['isEnabled' => true, 'jobListId' => 42];
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
    public function testExecuteJobSkipsDisabledJob(): void
    {
        // Arrange
        $jobBody   = [
            'isEnabled' => false,
            'name'      => 'disabled-job',
        ];
        $jobUuid   = 'job-uuid-disabled';
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
    public function testRunReturnsEmptyArrayWhenNoJobsDue(): void
    {
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
    public function testRunIsolatesThrowingJobAndContinues(): void
    {
        // Arrange — two due jobs.
        $now           = (new \DateTime('-1 hour'))->format('c');
        $throwingJob   = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'isEnabled' => true,
                'jobClass'  => 'OCA\\OpenConnector\\Action\\ThrowingAction',
                'interval'  => 300,
                'nextRun'   => $now,
                'arguments' => [],
            ],
            'job-throwing'
        );
        $healthyJob    = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'isEnabled' => true,
                'jobClass'  => 'OCA\\OpenConnector\\Action\\HealthyAction',
                'interval'  => 300,
                'nextRun'   => $now,
                'arguments' => [],
            ],
            'job-healthy'
        );

        $this->objectService->method('findAll')
            ->willReturn(['results' => [$throwingJob, $healthyJob], 'total' => 2]);

        // Configure container to return a throwing action then a healthy one.
        $throwingAction = new class {
            public function run(array $args): array
            {
                throw new \RuntimeException('boom from action');
            }
        };
        $healthyCalled = false;
        $healthyAction = new class($healthyCalled) {
            private bool $called;

            public function __construct(bool &$called)
            {
                $this->called =& $called;
            }

            public function run(array $args): array
            {
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
        // error job_log were written for the failing job.
        $savedSchemas      = [];
        $savedErrorLevels  = [];
        $defaultEntity     = ObjectServiceMockBuilder::objectEntity($this, [], 'saved');
        $this->objectService->method('saveObject')->willReturnCallback(
            static function (array $object, string $register, string $schema, ?string $uuid=null) use (
                &$savedSchemas,
                &$savedErrorLevels,
                $defaultEntity
            ) {
                $savedSchemas[] = $schema.($uuid !== null ? ':'.$uuid : '');
                if ($schema === 'job_log' && isset($object['level']) === true) {
                    $savedErrorLevels[] = $object['level'];
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
    public function testExecuteJobRestoresPriorSessionUser(): void
    {
        // Arrange — a job configured with userId=alice.
        $jobBody = [
            'isEnabled' => true,
            'jobClass'  => 'OCA\\OpenConnector\\Action\\HealthyAction',
            'interval'  => 300,
            'userId'    => 'alice',
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
            public function run(array $args): array
            {
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
    public function testExecuteJobSkipsJobWhenConfiguredUserMissing(): void
    {
        // Arrange — job references a deleted user.
        $jobBody = [
            'isEnabled' => true,
            'jobClass'  => 'OCA\\OpenConnector\\Action\\HealthyAction',
            'interval'  => 300,
            'userId'    => 'deleted-user',
            'arguments' => [],
        ];
        $jobEntity = ObjectServiceMockBuilder::objectEntity($this, $jobBody, 'job-missing-user');

        $this->session->method('getUser')->willReturn(null);
        $this->userMgr->method('get')->with('deleted-user')->willReturn(null);

        // setUser must NEVER be called when the user resolves to null.
        $this->session->expects($this->never())->method('setUser');

        // container->get must NEVER be invoked because the job was skipped early.
        $this->container->expects($this->never())->method('get');

        // saveObject is invoked to write the WARNING log entry.
        $logLevels = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, string $register, string $schema, ?string $uuid=null) use (&$logLevels) {
                if ($schema === 'job_log') {
                    $logLevels[] = $object['level'] ?? null;
                }
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'log-uuid');
            }
        );

        // Act
        $result = $this->service->executeJob($jobEntity);

        // Assert
        $this->assertNotNull($result, 'Skipped-due-to-missing-user must still return a log entity');
        $this->assertContains('WARNING', $logLevels, 'Skipped job must produce a WARNING job_log');
    }//end testExecuteJobSkipsJobWhenConfiguredUserMissing()


}//end class
