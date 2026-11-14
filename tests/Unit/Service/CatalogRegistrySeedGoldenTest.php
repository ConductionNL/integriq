<?php

/**
 * Golden tests for CatalogRegistryService over the shipped seed fragments:
 * the collected catalog, every seed payload, and the status of every mechanism.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CatalogRegistryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pins the catalog and the seed lookups by digest, and the status matrix.
 */
class CatalogRegistrySeedGoldenTest extends TestCase {

	/**
	 * The service over the shipped fragments and templates.
	 *
	 * @param IAppConfig|null      $appConfig     The app config.
	 * @param OrObjectService|null $objectService The object service.
	 * @param LoggerInterface|null $logger        The logger.
	 *
	 * @return CatalogRegistryService
	 */
	private function service(?IAppConfig $appConfig = null, ?OrObjectService $objectService = null, ?LoggerInterface $logger = null): CatalogRegistryService {
		return new CatalogRegistryService(
			new IntegrationRegistry(new NullLogger()),
			$objectService ?? $this->createMock(OrObjectService::class),
			$appConfig ?? $this->createMock(IAppConfig::class),
			$logger ?? new NullLogger()
		);

	}//end service()

	/**
	 * The collected catalog, and no warning, are as before.
	 *
	 * @return void
	 */
	public function testCollectIsUnchanged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$entries = $this->service(logger: $logger)->collect();

		$this->assertSame('47cdce0c7ea9611b1f6abcce16fe98f6dded518859ac0fa861fb48c0fa09dd60', hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)));

	}//end testCollectIsUnchanged()

	/**
	 * Every seed payload, and the misses, are as before.
	 *
	 * @return void
	 */
	public function testSeedPayloadsAreUnchanged(): void {
		$service = $this->service();
		$payloads = [];
		foreach ($service->collect() as $entry) {
			$slug = (string)($entry['sourceTemplateSlug'] ?? '');
			if ($slug !== '') {
				$payloads[$slug] = $service->findSeedSourcePayload($slug);
			}
		}

		$payloads['no-such-seed'] = $service->findSeedSourcePayload('no-such-seed');
		$payloads[''] = $service->findSeedSourcePayload('');

		$this->assertNull($payloads['no-such-seed']);
		$this->assertSame('33cf09294b048fdef7732318b52743c8951eaa272fc0c025d001ee23f970162b', hash('sha256', json_encode($payloads, JSON_THROW_ON_ERROR)));

	}//end testSeedPayloadsAreUnchanged()

	/**
	 * Each entry, the configured flag value, the found source, and its status.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string,2:array<string,mixed>|null,3:string}>
	 */
	public static function statuses(): array {
		return [
			'flag without key' => [['mechanism' => 'flag-gated'], '1', null, 'dormant'],
			'flag on' => [['mechanism' => 'flag-gated', 'flagKey' => 'k'], '1', null, 'available'],
			'flag TRUE' => [['mechanism' => 'flag-gated', 'flagKey' => 'k'], 'TRUE', null, 'available'],
			'flag off' => [['mechanism' => 'flag-gated', 'flagKey' => 'k'], 'yes', null, 'dormant'],
			'seed without slug' => [['mechanism' => 'mock-seeded'], '', ['slug' => 's', 'isEnabled' => true], 'dormant'],
			'seed missing' => [['mechanism' => 'mock-seeded', 'sourceTemplateSlug' => 'other'], '', ['slug' => 's', 'isEnabled' => true], 'dormant'],
			'seed enabled' => [['mechanism' => 'mock-seeded', 'sourceTemplateSlug' => 's'], '', ['slug' => 's', 'isEnabled' => true], 'available'],
			'seed disabled' => [['mechanism' => 'mock-seeded', 'sourceTemplateSlug' => 's'], '', ['slug' => 's', 'isEnabled' => 1], 'dormant'],
			'fallback on' => [['_fallbackEnabled' => true], '', null, 'available'],
			'fallback off' => [['mechanism' => 'always-available', '_fallbackEnabled' => 'yes'], '', null, 'dormant'],
			'fallback null' => [['_fallbackEnabled' => null], '', null, 'dormant'],
			'always' => [[], '', null, 'available'],
			'unknown mechanism' => [['mechanism' => 'other', '_fallbackEnabled' => false], '', null, 'dormant'],
		];

	}//end statuses()

	/**
	 * Each mechanism resolves its status as before.
	 *
	 * @param array<string,mixed>      $entry    The entry.
	 * @param string                   $flag     The configured flag value.
	 * @param array<string,mixed>|null $source   The seeded source the object service holds, or null.
	 * @param string                   $expected The status.
	 *
	 * @return void
	 */
	#[DataProvider('statuses')]
	public function testStatusResolves(array $entry, string $flag, ?array $source, string $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($flag);
		$objectService = $this->createMock(OrObjectService::class);
		$results = [];
		if ($source !== null) {
			$entity = new ObjectEntity();
			$entity->setObject($source);
			$results = ['not an entity', $entity];
		}

		$objectService->method('findAll')->willReturn(['results' => $results]);

		$this->assertSame($expected, $this->service($appConfig, $objectService)->resolveStatus($entry));

	}//end testStatusResolves()
}//end class
