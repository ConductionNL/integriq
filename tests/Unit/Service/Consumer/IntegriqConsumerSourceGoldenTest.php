<?php

/**
 * Characterisation test for IntegriqConsumerSource.
 *
 * Pins the allowed and refused consumer lookups: which consumer an issuer or
 * an API key resolves to, every field it resolves with, and every key that is
 * refused.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
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

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Service\Consumer\IntegriqConsumerSource;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the consumer lookups.
 */
class IntegriqConsumerSourceGoldenTest extends TestCase {

	/**
	 * A resolved consumer as plain data.
	 *
	 * @param ResolvedConsumer|null $consumer The consumer.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function plain(?ResolvedConsumer $consumer): ?array {
		if ($consumer === null) {
			return null;
		}

		$fields = get_object_vars($consumer);
		foreach ($fields as $key => $value) {
			if ($value instanceof ObjectEntity) {
				$fields[$key] = 'entity:' . $value->getUuid();
			}
		}

		return $fields;
	}//end plain()

	/**
	 * The recorded lookups.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$entity = static function (?string $uuid, array $data): ObjectEntity {
			$object = new ObjectEntity();
			if ($uuid !== null) {
				$object->setUuid($uuid);
			}

			$object->setObject($data);
			return $object;
		};
		$all = [
			$entity('c-jwt', ['name' => 'zaaksysteem', 'authorizationType' => 'jwt', 'authorizationConfiguration' => ['algorithm' => 'HS256'], 'userId' => 'svc-zs']),
			$entity('c-key', ['name' => 'portal', 'authorizationType' => 'ApiKey', 'authorizationConfiguration' => ['apiKey' => 'k-123'], 'userId' => 'svc-portal']),
			$entity('c-key2', ['name' => 'other', 'authorizationType' => 'apikey', 'authorizationConfiguration' => ['apiKey' => 'k-456'], 'userId' => 7]),
			$entity('c-blank', ['name' => 'blank', 'authorizationType' => 'apikey', 'authorizationConfiguration' => ['apiKey' => '']]),
			$entity('c-int', ['name' => 'int', 'authorizationType' => 'apikey', 'authorizationConfiguration' => ['apiKey' => 123]]),
			$entity('c-wrongtype', ['name' => 'wrong', 'authorizationType' => 'basic', 'authorizationConfiguration' => ['apiKey' => 'k-789']]),
			$entity(null, ['uuid' => 'data-uuid', 'name' => 'no entity uuid', 'authorizationType' => 'apikey', 'authorizationConfiguration' => ['apiKey' => 'k-000']]),
			$entity('c-bad-config', ['name' => 'badconfig', 'authorizationConfiguration' => 'x', 'authorizationType' => 5]),
			'not an entity',
		];
		$calls = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = [], ...$rest) use (&$calls, $all): array {
				$calls[] = [$config, $rest];
				$name = ($config['filters']['name'] ?? null);
				if ($name === null) {
					return ['results' => $all];
				}

				return array_values(array_filter($all, static fn ($e): bool => $e instanceof ObjectEntity && ($e->getObject()['name'] ?? null) === $name));
			}
		);
		$source = new IntegriqConsumerSource($objects);

		$record = ['issuer' => [], 'apiKey' => []];
		foreach (['zaaksysteem', 'portal', 'badconfig', 'no entity uuid', 'nobody'] as $issuer) {
			$calls = [];
			$record['issuer'][$issuer] = ['consumer' => self::plain($source->findByIssuer($issuer)), 'calls' => $calls];
		}

		foreach (['k-123', 'k-456', 'k-000', '', 'k-789', '123', 'K-123', 'k-12', 'nope'] as $key) {
			$calls = [];
			$record['apiKey'][$key === '' ? '(empty)' : $key] = ['consumer' => self::plain($source->findByApiKey($key)), 'reads' => count($calls)];
		}

		$path = __DIR__ . '/../../../fixtures/consumer/integriq-consumer-source.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
