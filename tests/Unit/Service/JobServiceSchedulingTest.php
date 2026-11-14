<?php

/**
 * JobService scheduling unit tests
 *
 * Scheduling a job: an enabled job that is already scheduled is left alone,
 * a disabled job loses its job-list id and is saved, and a new job is added
 * to Nextcloud's job list (or scheduled after a given moment) with its own id
 * among the arguments and saved with the job-list id the database reports.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/job-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTime;
use OCA\Integriq\BackgroundJob\JobTask;
use OCA\Integriq\Service\JobService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for scheduling jobs and finding their job-list id.
 *
 * @covers \OCA\Integriq\Service\JobService
 */
class JobServiceSchedulingTest extends TestCase {

	/** @var IJobList&MockObject */
	private IJobList $jobList;

	/** @var ORObjectService&MockObject */
	private ORObjectService $objects;

	/**
	 * The class the job-list id was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $askedFor = [];

	protected function setUp(): void {
		$this->askedFor = [];
		$this->jobList = $this->createMock(IJobList::class);
		$this->objects = $this->createMock(ORObjectService::class);
		$this->objects->method('saveObject')->willReturnCallback(
			fn (array $object, string $register, string $schema, ?string $uuid = null): ObjectEntity => $this->job($uuid ?? '', $object)
		);
	}//end setUp()

	public function testAnEnabledJobThatIsAlreadyScheduledIsLeftAlone(): void {
		$job = $this->job('job-1', ['jobListId' => 12, 'isEnabled' => true]);
		$this->jobList->expects($this->never())->method('add');
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame($job, $this->service(row: ['id' => 99])->scheduleJob(job: $job));
	}//end testAnEnabledJobThatIsAlreadyScheduledIsLeftAlone()

	public function testADisabledJobLosesItsJobListIdAndIsSaved(): void {
		$this->jobList->expects($this->never())->method('add');

		$saved = $this->service(row: ['id' => 99])->scheduleJob(job: $this->job('job-2', ['jobListId' => 12, 'isEnabled' => false, 'name' => 'sync']));

		$this->assertSame(['jobListId' => null, 'isEnabled' => false, 'name' => 'sync'], $saved->getObject());
		$this->assertSame('job-2', $saved->getUuid());
	}//end testADisabledJobLosesItsJobListIdAndIsSaved()

	public function testANewJobIsAddedWithItsIdAndSavedWithTheJobListId(): void {
		$this->jobList->expects($this->once())->method('add')->with(JobTask::class, ['source' => 's-1', 'jobId' => 'job-3']);
		$this->jobList->expects($this->never())->method('scheduleAfter');

		$saved = $this->service(row: ['id' => 42])->scheduleJob(job: $this->job('job-3', ['arguments' => ['source' => 's-1']]));

		$this->assertSame(42, $saved->getObject()['jobListId']);
		$this->assertSame([JobTask::class], $this->askedFor);
	}//end testANewJobIsAddedWithItsIdAndSavedWithTheJobListId()

	public function testANewJobWithAStartMomentIsScheduledAfterIt(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->jobList->expects($this->once())->method('scheduleAfter')->with(
			JobTask::class,
			(new DateTime('2026-12-01T08:00:00+00:00'))->getTimestamp(),
			['jobId' => 'job-4']
		);

		$saved = $this->service(row: false)->scheduleJob(job: $this->job('job-4', ['scheduleAfter' => '2026-12-01T08:00:00+00:00']));

		$this->assertNull($saved->getObject()['jobListId']);
	}//end testANewJobWithAStartMomentIsScheduledAfterIt()

	public function testTheJobListIdIsLookedUpByTheClassOfAJobInstance(): void {
		$instance = $this->createMock(IJob::class);

		$this->assertSame(7, $this->service(row: ['id' => 7])->getJobListId(job: $instance));
		$this->assertSame([get_class($instance)], $this->askedFor);
	}//end testTheJobListIdIsLookedUpByTheClassOfAJobInstance()

	/**
	 * A job entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The job data.
	 *
	 * @return ObjectEntity
	 */
	private function job(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end job()

	/**
	 * The service over a database whose newest job row is $row.
	 *
	 * @param array<string, mixed>|false $row The row the query fetches.
	 *
	 * @return JobService
	 */
	private function service(array|false $row): JobService {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$result->expects($this->any())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, string $value): string => $column . ' = ' . $value);

		$query = $this->createMock(IQueryBuilder::class);
		$query->method('select')->with('id')->willReturnSelf();
		$query->method('from')->with('jobs')->willReturnSelf();
		$query->method('where')->with('class = :class')->willReturnSelf();
		$query->method('orderBy')->with('id', 'DESC')->willReturnSelf();
		$query->method('setMaxResults')->with(1)->willReturnSelf();
		$query->method('expr')->willReturn($expr);
		$query->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->askedFor[] = $value;
				return ':class';
			}
		);
		$query->method('executeQuery')->willReturn($result);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($query);

		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturn(false);

		return new JobService(
			jobList: $this->jobList,
			objectService: $this->objects,
			connection: $connection,
			containerInterface: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			appConfig: $config,
		);
	}//end service()
}//end class
