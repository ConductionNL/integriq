<?php

/**
 * Characterisation test for CaseSystemOperations::handle.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\CaseSystem
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

namespace OCA\Integriq\Tests\Unit\Service\CaseSystem;

use OCA\Integriq\Service\CaseSystem\CaseSystemMock;
use OCA\Integriq\Service\CaseSystem\CaseSystemOperations;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\ZgwCaseSystem;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the case-system operations.
 */
class CaseSystemOperationsGoldenTest extends TestCase {

	/**
	 * The recorded answers.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$calls = [];
		$zgw = $this->createMock(ZgwCaseSystem::class);
		$zgw->method('run')->willReturnCallback(
			static function (string $operation, array $body, array $configuration, string $sourceName) use (&$calls): array {
				$calls[] = ['zgw' => [$operation, $body, $configuration, $sourceName]];
				if (($body['refuse'] ?? false) === true) {
					throw new CaseSystemRefusal(message: 'Zaak niet gevonden', status: 404);
				}

				return ['ok' => 'zgw', 'é' => 'ü/'];
			}
		);
		$mock = $this->createMock(CaseSystemMock::class);
		$mock->method('run')->willReturnCallback(
			static function (string $operation, array $body) use (&$calls): array {
				$calls[] = ['mock' => [$operation, $body]];
				return ['ok' => 'mock'];
			}
		);
		$operations = new CaseSystemOperations($zgw, $mock);
		$source = static function (array $data): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setObject($data);
			return $entity;
		};
		$zgwSource = $source(['name' => 'Zaken', 'configuration' => ['zrcUrl' => 'https://zrc.example.test']]);

		$cases = [
			'unknown operation' => [$zgwSource, '/case-system/delete-case', ['json' => []]],
			'no body' => [$zgwSource, 'read-case', []],
			'body not json' => [$zgwSource, 'read-case', ['body' => 'text']],
			'json body' => [$zgwSource, '/case-system/read-case?x=1', ['json' => ['id' => 'Z-1']]],
			'string body' => [$zgwSource, 'list-documents', ['body' => '{"id":"Z-1"}']],
			'refused' => [$zgwSource, 'read-document', ['json' => ['refuse' => true]]],
			'mock true' => [$source(['configuration' => ['mock' => true]]), 'create-case', ['json' => ['a' => 1]]],
			'mock text' => [$source(['configuration' => ['mock' => 'yes']]), 'add-document', ['json' => ['a' => 1]]],
			'mock false text' => [$source(['configuration' => ['mock' => 'false'], 'name' => 7]), 'add-document', ['json' => ['a' => 1]]],
			'configuration not an array' => [$source(['configuration' => 'x']), 'read-case', ['json' => []]],
		];
		$record = [];
		foreach ($cases as $name => [$src, $endpoint, $config]) {
			$calls = [];
			$response = $operations->handle($src, $endpoint, $config);
			$record[$name] = [
				'status' => $response->getStatusCode(),
				'headers' => $response->getHeaders(),
				'body' => (string)$response->getBody(),
				'calls' => $calls,
			];
		}

		$path = __DIR__ . '/../../../fixtures/case-system/operations.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
