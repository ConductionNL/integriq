<?php

/**
 * Unit tests for PersonalDataFieldList.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\PersonalDataFieldList;
use OCA\Integriq\Service\Security\PersonalDataMasker;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The personal data field list: the default names, an administrator's additions, masking over both.
 */
#[CoversClass(PersonalDataFieldList::class)]
#[UsesClass(PersonalDataMasker::class)]
class PersonalDataFieldListTest extends TestCase {

	/**
	 * @var PersonalDataFieldList
	 */
	private PersonalDataFieldList $list;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->list = new PersonalDataFieldList();
	}//end setUp()

	/**
	 * The default personal data list covers BSN, name, address, date of birth, e-mail and telephone.
	 *
	 * @return void
	 */
	public function testTheDefaultPersonalDataListCoversTheRequiredKinds(): void {
		foreach (['inp.bsn', 'BSN', 'geslachtsnaam', 'voornamen', 'lastName', 'adres', 'straatnaam', 'postcode', 'geboortedatum', 'dateOfBirth', 'e-mail', 'emailadres', 'telefoonnummer', 'phone'] as $name) {
			$this->assertTrue($this->list->isPersonalDataName(name: $name), $name);
		}

		foreach (['zaaktype', 'omschrijving', 'name', 'status', 'kenteken'] as $name) {
			$this->assertFalse($this->list->isPersonalDataName(name: $name), $name);
		}
	}//end testTheDefaultPersonalDataListCoversTheRequiredKinds()

	/**
	 * A JSON and an XML body with a BSN and a name are both masked.
	 *
	 * @return void
	 */
	public function testPersonalDataIsMaskedInJsonAndXml(): void {
		$this->assertSame(
			'{"bsn":"[afgeschermd]","geslachtsnaam":"[afgeschermd]","zaaktype":"z1"}',
			$this->list->maskPersonalData(value: '{"bsn":"999993653","geslachtsnaam":"Jansen","zaaktype":"z1"}')
		);
		$this->assertSame(
			'<BG:inp.bsn>[afgeschermd]</BG:inp.bsn><BG:geslachtsnaam>[afgeschermd]</BG:geslachtsnaam>',
			$this->list->maskPersonalData(value: '<BG:inp.bsn>999993653</BG:inp.bsn><BG:geslachtsnaam>Jansen</BG:geslachtsnaam>')
		);
	}//end testPersonalDataIsMaskedInJsonAndXml()

	/**
	 * Without app config only the default list applies.
	 *
	 * @return void
	 */
	public function testWithoutAppConfigNothingIsAdded(): void {
		$this->assertSame([], $this->list->addedPersonalDataFields());
		$this->assertSame(PersonalDataFieldList::DEFAULT_FIELDS, $this->list->personalDataFieldNames());
	}//end testWithoutAppConfigNothingIsAdded()

	/**
	 * A field an administrator added is masked, next to the default list.
	 *
	 * @return void
	 */
	public function testAnAddedFieldIsMasked(): void {
		$list = new PersonalDataFieldList(appConfig: $this->appConfigHolding(value: '["kenteken", " ", 7, "kenteken", "bsn"]'));

		$this->assertSame(['kenteken', 'bsn'], $list->addedPersonalDataFields());
		$this->assertContains('kenteken', $list->personalDataFieldNames());
		$this->assertSame(count(PersonalDataFieldList::DEFAULT_FIELDS) + 1, count($list->personalDataFieldNames()));
		$this->assertSame('{"kenteken":"[afgeschermd]"}', $list->maskPersonalData(value: '{"kenteken":"AB-123-C"}'));
	}//end testAnAddedFieldIsMasked()

	/**
	 * A stored value that is not a JSON list adds nothing.
	 *
	 * @return void
	 */
	public function testAMalformedSettingAddsNothing(): void {
		$list = new PersonalDataFieldList(appConfig: $this->appConfigHolding(value: 'kenteken'));

		$this->assertSame([], $list->addedPersonalDataFields());
		$this->assertSame('{"kenteken":"x"}', $list->maskPersonalData(value: '{"kenteken":"x"}'));
	}//end testAMalformedSettingAddsNothing()

	/**
	 * An app config whose personal data setting holds the given value.
	 *
	 * @param string $value The stored value.
	 *
	 * @return IAppConfig The app config.
	 */
	private function appConfigHolding(string $value): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->with('integriq', PersonalDataFieldList::CONFIG_KEY, '[]')
			->willReturn($value);

		return $appConfig;
	}//end appConfigHolding()
}//end class
