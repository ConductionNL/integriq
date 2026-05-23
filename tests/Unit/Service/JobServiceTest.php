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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);
        $this->jobList       = $this->createMock(IJobList::class);

        $connection = $this->createMock(IDBConnection::class);
        $container  = $this->createMock(ContainerInterface::class);
        $session    = $this->createMock(IUserSession::class);
        $userMgr    = $this->createMock(IUserManager::class);
        $appConfig  = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $this->service = new JobService(
            $this->jobList,
            $this->objectService,
            $connection,
            $container,
            $session,
            $userMgr,
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


}//end class
