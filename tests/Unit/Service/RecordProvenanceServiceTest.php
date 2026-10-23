<?php

/**
 * Unit tests for RecordProvenanceService: where a record was synchronized from.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/record-provenance-tab/specs/synchronization/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\RecordProvenanceService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The contract that targets a record, its synchronization and source, and its newest log entries.
 *
 * @spec openspec/changes/record-provenance-tab/specs/synchronization/spec.md#requirement-a-record-says-where-it-was-synchronized-from
 */
final class RecordProvenanceServiceTest extends TestCase {

	/**
	 * Objects per schema.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $store = [];

	/**
	 * Build the service over the fake store.
	 *
	 * @return RecordProvenanceService
	 */
	private function makeService(): RecordProvenanceService {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (string|int $id, $register = null, $schema = null): ?ObjectEntity {
				foreach (($this->store[$schema] ?? []) as $entity) {
					if ($entity->getUuid() === $id) {
						return $entity;
					}
				}

				return null;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$rows = [];
				foreach (($this->store[$filters['schema']] ?? []) as $entity) {
					$body = $entity->getObject();
					$match = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === false && ($body[$key] ?? null) !== $value) {
							$match = false;
						}
					}

					if ($match === true) {
						$rows[] = $entity;
					}
				}

				return array_slice($rows, 0, (int)($config['limit'] ?? 1000));
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new RecordProvenanceService($objects, $l10n);
	}//end makeService()

	/**
	 * A case written by a synchronization names its source, origin id and five newest log entries.
	 *
	 * @return void
	 */
	public function testASynchronizedRecordNamesItsSource(): void {
		$this->store['source'] = [ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Zaaksysteem Centric (StUF-ZKN)'], 'src-1')];
		$this->store['synchronization'] = [ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Zaken naar register', 'sourceId' => 'src-1'], 'sync-1')];
		$this->store['synchronization_contract'] = [
			ObjectServiceMockBuilder::objectEntity($this, ['targetId' => 'case-82', 'originId' => 'ZAAK-2026-0082', 'synchronization' => 'sync-1', 'targetLastSynced' => '2026-10-23T10:30:00+02:00'], 'contract-1'),
		];
		$logs = [];
		for ($i = 0; $i < 14; $i++) {
			$logs[] = ObjectServiceMockBuilder::objectEntity($this, ['synchronization_contract' => 'contract-1', 'targetResult' => 'updated', 'created' => '2026-10-' . sprintf('%02d', 23 - $i) . 'T10:30:00+02:00'], 'log-' . $i);
		}

		$this->store['synchronization_contract_log'] = $logs;

		$answer = $this->makeService()->provenance(recordId: 'case-82');

		$this->assertTrue($answer['synced']);
		$this->assertSame('Zaaksysteem Centric (StUF-ZKN), via Integriq', $answer['source']);
		$this->assertSame('ZAAK-2026-0082', $answer['sourceRecord']);
		$this->assertSame('2026-10-23T10:30:00+02:00', $answer['lastSynced']);
		$this->assertSame(['id' => 'sync-1', 'name' => 'Zaken naar register'], $answer['synchronization']);
		$this->assertCount(5, $answer['recent']);
		$this->assertSame('updated', $answer['recent'][0]['result']);
		$this->assertSame(14, $answer['logTotal']);
		$this->assertSame('5 of 14 · full log in Integriq', $answer['logSummary']);
	}//end testASynchronizedRecordNamesItsSource()

	/**
	 * A record no contract targets says so.
	 *
	 * @return void
	 */
	public function testARecordNoSynchronizationWroteIsNotSynced(): void {
		$this->assertSame(['synced' => false, 'none' => true], $this->makeService()->provenance(recordId: 'case-1'));
	}//end testARecordNoSynchronizationWroteIsNotSynced()
}//end class
