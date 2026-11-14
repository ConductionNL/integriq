<?php

/**
 * Unit tests for the Digipoort entry in the connector catalogue.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/tasks.md#task-4-catalogue-item-log-page-and-docs
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CatalogRegistryService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The catalogue lists Digipoort under Tax filing, backed by the dormant seeded source.
 *
 * @covers \OCA\Integriq\Service\CatalogRegistryService
 */
class CatalogDigipoortEntryTest extends TestCase {
	/**
	 * The service over the shipped fragments.
	 *
	 * @return CatalogRegistryService
	 */
	private function service(): CatalogRegistryService {
		return new CatalogRegistryService(
			new IntegrationRegistry(new NullLogger()),
			$this->createMock(OrObjectService::class),
			$this->createMock(IAppConfig::class),
			new NullLogger()
		);
	}//end service()

	/**
	 * The adapter entry is listed once, under Tax filing, with its standards.
	 *
	 * @return void
	 */
	public function testDigipoortIsListedUnderTaxFiling(): void {
		$entries = array_values(
			array_filter(
				$this->service()->collect(),
				static fn (array $entry): bool => str_contains((string)json_encode($entry), 'digipoort')
			)
		);

		$this->assertCount(1, $entries, 'The seeded source is not listed a second time as a source template.');
		$this->assertSame('adapter:digipoort-sbr', $entries[0]['slug']);
		$this->assertSame('Tax filing', $entries[0]['category']);
		$this->assertSame('mock-seeded', $entries[0]['mechanism']);
		$this->assertSame('digipoort-sbr', $entries[0]['sourceTemplateSlug']);
		$this->assertSame(['Digipoort WUS', 'SBR', 'PKIoverheid'], $entries[0]['standards']);
	}//end testDigipoortIsListedUnderTaxFiling()

	/**
	 * The entry's seed is the dormant source on the log provider, with no certificate.
	 *
	 * @return void
	 */
	public function testTheSeedIsADormantLogSource(): void {
		$seed = $this->service()->findSeedSourcePayload('digipoort-sbr');

		$this->assertIsArray($seed);
		$this->assertSame('digipoort-sbr', $seed['type']);
		$this->assertFalse($seed['isEnabled']);
		$this->assertSame('log', $seed['configuration']['provider']);
		$this->assertSame('preproduction', $seed['configuration']['environment']);
		$this->assertSame('', $seed['configuration']['certificateRef']);
		$this->assertSame('Omzetbelasting', $seed['configuration']['berichtsoorten']['ob-aangifte']);
		$this->assertNotSame('', $seed['configuration']['endpoints']['production']['aanleverUrl']);
	}//end testTheSeedIsADormantLogSource()
}//end class
