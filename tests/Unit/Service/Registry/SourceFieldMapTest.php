<?php

/**
 * Integriq — source field maps per target schema.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registry;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\Registry\BrpVolgindicatieProvider;
use OCA\Integriq\Service\Registry\KvkMutatieProvider;
use OCA\Integriq\Service\Registry\MapsSourceFieldsInterface;
use OCA\Integriq\Service\Registry\SubscriptionChange;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Decision 178: integriq translates the source's field names into each target
 * schema's own property names, in its providers.
 *
 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
 */
class SourceFieldMapTest extends TestCase {
	/**
	 * The BRP binding, built without touching its source.
	 *
	 * @return BrpVolgindicatieProvider The binding.
	 */
	private function brp(): BrpVolgindicatieProvider {
		return new BrpVolgindicatieProvider(
			$this->createMock(ConnectionStore::class),
			$this->createMock(CallService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end brp()

	/**
	 * The KvK binding, built without touching its source.
	 *
	 * @return KvkMutatieProvider The binding.
	 */
	private function kvk(): KvkMutatieProvider {
		return new KvkMutatieProvider(
			$this->createMock(ConnectionStore::class),
			$this->createMock(CallService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end kvk()

	/**
	 * Both registry bindings declare maps.
	 *
	 * @return void
	 */
	public function testTheBrpAndKvkBindingsDeclareMaps(): void {
		$this->assertInstanceOf(MapsSourceFieldsInterface::class, $this->brp());
		$this->assertInstanceOf(MapsSourceFieldsInterface::class, $this->kvk());
	}//end testTheBrpAndKvkBindingsDeclareMaps()

	/**
	 * The dossiq person schema takes name, birth, residence and the secrecy flag.
	 *
	 * @return void
	 */
	public function testTheBrpMapForDossiqsPersonSchema(): void {
		$this->assertSame(
			[
				'naam' => 'name',
				'geboorte' => 'birth',
				'verblijfplaats' => 'residence',
				'geheimhoudingPersoonsgegevens' => 'indicatieGeheim',
			],
			$this->brp()->fieldMapFor('brpPerson')
		);
	}//end testTheBrpMapForDossiqsPersonSchema()

	/**
	 * The dossiq company schema takes trade name, legal form and address.
	 *
	 * @return void
	 */
	public function testTheKvkMapForDossiqsCompanySchema(): void {
		$map = $this->kvk()->fieldMapFor('kvkCompany');

		$this->assertNotNull($map);
		$this->assertSame('tradeName', $map['handelsnaam']);
		$this->assertSame('tradeName', $map['naam']);
		$this->assertSame('legalForm', $map['rechtsvorm']);
		$this->assertSame('address', $map['adres']);
		$this->assertSame('address', $map['bezoekadres']);
	}//end testTheKvkMapForDossiqsCompanySchema()

	/**
	 * A schema the binding does not know has no map, which means: unchanged.
	 *
	 * @return void
	 */
	public function testAnUnknownTargetSchemaHasNoMap(): void {
		$this->assertNull($this->brp()->fieldMapFor('kvkCompany'));
		$this->assertNull($this->kvk()->fieldMapFor('somethingElse'));
	}//end testAnUnknownTargetSchemaHasNoMap()

	/**
	 * A mapped change carries the schema's names and drops what the map does
	 * not list.
	 *
	 * @return void
	 */
	public function testAMappedChangeRenamesAndDropsUnlistedFields(): void {
		$change = new SubscriptionChange(
			'999993653',
			[
				'naam' => ['geslachtsnaam' => 'Jansen'],
				'verblijfplaats' => ['straat' => 'Nieuwstraat'],
				'aNummer' => '1234567890',
			],
			'vi-42'
		);

		$mapped = $change->mappedTo($this->brp()->fieldMapFor('brpPerson') ?? []);

		$this->assertSame(
			[
				'identity' => '999993653',
				'properties' => [
					'name' => ['geslachtsnaam' => 'Jansen'],
					'residence' => ['straat' => 'Nieuwstraat'],
				],
				'eventReference' => 'vi-42',
			],
			$mapped->toArray()
		);
		// The original is left alone: the next target maps from the source names.
		$this->assertArrayHasKey('naam', $change->getProperties());
	}//end testAMappedChangeRenamesAndDropsUnlistedFields()

	/**
	 * A change that keeps nothing after mapping is empty, so it posts nothing.
	 *
	 * @return void
	 */
	public function testAChangeThatKeepsNoFieldIsEmpty(): void {
		$change = new SubscriptionChange('999993653', ['aNummer' => '1234567890'], 'vi-43');

		$this->assertTrue($change->mappedTo(['naam' => 'name'])->isEmpty());
	}//end testAChangeThatKeepsNoFieldIsEmpty()
}//end class
