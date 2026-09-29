<?php

/**
 * Tests for RosterDeliveryService and RosterImportRequestedListener, over the
 * real adapter, presets and mapper and a verbatim copy of planninq's event.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Roster;

require_once __DIR__ . '/../../../stubs/planninq/TimetableUpsertRequestedEvent.php';

use OCA\Integriq\Adapters\Roster\RosterImportClient;
use OCA\Integriq\Adapters\Roster\RosterImportClientMock;
use OCA\Integriq\Event\RosterImportRequestedEvent;
use OCA\Integriq\EventListener\RosterImportRequestedListener;
use OCA\Integriq\Sources\Roster\PlanninqTimetableTarget;
use OCA\Integriq\Sources\Roster\RosterDeliveryService;
use OCA\Integriq\Sources\Roster\RosterImportSourceAdapter;
use OCA\Integriq\Sources\Roster\RosterMappingPresetRegistry;
use OCA\Integriq\Sources\Roster\RosterSessionMapper;
use OCA\Integriq\Sources\Roster\RosterTargetConfiguration;
use OCA\Planninq\Event\TimetableUpsertRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One delivery end to end, and learniq's event always answered.
 */
class RosterDeliveryServiceTest extends TestCase {
	/**
	 * Sessions planninq received, by dispatch.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private array $delivered = [];

	/**
	 * Build the delivery service.
	 *
	 * @param string                  $planninqMode `answer`, `silent` or `absent`.
	 * @param RosterImportClient|null $client       Client override.
	 *
	 * @return RosterDeliveryService
	 */
	private function service(string $planninqMode = 'answer', ?RosterImportClient $client = null): RosterDeliveryService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$logger = $this->createMock(LoggerInterface::class);
		$presets = new RosterMappingPresetRegistry();

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($planninqMode): void {
				if ($planninqMode !== 'answer' || ($event instanceof TimetableUpsertRequestedEvent) === false) {
					return;
				}

				$this->delivered[] = $event->getSessions();
				$event->setResult(
					[
						'contractVersion' => 1,
						'sourceSystem' => $event->getSourceSystem(),
						'processed' => count($event->getSessions()),
						'created' => count($event->getSessions()),
						'updated' => 0,
						'unchanged' => 0,
						'rejected' => [],
						'sessionIds' => [],
					]
				);
			}
		);

		$eventClass = PlanninqTimetableTarget::EVENT_CLASS;
		if ($planninqMode === 'absent') {
			$eventClass = 'OCA\\Planninq\\Event\\NoSuchEvent';
		}

		return new RosterDeliveryService(
			new RosterImportSourceAdapter(
				$config,
				$logger,
				($client ?? new RosterImportClientMock()),
				$presets,
				new RosterSessionMapper(),
				new RosterTargetConfiguration($config, $logger)
			),
			$presets,
			new PlanninqTimetableTarget($dispatcher, $eventClass),
			$logger
		);
	}//end service()

	/**
	 * Handle learniq's event with a listener over the given service.
	 *
	 * @param RosterDeliveryService $service  The delivery service.
	 * @param string                $systemId The source asked for.
	 *
	 * @return array<string,mixed> The event's result.
	 */
	private function ask(RosterDeliveryService $service, string $systemId): array {
		$event = new RosterImportRequestedEvent('learniq', $systemId, ['groupMap' => ['3a' => 'cohort-3a']], 'job-42');
		(new RosterImportRequestedListener($service, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertTrue($event->isHandled(), 'learniq is always answered');
		return $event->getResult();
	}//end ask()

	/**
	 * A learniq timetable-import job lands in planninq.
	 *
	 * @return void
	 */
	public function testLearniqRequestLandsInPlanninq(): void {
		$result = $this->ask($this->service(), 'roster-zermelo');

		$this->assertSame('delivered', $result['status']);
		$this->assertSame('planninq', $result['target']);
		$this->assertSame('mock', $result['flavour']);
		$this->assertFalse($result['active']);
		$this->assertSame(2, $result['fetched']);
		$this->assertSame(2, $result['planninq']['created']);
		$this->assertSame('cohort-3a', $this->delivered[0][0]['cohortId'], 'the delivery map reached planninq');
	}//end testLearniqRequestLandsInPlanninq()

	/**
	 * An unknown source is answered with unknown-source, and planninq sees nothing.
	 *
	 * @return void
	 */
	public function testUnknownSourceIsAnsweredNotDropped(): void {
		$result = $this->ask($this->service(), 'roster-unknown');

		$this->assertSame('failed', $result['status']);
		$this->assertSame('unknown-source', $result['errorCode']);
		$this->assertSame([], $this->delivered);
	}//end testUnknownSourceIsAnsweredNotDropped()

	/**
	 * Planninq absent or silent: planninq-absent, never delivered.
	 *
	 * @return void
	 */
	public function testMissingPlanninqIsAFailedDelivery(): void {
		$this->assertSame('planninq-absent', $this->ask($this->service('absent'), 'roster-xedule')['errorCode']);
		$this->assertSame('planninq-absent', $this->ask($this->service('silent'), 'roster-xedule')['errorCode']);
	}//end testMissingPlanninqIsAFailedDelivery()

	/**
	 * A client that throws is fetch-failed.
	 *
	 * @return void
	 */
	public function testThrowingClientIsFetchFailed(): void {
		$client = new class extends RosterImportClient {
			/**
			 * Flavour.
			 *
			 * @return string
			 */
			public function flavour(): string {
				return 'https';
			}

			/**
			 * Always fails.
			 *
			 * @param string $systemId Unused.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function fetchLessons(string $systemId): array {
				throw new RuntimeException('timeout');
			}
		};

		$result = $this->ask($this->service('answer', $client), 'roster-timeedit');

		$this->assertSame('fetch-failed', $result['errorCode']);
		$this->assertStringContainsString('timeout', $result['error']);
	}//end testThrowingClientIsFetchFailed()

	/**
	 * The listener ignores other events.
	 *
	 * @return void
	 */
	public function testListenerIgnoresOtherEvents(): void {
		$other = new TimetableUpsertRequestedEvent('x', 'roster-zermelo', []);
		(new RosterImportRequestedListener($this->service(), $this->createMock(LoggerInterface::class)))->handle($other);

		$this->assertFalse($other->isHandled());
	}//end testListenerIgnoresOtherEvents()

	/**
	 * The abstract client is bound to the mock and the listener is registered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-the-adapter-can-be-constructed-on-an-instance-req-006
	 */
	public function testApplicationBindsTheClientAndRegistersTheListener(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/AppInfo/Application.php');

		$this->assertMatchesRegularExpression(
			'/registerService\(\s*RosterImportClient::class,\s*static function \(\$c\) \{\s*return \$c->get\(RosterImportClientMock::class\);/',
			$source
		);
		$this->assertMatchesRegularExpression(
			'/registerEventListener\(\s*RosterImportRequestedEvent::class,\s*RosterImportRequestedListener::class\s*\)/',
			$source
		);
	}//end testApplicationBindsTheClientAndRegistersTheListener()
}//end class
