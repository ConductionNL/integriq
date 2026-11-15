<?php

/**
 * Characterisation test for SyncRefResolver::resolve.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Helper
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

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\SyncRefResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden record of the synchronisation reference resolution.
 */
class SyncRefResolverGoldenTest extends TestCase {

	/**
	 * The recorded resolutions.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$log = [];
		$entity = static function (string $uuid): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			return $object;
		};
		$answers = [
			1 => ['results' => [$entity('11111111-1111-1111-1111-111111111111'), $entity('x')]],
			2 => [$entity('')],
			3 => [['uuid' => 'row-uuid']],
			4 => [['uuid' => '']],
			5 => [['uuid' => 5]],
			6 => [['id' => 6]],
			7 => [],
			8 => 'throw',
			9 => ['results' => 'not a list'],
			10 => ['results' => ['a string row']],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$log, $answers): mixed {
				$log[] = ['findAll' => $config];
				$answer = ($answers[$config['filters']['id']] ?? []);
				if ($answer === 'throw') {
					throw new RuntimeException('store down');
				}

				return $answer;
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
				$this->log[] = ['log' => [$level, (string)$message]];
			}
		};
		$resolver = new SyncRefResolver($objects, $logger);

		$record = [];
		$values = ['', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '007', 'integriq/source', 'a-b_c/d', 'a/b/c',
			'0F8FAD5B-D9CB-469F-A165-70867728950E', '0f8fad5b-d9cb-469f-a165-70867728950', 'een naam', '-1', ' 1'];
		foreach ($values as $value) {
			$log = [];
			$record[] = ['value' => $value, 'resolved' => $resolver->resolve($value), 'calls' => $log];
		}

		$path = __DIR__ . '/../../../fixtures/helper/sync-ref-resolver.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
