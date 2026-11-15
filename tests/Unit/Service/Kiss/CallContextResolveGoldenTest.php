<?php

/**
 * Characterisation test for CallContextService::resolve.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
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

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallContextService;
use OCA\Integriq\Service\Kiss\CallerDirectory;
use OCA\Integriq\Service\Kiss\CallerLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden record of the call context.
 */
class CallContextResolveGoldenTest extends TestCase {

	/**
	 * The recorded contexts.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$log = [];
		$lookup = $this->createMock(CallerLookup::class);
		$lookup->method('isUsable')->willReturnCallback(
			static function (string $e164) use (&$log): bool {
				$log[] = ['isUsable' => $e164];
				return str_starts_with($e164, '+');
			}
		);
		$lookup->method('match')->willReturnCallback(
			static function (string $e164, array $parties) use (&$log): ?array {
				$log[] = ['match' => [$e164, $parties]];
				return ($parties[0] ?? null);
			}
		);
		$directory = $this->createMock(CallerDirectory::class);
		$directory->method('candidatesFor')->willReturnCallback(
			static function (string $e164, array $sourceConfiguration = []) use (&$log): array {
				$log[] = ['candidatesFor' => [$e164, $sourceConfiguration]];
				if ($e164 === '+31600000001') {
					throw new RuntimeException('directory down');
				}

				return ($e164 === '+31600000002' ? [] : [['name' => 'Jan', 'number' => $e164]]);
			}
		);
		$directory->method('openCasesFor')->willReturnCallback(
			static function (array $party, array $sourceConfiguration = []) use (&$log): array {
				$log[] = ['openCasesFor' => [$party, $sourceConfiguration]];
				if ($party['number'] === '+31600000003') {
					throw new RuntimeException('cases down');
				}

				return ['Z-1', 'Z-2'];
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
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};
		$service = new CallContextService($lookup, $directory, $logger);

		$record = [];
		foreach (['unusable' => 'anonymous', 'directory down' => '+31600000001', 'no candidates' => '+31600000002', 'cases down' => '+31600000003', 'known' => '+31612345678'] as $name => $number) {
			$log = [];
			$record[$name] = ['context' => $service->resolve($number, ['kissUrl' => 'k']), 'calls' => $log];
		}

		$path = __DIR__ . '/../../../fixtures/kiss/call-context.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
