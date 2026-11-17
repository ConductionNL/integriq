<?php

/**
 * DocumentGenerationStatusJob sweeps queued and unreachable generation jobs, one bad vendor never stopping the rest.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\DocumentGenerationStatusJob;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\DocumentGenerationStatusJob
 */
class DocumentGenerationStatusJobTest extends TestCase {

	/** @var ObjectService&MockObject The register the jobs are read from. */
	private ObjectService&MockObject $objects;

	/** @var DocumentGenerationServiceInterface&MockObject Polls one job at its vendor. */
	private DocumentGenerationServiceInterface&MockObject $service;

	/** @var LoggerInterface&MockObject Receives the sweep's warnings. */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->service = $this->createMock(DocumentGenerationServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}

	private function job(): DocumentGenerationStatusJob {
		return new DocumentGenerationStatusJob($this->createMock(ITimeFactory::class), $this->objects, $this->service, $this->logger);
	}

	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		return $entity;
	}

	private function runJob(DocumentGenerationStatusJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testItRunsEveryFiveMinutesWhenTheServerIsIdle(): void {
		$job = $this->job();

		$this->assertSame(300, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());
	}

	public function testItPollsTheQueuedThenTheUnreachableJobsInBatchesOfFifty(): void {
		$queries = [];
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$queries): array {
				$queries[] = $config;
				return $config['filters']['status'] === 'queued'
					? ['results' => [$this->entity('q1'), $this->entity('q2')]]
					: [$this->entity('u1')];
			}
		);
		$polled = [];
		$this->service->method('pollJob')->willReturnCallback(
			function (ObjectEntity $job) use (&$polled): ObjectEntity {
				$polled[] = $job->getUuid();
				return $job;
			}
		);
		$this->logger->expects($this->never())->method('warning');

		$this->runJob($this->job());

		$this->assertSame(['q1', 'q2', 'u1'], $polled);
		$this->assertSame(
			[
				[
					'filters' => [
						'register' => DocumentGenerationServiceInterface::REGISTER,
						'schema' => DocumentGenerationServiceInterface::SCHEMA_JOB,
						'status' => 'queued',
					],
					'limit' => 50,
				],
				[
					'filters' => [
						'register' => DocumentGenerationServiceInterface::REGISTER,
						'schema' => DocumentGenerationServiceInterface::SCHEMA_JOB,
						'status' => 'unreachable',
					],
					'limit' => 50,
				],
			],
			$queries
		);
	}

	public function testAJobThatCannotBePolledIsLoggedAndTheSweepCarriesOn(): void {
		$this->objects->method('findAll')->willReturnOnConsecutiveCalls(['results' => [$this->entity('bad'), $this->entity('good')]], ['results' => []]);
		$polled = [];
		$this->service->method('pollJob')->willReturnCallback(
			function (ObjectEntity $job) use (&$polled): ObjectEntity {
				$polled[] = $job->getUuid();
				if ($job->getUuid() === 'bad') {
					throw new RuntimeException('vendor 502');
				}
				return $job;
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[integriq] a document generation job could not be polled', ['exception' => 'vendor 502']);

		$this->runJob($this->job());

		$this->assertSame(['bad', 'good'], $polled);
	}

	public function testAnUnreadableStatusIsLoggedAndTheOtherStatusIsStillSwept(): void {
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				if ($config['filters']['status'] === 'queued') {
					throw new RuntimeException('register offline');
				}
				return ['results' => [$this->entity('u1')]];
			}
		);
		$this->service->expects($this->once())->method('pollJob')
			->with($this->callback(fn (ObjectEntity $job): bool => $job->getUuid() === 'u1'))
			->willReturnArgument(0);
		$this->logger->expects($this->once())->method('warning')->with(
			'[integriq] the document generation sweep could not read its jobs',
			['status' => 'queued', 'exception' => 'register offline']
		);

		$this->runJob($this->job());
	}
}
