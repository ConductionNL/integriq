<?php

/**
 * Unit tests for the learning material licence code.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\LicenseCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Licence codes translate to and from Edurep's codes and Creative Commons URLs.
 */
class LicenseCodeTest extends TestCase {
	/**
	 * @return array<string,array{string,LicenseCode|null}>
	 */
	public static function edurepValues(): array {
		return [
			'cc-by-40' => ['cc-by-40', LicenseCode::CC_BY_40],
			'cc-by-nc-nd-30' => ['cc-by-nc-nd-30', LicenseCode::CC_BY_NC_ND_30],
			'cc0-10' => ['cc0-10', LicenseCode::CC0],
			'pdm-10' => ['pdm-10', LicenseCode::PDM],
			'yes is no code' => ['yes', null],
			'no is no code' => ['no', null],
			'unversioned is no code' => ['cc-by-nd', null],
		];
	}//end edurepValues()

	/**
	 * @param string $value The Edurep value.
	 * @param LicenseCode|null $expected The code.
	 *
	 * @return void
	 */
	#[DataProvider('edurepValues')]
	public function testReadsEdurepValues(string $value, ?LicenseCode $expected): void {
		$this->assertSame($expected, LicenseCode::fromEdurep(value: $value));
	}//end testReadsEdurepValues()

	/**
	 * @return void
	 */
	public function testReadsCreativeCommonsUrls(): void {
		$this->assertSame(LicenseCode::CC_BY_NC_ND_30, LicenseCode::fromUrl(url: 'http://creativecommons.org/licenses/by-nc-nd/3.0/nl/'));
		$this->assertSame(LicenseCode::CC_BY_40, LicenseCode::fromUrl(url: 'https://creativecommons.org/licenses/by/4.0/'));
		$this->assertSame(LicenseCode::CC0, LicenseCode::fromUrl(url: 'https://creativecommons.org/publicdomain/zero/1.0/'));
		$this->assertSame(LicenseCode::PDM, LicenseCode::fromUrl(url: 'https://creativecommons.org/publicdomain/mark/1.0/'));
		$this->assertNull(LicenseCode::fromUrl(url: 'https://example.org/terms'));
	}//end testReadsCreativeCommonsUrls()

	/**
	 * @return void
	 */
	public function testWritesEdurepValues(): void {
		$this->assertSame('cc-by-sa-40', LicenseCode::CC_BY_SA_40->toEdurep());
		$this->assertSame('cc0-10', LicenseCode::CC0->toEdurep());
		$this->assertSame('pdm-10', LicenseCode::PDM->toEdurep());
		$this->assertSame('no', LicenseCode::RIGHTS_RESERVED->toEdurep());
	}//end testWritesEdurepValues()

	/**
	 * @return void
	 */
	public function testTheCodesAreAClosedSetWithRightsReservedAndUnknown(): void {
		$values = array_map(static fn (LicenseCode $code): string => $code->value, LicenseCode::cases());
		$this->assertCount(16, $values);
		$this->assertContains('rights-reserved', $values);
		$this->assertContains('unknown', $values);
	}//end testTheCodesAreAClosedSetWithRightsReservedAndUnknown()
}//end class
