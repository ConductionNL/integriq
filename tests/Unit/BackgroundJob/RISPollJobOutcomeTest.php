<?php

/**
 * RISPollJob groups synced records per RIS and source, and polls iBabs once per source it can load.
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

use OCA\Integriq\BackgroundJob\RISPollJob;
use OCA\Integriq\Service\IBabsConnectorServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\RISPollJob
 */
class RISPollJobOutcomeTest extends TestCase {

	/**
	 * Log calls captured per level.
	 *
	 * @var array<string,array<int,array{0:string,1:array<string,mixed>}>>
	 */
	private array $logged = ['info' => [], 'warning' => [], 'error' => []];

	/**
	 * A synced record entity with the given data.
	 *
	 * @param array<string,mixed> $data The record.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function record(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		return $entity;
	}

	/**
	 * A logger that records every info, warning and error call.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[$level][] = [$message, $context];
				}
			);
		}

		return $logger;
	}

	public function testIBabsIsPolledOncePerLoadedSourceWithThatSourcesRecords(): void {
		$records = [
			['caseId' => 'z-1', 'risType' => 'iBabs', 'sourceId' => 'src-a'],
			['caseId' => 'z-2', 'risType' => 'ibabs', 'sourceId' => 'src-b'],
			['caseId' => 'z-3', 'risType' => 'IBABS', 'sourceId' => 'src-a'],
			['caseId' => 'z-4', 'risType' => 'notubiz', 'sourceId' => 'src-a'],
			['caseId' => 'z-5', 'risType' => 'other'],
			['caseId' => 'z-6'],
		];
		$objects = $this->createMock(OrObjectService::class);
		$objects->expects($this->once())->method('findAll')->with(
			[
				'filters' => [
					'register' => 'integriq',
					'schema' => 'ris_sync_record',
					'status' => 'synced',
				],
			]
		)->willReturn(['results' => array_map(fn (array $r): ObjectEntity => $this->record($r), $records)]);

		$sourceA = new ObjectEntity();
		$sourceB = new ObjectEntity();
		$objects->method('find')->willReturnCallback(
			static fn (string $id): ObjectEntity => ($id === 'src-a' ? $sourceA : $sourceB)
		);

		$calls = [];
		$ibabs = $this->createMock(IBabsConnectorServiceInterface::class);
		$ibabs->expects($this->exactly(2))->method('pollDecisions')->willReturnCallback(
			function (ObjectEntity $source, array $items) use (&$calls, $sourceA): array {
				$calls[] = [$source, array_column($items, 'caseId')];
				if ($source === $sourceA) {
					return [['caseId' => 'z-1', 'zaakStatus' => 'besloten']];
				}

				return [];
			}
		);

		(new RISPollJob($this->createMock(ITimeFactory::class), $ibabs, $objects, $this->logger()))->run(null);

		$this->assertSame([[$sourceA, ['z-1', 'z-3']], [$sourceB, ['z-2']]], $calls);
		$this->assertContains(['RISPollJob: iBabs besluit received', ['caseId' => 'z-1', 'status' => 'besloten']], $this->logged['info']);
		$this->assertContains(['RISPollJob: NotuBiz poll — besluit retrieval not yet implemented for NotuBiz', ['count' => 1]], $this->logged['info']);
		$this->assertSame(['RISPollJob: poll complete', ['ibabsCount' => 3, 'notubizCount' => 1]], end($this->logged['info']));
		$this->assertSame([], $this->logged['error']);
	}

	public function testRecordsWithoutASourceIdArePolledUnderTheDefaultSource(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(['results' => [$this->record(['caseId' => 'z-9', 'risType' => 'ibabs'])]]);
		$source = new ObjectEntity();
		$objects->expects($this->once())->method('find')->with('default')->willReturn($source);
		$ibabs = $this->createMock(IBabsConnectorServiceInterface::class);
		$ibabs->expects($this->once())->method('pollDecisions')->with($source, [['caseId' => 'z-9', 'risType' => 'ibabs']])->willReturn([]);

		(new RISPollJob($this->createMock(ITimeFactory::class), $ibabs, $objects, $this->logger()))->run(null);

		$this->assertSame(['RISPollJob: poll complete', ['ibabsCount' => 1, 'notubizCount' => 0]], end($this->logged['info']));
	}

	public function testAnUnloadableSourceIsSkippedWithAWarningAndAFailingPollIsLoggedAsAnError(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(
			[
				'results' => [
					$this->record(['caseId' => 'z-1', 'risType' => 'ibabs', 'sourceId' => 'gone']),
					$this->record(['caseId' => 'z-2', 'risType' => 'ibabs', 'sourceId' => 'broken']),
				],
			]
		);
		$broken = new ObjectEntity();
		$objects->method('find')->willReturnCallback(
			static function (string $id) use ($broken): ObjectEntity {
				if ($id === 'gone') {
					throw new RuntimeException('not found');
				}

				return $broken;
			}
		);
		$ibabs = $this->createMock(IBabsConnectorServiceInterface::class);
		$ibabs->expects($this->once())->method('pollDecisions')->with($broken)->willThrowException(new RuntimeException('iBabs 503'));

		(new RISPollJob($this->createMock(ITimeFactory::class), $ibabs, $objects, $this->logger()))->run(null);

		$this->assertSame(
			[
				['RISPollJob: loadSource failed', ['id' => 'gone', 'e' => 'not found']],
				['RISPollJob: iBabs source not found', ['sourceId' => 'gone']],
			],
			$this->logged['warning']
		);
		$this->assertSame([['RISPollJob: iBabs poll error', ['sourceId' => 'broken', 'exception' => 'iBabs 503']]], $this->logged['error']);
	}

	public function testAFailedReadLogsAnErrorAndPollsNothing(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('DB unavailable'));
		$ibabs = $this->createMock(IBabsConnectorServiceInterface::class);
		$ibabs->expects($this->never())->method('pollDecisions');

		(new RISPollJob($this->createMock(ITimeFactory::class), $ibabs, $objects, $this->logger()))->run(null);

		$this->assertSame([['RISPollJob: failed to load sync records', ['exception' => 'DB unavailable']]], $this->logged['error']);
		$this->assertSame(['RISPollJob: no synced records to poll', []], end($this->logged['info']));
	}

	public function testTheJobPollsEveryFifteenMinutesOneRunAtATime(): void {
		$job = new RISPollJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IBabsConnectorServiceInterface::class),
			$this->createMock(OrObjectService::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(900, $job->getInterval());
		$this->assertFalse($job->getAllowParallelRuns());
	}
}
