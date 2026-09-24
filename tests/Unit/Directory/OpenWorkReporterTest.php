<?php

/**
 * Unit tests for OpenWorkReporter — what a leaver still holds, asked rather
 * than read, with `unknown` for a consumer that did not answer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\IOpenWorkConsumer;
use OCA\Integriq\Directory\OpenWorkReporter;
use OCA\Integriq\Event\OpenWorkQueryEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the open-work report.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
 */
class OpenWorkReporterTest extends TestCase {

	/**
	 * A reporter over a dispatcher that answers for the given consumers.
	 *
	 * @param array<string,integer> $eventAnswers Answers a listener would give.
	 *
	 * @return OpenWorkReporter The reporter.
	 */
	private function reporter(array $eventAnswers = []): OpenWorkReporter {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function ($event) use ($eventAnswers): void {
				if (($event instanceof OpenWorkQueryEvent) === false) {
					return;
				}

				foreach ($eventAnswers as $consumerId => $count) {
					$event->answer((string)$consumerId, (int)$count);
				}
			}
		);

		return new OpenWorkReporter(
			eventDispatcher: $dispatcher,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end reporter()

	/**
	 * An in-process consumer that answers a fixed count.
	 *
	 * @param string $consumerId The consumer id.
	 * @param integer|null $count The count, or null for "cannot say".
	 *
	 * @return IOpenWorkConsumer The consumer.
	 */
	private function consumer(string $consumerId, ?int $count): IOpenWorkConsumer {
		return new class($consumerId, $count) implements IOpenWorkConsumer {

			/**
			 * @param string $consumerId The consumer id.
			 * @param integer|null $count The count.
			 */
			public function __construct(
				private readonly string $consumerId,
				private readonly ?int $count,
			) {
			}

			/**
			 * @return string The consumer id.
			 */
			public function getConsumerId(): string {
				return $this->consumerId;
			}

			/**
			 * @param string $userId The account id.
			 *
			 * @return integer|null The count.
			 */
			public function countOpenWork(string $userId): ?int {
				return $this->count;
			}
		};
	}//end consumer()

	/**
	 * A consumer that answers is named with its count.
	 *
	 * @return void
	 */
	public function testAnAnsweringConsumerIsNamedWithItsCount(): void {
		$reporter = $this->reporter();
		$reporter->registerConsumer($this->consumer(consumerId: 'dossiq', count: 3));

		$report = $reporter->report(userId: 'dana', expectedConsumers: ['dossiq']);

		$this->assertSame(['dossiq' => 3], $report);

	}//end testAnAnsweringConsumerIsNamedWithItsCount()

	/**
	 * A consumer answering over the event is named too.
	 *
	 * @return void
	 */
	public function testAConsumerAnsweringOverTheEventIsNamed(): void {
		$report = $this->reporter(eventAnswers: ['dossiq' => 2])->report(
			userId: 'dana',
			expectedConsumers: ['dossiq']
		);

		$this->assertSame(['dossiq' => 2], $report);

	}//end testAConsumerAnsweringOverTheEventIsNamed()

	/**
	 * An expected consumer that is absent reads unknown, and not zero.
	 *
	 * A confident zero about an app that never looked is exactly the instrument
	 * that lies, so this asserts the identity `unknown` rather than merely that
	 * the answer is not a count.
	 *
	 * @return void
	 */
	public function testAnAbsentConsumerReadsUnknownAndNotZero(): void {
		$report = $this->reporter()->report(userId: 'dana', expectedConsumers: ['dossiq']);

		$this->assertSame(OpenWorkReporter::UNKNOWN, $report['dossiq']);
		$this->assertNotSame(0, $report['dossiq']);

	}//end testAnAbsentConsumerReadsUnknownAndNotZero()

	/**
	 * A consumer that returns null cannot answer, so it reads unknown.
	 *
	 * @return void
	 */
	public function testAConsumerThatCannotSayReadsUnknown(): void {
		$reporter = $this->reporter();
		$reporter->registerConsumer($this->consumer(consumerId: 'dossiq', count: null));

		$report = $reporter->report(userId: 'dana', expectedConsumers: ['dossiq']);

		$this->assertSame(OpenWorkReporter::UNKNOWN, $report['dossiq']);

	}//end testAConsumerThatCannotSayReadsUnknown()

	/**
	 * A consumer that throws did not answer, so it reads unknown too.
	 *
	 * @return void
	 */
	public function testAConsumerThatThrowsReadsUnknown(): void {
		$throwing = new class implements IOpenWorkConsumer {

			/**
			 * @return string The consumer id.
			 */
			public function getConsumerId(): string {
				return 'dossiq';
			}

			/**
			 * @param string $userId The account id.
			 *
			 * @return integer|null Never returns.
			 */
			public function countOpenWork(string $userId): ?int {
				throw new RuntimeException('dossiq is not reachable');
			}
		};

		$reporter = $this->reporter();
		$reporter->registerConsumer($throwing);

		$report = $reporter->report(userId: 'dana', expectedConsumers: ['dossiq']);

		$this->assertSame(OpenWorkReporter::UNKNOWN, $report['dossiq']);

	}//end testAConsumerThatThrowsReadsUnknown()
}//end class
