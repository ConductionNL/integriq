<?php

/**
 * The Zuiddrecht set's DSO verzoeken carry only the case type fields the
 * dso_verzoek schema allows (its caseTypes items refuse anything else), so
 * the set's --check passes; the variant a mapping row gives is one of them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The example verzoeken against the dso_verzoek schema.
 */
class ExampleSetDsoVerzoekMatchesSchemaTest extends TestCase {

	/**
	 * Every mapped activity's case type uses only allowed fields, variant among them.
	 *
	 * @return void
	 */
	public function testTheExampleVerzoekenFitTheSchema(): void {
		$root     = __DIR__.'/../../../lib/Settings/';
		$register = json_decode((string)file_get_contents($root.'integriq_register.json'), true);
		$activity = $register['components']['schemas']['dso_verzoek']['properties']['mappedActivities']['items'];
		$allowed  = array_keys($activity['properties']['caseTypes']['items']['properties']);
		$this->assertContains(needle: 'variant', haystack: $allowed);

		$set     = json_decode((string)file_get_contents($root.'example-sets/zuiddrecht.json'), true);
		$checked = 0;
		foreach ($set['objects'] as $object) {
			if ($object['schema'] !== 'dso_verzoek') {
				continue;
			}

			foreach (($object['data']['mappedActivities'] ?? []) as $activity) {
				foreach (($activity['caseTypes'] ?? []) as $caseType) {
					$this->assertSame(expected: [], actual: array_values(array_diff(array_keys($caseType), $allowed)), message: $object['key']);
					$checked++;
				}
			}
		}

		$this->assertGreaterThan(expected: 0, actual: $checked);
	}//end testTheExampleVerzoekenFitTheSchema()
}//end class
