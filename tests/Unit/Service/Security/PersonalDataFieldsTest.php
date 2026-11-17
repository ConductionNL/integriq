<?php

/**
 * Unit tests for PersonalDataFields.
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
use OCA\Integriq\Service\Security\PersonalDataFields;
use OCA\Integriq\Service\Security\PersonalDataMasker;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The entry the outbound log and the settings page use answers as the list behind it.
 */
#[CoversClass(PersonalDataFields::class)]
#[UsesClass(PersonalDataFieldList::class)]
#[UsesClass(PersonalDataMasker::class)]
class PersonalDataFieldsTest extends TestCase {

	/**
	 * The constants and the defaults are the list's and the masker's.
	 *
	 * @return void
	 */
	public function testItSharesTheListsNamesAndPlaceholder(): void {
		$this->assertSame('[afgeschermd]', PersonalDataFields::PLACEHOLDER);
		$this->assertSame(PersonalDataFieldList::CONFIG_KEY, PersonalDataFields::CONFIG_KEY);
		$this->assertSame(PersonalDataFieldList::DEFAULT_FIELDS, PersonalDataFields::defaults());
		$this->assertSame('bsn', PersonalDataFields::normalise(name: 'inp:B-S_N'));
	}//end testItSharesTheListsNamesAndPlaceholder()

	/**
	 * Without an app config only the defaults mask, and nothing is added.
	 *
	 * @return void
	 */
	public function testWithoutAppConfigTheDefaultsMask(): void {
		$fields = new PersonalDataFields();

		$this->assertTrue($fields->isPersonalDataName(name: 'bsn'));
		$this->assertFalse($fields->isPersonalDataName(name: 'kenteken'));
		$this->assertSame([], $fields->addedPersonalDataFields());
		$this->assertSame('{"bsn":"[afgeschermd]","x":1}', $fields->maskPersonalData(value: '{"bsn":"123456782","x":1}'));
	}//end testWithoutAppConfigTheDefaultsMask()

	/**
	 * A name an administrator added is masked too.
	 *
	 * @return void
	 */
	public function testAnAddedNameIsMasked(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->with('integriq', PersonalDataFieldList::CONFIG_KEY, '[]')
			->willReturn('["kenteken"]');
		$fields = new PersonalDataFields(appConfig: $appConfig);

		$this->assertSame(['kenteken'], $fields->addedPersonalDataFields());
		$this->assertTrue($fields->isPersonalDataName(name: 'Kenteken'));
		$this->assertSame('{"kenteken":"[afgeschermd]"}', $fields->maskPersonalData(value: '{"kenteken":"AB-123-C"}'));
	}//end testAnAddedNameIsMasked()
}//end class
