<?php

/**
 * Characterisation test for the source and letter reads of BerichtenboxHealth.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DigitalPost
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

namespace OCA\Integriq\Tests\Unit\Service\DigitalPost;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\DigitalPost\BerichtenboxHealth;
use OCA\Integriq\Service\DigitalPost\BerichtenboxProvider;
use OCA\Integriq\Service\DigitalPost\DigitalPostResult;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Golden record of the health reads.
 */
class BerichtenboxHealthGoldenTest extends TestCase {

	/**
	 * A health check over the given answers, built without its constructor.
	 *
	 * @param mixed $answer What findAll answers; `throw` throws.
	 *
	 * @return array{0: BerichtenboxHealth, 1: \ArrayObject}
	 */
	private function health(mixed $answer): array {
		$calls = new \ArrayObject();
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (...$args) use ($answer, $calls): array {
				$calls[] = $args;
				if ($answer === 'throw') {
					throw new RuntimeException('down');
				}

				return $answer;
			}
		);
		$store = $this->createMock(ConnectionStore::class);
		$store->method('readSourceRaw')->willReturnCallback(
			static function (ObjectEntity $source) use ($calls): ObjectEntity {
				$calls[] = ['readSourceRaw' => $source->getUuid()];
				$raw = new ObjectEntity();
				$data = $source->getObject();
				$raw->setObject(($data['rawShape'] ?? null) === 'none' ? [] : ['configuration' => ['raw' => true] + (array)($data['configuration'] ?? [])]);
				return $raw;
			}
		);

		$health = (new ReflectionClass(BerichtenboxHealth::class))->newInstanceWithoutConstructor();
		(function (OrObjectService $objects, ConnectionStore $store): void {
			$this->objectService = $objects;
			$this->connectionStore = $store;
		})->call($health, $objects, $store);

		return [$health, $calls];
	}//end health()

	/**
	 * The recorded reads.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$entity = static function (array $data, string $uuid = 'u'): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setObject($data);
			return $object;
		};
		$now = new DateTimeImmutable('@1800000000');
		$old = gmdate('c', 1800000000 - 25 * 3600);
		$recent = gmdate('c', 1800000000 - 23 * 3600);
		$bb = BerichtenboxProvider::ID;
		$sent = DigitalPostResult::STATUS_SENT;
		$letters = ['results' => [
			$entity(['providerId' => $bb, 'status' => $sent, 'created' => $old]),
			$entity(['providerId' => $bb, 'status' => $sent, 'created' => $recent]),
			$entity(['providerId' => $bb, 'status' => $sent, 'created' => $old, 'simulated' => true]),
			$entity(['providerId' => $bb, 'status' => $sent, 'created' => $old, 'simulated' => 'yes']),
			$entity(['providerId' => 'other', 'status' => $sent, 'created' => $old]),
			$entity(['providerId' => $bb, 'status' => 'delivered', 'created' => $old]),
			$entity(['providerId' => $bb, 'status' => $sent, 'created' => 'not a date']),
			$entity(['providerId' => $bb, 'status' => $sent]),
			['providerId' => $bb, 'status' => $sent, 'created' => $old],
		]];
		$sources = ['results' => [
			$entity(['slug' => 'bb-1', 'configuration' => ['providerId' => $bb, 'senderOin' => '1']], 'u-1'),
			$entity(['configuration' => ['providerId' => $bb]], 'u-2'),
			$entity(['slug' => 'bb-3', 'configuration' => ['providerId' => $bb], 'rawShape' => 'none'], 'u-3'),
			$entity(['slug' => 'other', 'configuration' => ['providerId' => 'mijnoverheid']], 'u-4'),
			$entity(['slug' => 'text', 'configuration' => 'json'], 'u-5'),
			['slug' => 'plain', 'configuration' => ['providerId' => $bb]],
		]];

		$record = [];
		foreach (['letters' => $letters, 'letters plain list' => $letters['results'], 'letters throw' => 'throw'] as $name => $answer) {
			[$health, $calls] = $this->health(answer: $answer);
			$waiting = (new \ReflectionMethod(BerichtenboxHealth::class, 'lettersWaiting'))->invoke($health, $now);
			$record[$name] = ['waiting' => $waiting, 'calls' => $calls->getArrayCopy()];
		}

		foreach (['sources' => $sources, 'sources plain list' => $sources['results'], 'sources throw' => 'throw'] as $name => $answer) {
			[$health, $calls] = $this->health(answer: $answer);
			$found = (new \ReflectionMethod(BerichtenboxHealth::class, 'sources'))->invoke($health);
			$record[$name] = ['sources' => $found, 'calls' => $calls->getArrayCopy()];
		}

		$path = __DIR__ . '/../../../fixtures/berichtenbox/health-reads.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
