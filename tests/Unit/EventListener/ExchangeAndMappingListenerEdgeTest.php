<?php

/**
 * Edge cases of ExchangeJobRequestedListener and MappingExecutionRequestedListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\MappingExecutionRequestedEvent;
use OCA\Integriq\EventListener\ExchangeJobRequestedListener;
use OCA\Integriq\EventListener\MappingExecutionRequestedListener;
use OCA\Integriq\Service\Exchange\ExchangeJobServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeRejectionServiceInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Events the listeners do not own are ignored; partial histories and failures are handled without throwing.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 */
#[CoversClass(ExchangeJobRequestedListener::class)]
#[CoversClass(MappingExecutionRequestedListener::class)]
#[UsesClass(ExchangeJobRequestedEvent::class)]
#[UsesClass(MappingExecutionRequestedEvent::class)]
final class ExchangeAndMappingListenerEdgeTest extends TestCase {

	/**
	 * The exchange listener ignores any other event.
	 *
	 * @return void
	 */
	public function testTheExchangeListenerIgnoresOtherEvents(): void {
		$jobs = $this->createMock(ExchangeJobServiceInterface::class);
		$jobs->expects($this->never())->method('handleRequest');

		$this->exchangeListener(jobs: $jobs)->handle(new Event());
	}//end testTheExchangeListenerIgnoresOtherEvents()

	/**
	 * No migration when the job already existed, when there is no history, or when its rejections are not a list.
	 *
	 * @return void
	 */
	public function testNothingIsMigratedWithoutANewJobOrRejectionRows(): void {
		$created = new ObjectEntity();
		$created->setUuid('job-1');
		$results = [null, $created, $created];
		$jobs = $this->createMock(ExchangeJobServiceInterface::class);
		$jobs->method('handleRequest')->willReturnCallback(static function () use (&$results) {
			return array_shift($results);
		});
		$rejections = $this->createMock(ExchangeRejectionServiceInterface::class);
		$rejections->expects($this->never())->method('migrate');
		$listener = $this->exchangeListener(jobs: $jobs, rejections: $rejections);

		$existing = new ExchangeJobRequestedEvent(ownerApp: 'learniq', target: 'bron-rod', direction: 'export', history: ['rejections' => [['errorCode' => 'X']]]);
		$listener->handle($existing);
		$listener->handle(new ExchangeJobRequestedEvent(ownerApp: 'learniq', target: 'bron-rod', direction: 'export'));
		$listener->handle(new ExchangeJobRequestedEvent(ownerApp: 'learniq', target: 'bron-rod', direction: 'export', history: ['rejections' => 'none']));

		$this->assertNull($existing->getRefusal());
	}//end testNothingIsMigratedWithoutANewJobOrRejectionRows()

	/**
	 * A rejection that cannot be stored is logged and the next one is still migrated.
	 *
	 * @return void
	 */
	public function testAFailedRejectionIsLoggedAndTheRestMigrated(): void {
		$created = new ObjectEntity();
		$created->setUuid('job-2');
		$jobs = $this->createMock(ExchangeJobServiceInterface::class);
		$jobs->method('handleRequest')->willReturn($created);
		$stored = [];
		$rejections = $this->createMock(ExchangeRejectionServiceInterface::class);
		$rejections->method('migrate')->willReturnCallback(
			static function (ObjectEntity $job, array $rejection) use (&$stored): ObjectEntity {
				if ($rejection['errorCode'] === 'BAD') {
					throw new RuntimeException('schema refused it');
				}

				$stored[] = $rejection['errorCode'];
				return new ObjectEntity();
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[integriq] a migrated rejection of exchange job job-2 was not stored: schema refused it');

		$event = new ExchangeJobRequestedEvent(
			ownerApp: 'learniq',
			target: 'bron-rod',
			direction: 'export',
			history: ['rejections' => [['errorCode' => 'BAD'], ['errorCode' => 'OK']]]
		);
		$this->exchangeListener(jobs: $jobs, rejections: $rejections, logger: $logger)->handle($event);

		$this->assertSame(['OK'], $stored);
		$this->assertNull($event->getRefusal());
	}//end testAFailedRejectionIsLoggedAndTheRestMigrated()

	/**
	 * The mapping listener ignores any other event.
	 *
	 * @return void
	 */
	public function testTheMappingListenerIgnoresOtherEvents(): void {
		$mappings = $this->createMock(MappingServiceInterface::class);
		$mappings->expects($this->never())->method('findMapping');

		(new MappingExecutionRequestedListener(mappingService: $mappings, logger: $this->createMock(LoggerInterface::class)))->handle(new Event());
	}//end testTheMappingListenerIgnoresOtherEvents()

	/**
	 * A mapping that throws becomes a `failed` refusal naming it, logged with the app and correlation id.
	 *
	 * @return void
	 */
	public function testAFailingMappingBecomesARefusal(): void {
		$mapping = new ObjectEntity();
		$mapping->setObject(['slug' => 'to-sitemap', 'callableBy' => ['portaliq']]);
		$mappings = $this->createMock(MappingServiceInterface::class);
		$mappings->expects($this->once())->method('findMapping')->with('to-sitemap')->willReturn($mapping);
		$mappings->method('executeMapping')->willThrowException(new RuntimeException('twig syntax error'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')
			->with('[integriq] mapping "to-sitemap" failed for portaliq (correlation c-1): twig syntax error');
		$listener = new MappingExecutionRequestedListener(mappingService: $mappings, logger: $logger);

		foreach ([1, 2] as $run) {
			$event = new MappingExecutionRequestedEvent(mappingSlug: 'to-sitemap', input: ['a' => 1], sourceApp: 'portaliq', correlationId: 'c-1');
			$listener->handle($event);

			$this->assertSame('failed', $event->getRefusal()['code'], 'run ' . $run);
			$this->assertSame('The mapping "to-sitemap" failed: twig syntax error', $event->getRefusal()['reason']);
			$this->assertNull($event->getOutput());
		}
	}//end testAFailingMappingBecomesARefusal()

	/**
	 * The exchange listener under test.
	 *
	 * @param ExchangeJobServiceInterface            $jobs       The job service.
	 * @param ExchangeRejectionServiceInterface|null $rejections The rejection service.
	 * @param LoggerInterface|null                   $logger     The logger.
	 *
	 * @return ExchangeJobRequestedListener
	 */
	private function exchangeListener(
		ExchangeJobServiceInterface $jobs,
		?ExchangeRejectionServiceInterface $rejections = null,
		?LoggerInterface $logger = null
	): ExchangeJobRequestedListener {
		return new ExchangeJobRequestedListener(
			$jobs,
			$rejections ?? $this->createMock(ExchangeRejectionServiceInterface::class),
			$logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end exchangeListener()
}//end class
