<?php

/**
 * Characterisation tests for what MigrateStoredJobClasses reports.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateStoredJobClasses;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins every output line and error log of the step: no stale value, a
 * rewrite with failures, a failed enumeration, an unresolvable service.
 */
class MigrateStoredJobClassesReportTest extends TestCase {

	/**
	 * Every output and log line, as "channel: text".
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * Run the step over an object service (or a container that cannot
	 * resolve one) and return what it reported.
	 *
	 * @param OrObjectService|null $objects The object service, null for an unresolvable one.
	 *
	 * @return list<string>
	 */
	private function report(?OrObjectService $objects): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects) {
				if ($objects === null) {
					throw new \RuntimeException('no service');
				}

				return $objects;
			}
		);
		$lines = &$this->lines;
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array $lines The shared lines, by reference.
			 */
			public function __construct(private array &$lines) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = 'log.' . $level . ': ' . $message . ' ' . json_encode($context);
			}
		};
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $channel) {
			$output->method($channel)->willReturnCallback(
				function (string $text) use ($channel): void {
					$this->lines[] = $channel . ': ' . $text;
				}
			);
		}

		(new MigrateStoredJobClasses($container, $logger))->run($output);

		return $this->lines;

	}//end report()

	/**
	 * Nothing stale is reported as such.
	 *
	 * @return void
	 */
	public function testNothingStaleIsReported(): void {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturn(['results' => []]);

		$this->assertSame(['info: MigrateStoredJobClasses: no stored jobClass still names the openconnector namespace.'], $this->report($objects));

	}//end testNothingStaleIsReported()

	/**
	 * Rewrites and failures are counted; non-entities and current values
	 * count as neither; a failure is surfaced loudly.
	 *
	 * @return void
	 */
	public function testRewritesAndFailuresAreCounted(): void {
		$stale = fn (string $uuid): object => ObjectServiceMockBuilder::objectEntity($this, ['jobClass' => 'OCA\\OpenConnector\\Action\\PingAction'], $uuid);
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturn(
			[
				'results' => [
					$stale('a'),
					'not-an-entity',
					ObjectServiceMockBuilder::objectEntity($this, ['jobClass' => 'OCA\\Integriq\\Action\\PingAction'], 'current'),
					$stale('bad'),
					$stale('b'),
				],
			]
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null) {
				if ($uuid === 'bad') {
					throw new \RuntimeException('write rejected');
				}

				return ObjectServiceMockBuilder::objectEntity($this, $object, (string)$uuid);
			}
		);

		$lines = array_values(array_filter($this->report($objects), static fn (string $line): bool => str_starts_with($line, 'log.') === false));

		$this->assertSame(
			[
				'info: MigrateStoredJobClasses: rewrote 2 stored jobClass value(s).',
				'warning: MigrateStoredJobClasses: 1 job(s) could NOT be rewritten and still name the openconnector namespace. Those jobs will not run until their jobClass is corrected.',
			],
			$lines
		);

	}//end testRewritesAndFailuresAreCounted()

	/**
	 * A failed enumeration stops with a warning and an error log.
	 *
	 * @return void
	 */
	public function testAFailedEnumerationIsReported(): void {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willThrowException(new \RuntimeException('db down'));

		$this->assertSame(
			[
				'warning: MigrateStoredJobClasses: could not enumerate stored jobs at offset 0; stopping. Some jobClass values may still name the openconnector namespace.',
				'log.error: Integriq: MigrateStoredJobClasses enumeration failed {"offset":0,"exception":"db down"}',
				'info: MigrateStoredJobClasses: no stored jobClass still names the openconnector namespace.',
			],
			$this->report($objects)
		);

	}//end testAFailedEnumerationIsReported()

	/**
	 * An object service that cannot be resolved migrates nothing, loudly.
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceIsReported(): void {
		$this->assertSame(
			[
				'warning: MigrateStoredJobClasses: could not resolve the OpenRegister object service; stored jobClass values were NOT migrated.',
				'log.error: Integriq: MigrateStoredJobClasses service resolution failed {"exception":"no service"}',
			],
			$this->report(null)
		);

	}//end testAnUnresolvableServiceIsReported()

}//end class
