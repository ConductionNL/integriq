<?php

/**
 * Characterisation test for FetchFilesJob::run.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
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

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\FetchFilesJob;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden record of the deferred file fetch job.
 */
class FetchFilesJobGoldenTest extends TestCase {

	/**
	 * The recorded runs.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$log = [];
		$sync = $this->createMock(SynchronizationServiceInterface::class);
		$sync->method('fetchFilesForObject')->willReturnCallback(
			static function (array $config, mixed $endpoint, string $objectId, int $ruleId) use (&$log): mixed {
				$log[] = ['fetch' => [$config, $endpoint, $objectId, $ruleId]];
				if ($objectId === 'boom') {
					throw new RuntimeException('source down');
				}

				return [];
			}
		);
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<mixed> $log The shared log.
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
				$this->log[] = ['log' => [$level, (string)$message, array_keys($context)]];
			}
		};
		$job = new FetchFilesJob($this->createMock(ITimeFactory::class), $sync, $logger);
		$run = new ReflectionMethod(FetchFilesJob::class, 'run');

		$arguments = [
			'not an array' => 'x',
			'empty' => [],
			'config not an array' => ['config' => 'x', 'endpoint' => 'e', 'objectId' => 1],
			'no endpoint' => ['config' => [], 'objectId' => 1],
			'no object' => ['config' => [], 'endpoint' => 'e'],
			'complete' => ['config' => ['a' => 1], 'endpoint' => ['url' => 'https://x'], 'objectId' => 42, 'ruleId' => '7'],
			'no rule' => ['config' => [], 'endpoint' => '', 'objectId' => 'o-1'],
			'fetch throws' => ['config' => [], 'endpoint' => 'e', 'objectId' => 'boom'],
		];
		$record = [];
		foreach ($arguments as $name => $argument) {
			$log = [];
			$run->invoke($job, $argument);
			$record[$name] = $log;
		}

		$path = __DIR__ . '/../../../fixtures/synchronization/fetch-files-job.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
