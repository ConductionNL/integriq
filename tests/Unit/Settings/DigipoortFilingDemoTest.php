<?php

/**
 * The demo Digipoort filing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#scenario-an-administrator-checks-a-filing
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The demo filing for Adviesbureau Kade B.V. matches the schema and shows delivered then accepted.
 */
class DigipoortFilingDemoTest extends TestCase {
	/**
	 * The demo filing's slug.
	 */
	private const SLUG = 'sbr-filing-adviesbureau-kade-ob-2026-q3';

	/**
	 * The demo filing from the mock register.
	 *
	 * @return array<string,mixed>
	 */
	private function demoFiling(): array {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'), true);
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['slug'] ?? null) === self::SLUG) {
				return $object;
			}
		}

		$this->fail('No demo filing ' . self::SLUG);
	}//end demoFiling()

	/**
	 * The demo filing is the OB aangifte 2026 Q3, delivered and then accepted, each with a time.
	 *
	 * @return void
	 */
	public function testTheDemoFilingShowsDeliveredThenAccepted(): void {
		$filing = $this->demoFiling();

		$this->assertSame('ob-aangifte', $filing['filingType']);
		$this->assertSame('belastingdienst', $filing['recipient']);
		$this->assertSame('accepted', $filing['status']);
		$this->assertSame(['delivered', 'accepted'], array_column($filing['statusHistory'], 'status'));
		foreach ($filing['statusHistory'] as $entry) {
			$this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $entry['at']), 'Each status carries its time.');
		}
	}//end testTheDemoFilingShowsDeliveredThenAccepted()

	/**
	 * The demo filing validates against the shipped `sbr_filing` schema.
	 *
	 * @return void
	 */
	public function testTheDemoFilingMatchesTheSchema(): void {
		$filing = $this->demoFiling();
		unset($filing['@self']);

		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'sbr_filing', object: $filing));
	}//end testTheDemoFilingMatchesTheSchema()

	/**
	 * The demo register's copy of the schema is the shipped one.
	 *
	 * @return void
	 */
	public function testTheDemoSchemaIsTheShippedSchema(): void {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'), true);
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/connectors-digipoort-sbr-filing.json'), true);

		$this->assertSame($fragment['components']['schemas']['sbr_filing'], $mock['components']['schemas']['sbr_filing']);
	}//end testTheDemoSchemaIsTheShippedSchema()
}//end class
