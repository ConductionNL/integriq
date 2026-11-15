<?php

/**
 * Unit tests for the normalised learning material record.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\LicenseCode;
use OCA\Integriq\Adapters\LearningMaterial\MaterialRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Fields are normalised: strings, nullable strings, lists and the licence.
 */
#[CoversClass(MaterialRecord::class)]
class MaterialRecordTest extends TestCase {
	/**
	 * @return void
	 */
	public function testAnEmptyRecordHasEmptyDefaults(): void {
		$record = (new MaterialRecord([]))->toArray();

		$this->assertSame('', $record['sourceId']);
		$this->assertSame('', $record['title']);
		$this->assertNull($record['embedUrl']);
		$this->assertNull($record['published']);
		$this->assertNull($record['licenseUrl']);
		$this->assertNull($record['accessRights']);
		$this->assertSame([], $record['files']);
		$this->assertSame([], $record['authors']);
		$this->assertSame(LicenseCode::UNKNOWN->value, $record['license']);
	}//end testAnEmptyRecordHasEmptyDefaults()

	/**
	 * @return void
	 */
	public function testFieldsAreNormalised(): void {
		$record = new MaterialRecord(
			[
				'sourceId' => 123,
				'title' => 'Betoog schrijven',
				'url' => 'https://example.org/m',
				'embedUrl' => '',
				'published' => 2024,
				'license' => LicenseCode::CC_BY_40,
				'licenseUrl' => ['not scalar'],
				'files' => [['url' => 'https://example.org/f.pdf', 'format' => 'application/pdf', 'accessRights' => ''], 'not a file', ['format' => 'text/html', 'accessRights' => 'open']],
				'authors' => ['Jan', 7, null, ['x']],
				'levels' => 'VO',
				'disciplines' => [],
			]
		);

		$this->assertSame('123', $record->sourceId);
		$this->assertNull($record->embedUrl);
		$this->assertSame('2024', $record->published);
		$this->assertNull($record->licenseUrl);
		$this->assertSame(LicenseCode::CC_BY_40, $record->license);
		$this->assertSame(
			[
				['url' => 'https://example.org/f.pdf', 'format' => 'application/pdf', 'accessRights' => null],
				['url' => '', 'format' => 'text/html', 'accessRights' => 'open'],
			],
			$record->files
		);
		$this->assertSame(['Jan', '7'], $record->authors);
		$this->assertSame(['VO'], $record->levels);
		$this->assertSame('CC-BY-4.0', $record->toArray()['license']);
	}//end testFieldsAreNormalised()

	/**
	 * @return void
	 */
	public function testALicenceThatIsNoLicenceCodeIsUnknown(): void {
		$this->assertSame(LicenseCode::UNKNOWN, (new MaterialRecord(['license' => 'CC-BY-4.0']))->license);
	}//end testALicenceThatIsNoLicenceCodeIsUnknown()
}//end class
