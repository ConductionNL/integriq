<?php

/**
 * Characterisation test for RosterDeliveryService::deliver.
 *
 * Pins the success result, the delivery log line and both refusals.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Roster;

use OCA\Integriq\Adapters\Roster\RosterImportClient;
use OCA\Integriq\Adapters\Roster\RosterImportClientMock;
use OCA\Integriq\Sources\Roster\PlanninqTimetableTarget;
use OCA\Integriq\Sources\Roster\RosterDeliveryException;
use OCA\Integriq\Sources\Roster\RosterDeliveryService;
use OCA\Integriq\Sources\Roster\RosterImportSourceAdapter;
use OCA\Integriq\Sources\Roster\RosterMappingPresetRegistry;
use OCA\Integriq\Sources\Roster\RosterSessionMapper;
use OCA\Integriq\Sources\Roster\RosterTargetConfiguration;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden record of the delivery outcomes.
 */
class RosterDeliveryServiceGoldenTest extends TestCase {

	/**
	 * Everything recorded, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Build the service.
	 *
	 * @param RosterImportClient  $client   The client.
	 * @param array<string,mixed> $planninq What planninq answers.
	 *
	 * @return RosterDeliveryService
	 */
	private function service(RosterImportClient $client, array $planninq): RosterDeliveryService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$quiet = $this->createMock(LoggerInterface::class);
		$presets = new RosterMappingPresetRegistry();
		$target = $this->createMock(PlanninqTimetableTarget::class);
		$target->method('deliver')->willReturnCallback(
			function (string $systemId, array $sessions, string $correlationId) use ($planninq): array {
				$this->log[] = ['target' => [$systemId, count($sessions), $correlationId]];
				return $planninq;
			}
		);

		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<array<string, mixed>> $log The shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};

		$adapter = new RosterImportSourceAdapter(
			$config,
			$quiet,
			$client,
			$presets,
			new RosterSessionMapper(),
			new RosterTargetConfiguration($config, $quiet)
		);
		return new RosterDeliveryService($adapter, $presets, $target, $logger);
	}//end service()

	/**
	 * Record one delivery or its refusal.
	 *
	 * @param RosterDeliveryService $service  The service.
	 * @param string                $systemId The source.
	 *
	 * @return void
	 */
	private function record(RosterDeliveryService $service, string $systemId): void {
		try {
			$this->log[] = ['result' => $service->deliver($systemId, ['groupMap' => ['3a' => 'cohort-3a']], 'job-42')];
		} catch (RosterDeliveryException $e) {
			$previous = $e->getPrevious();
			$this->log[] = ['refused' => [$e->getErrorCode(), $e->getMessage(), ($previous === null ? null : $previous::class)]];
		}
	}//end record()

	/**
	 * The recorded outcomes.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$answer = ['created' => 1, 'updated' => 1, 'rejected' => [['id' => 'x']]];
		$this->record($this->service(new RosterImportClientMock(), $answer), 'roster-xedule');
		$this->record($this->service(new RosterImportClientMock(), ['status' => 'odd']), 'roster-xedule');
		$this->record($this->service(new RosterImportClientMock(), $answer), 'no-such-source');

		$failing = new class extends RosterImportClient {
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
		$this->record($this->service($failing, $answer), 'roster-timeedit');

		$path = __DIR__ . '/../../../fixtures/roster/roster-delivery.json';
		$actual = json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
