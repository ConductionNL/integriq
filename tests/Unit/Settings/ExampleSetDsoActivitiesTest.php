<?php

/**
 * Example data for dossiq's VTH settings (DqVthInstellingen): three DSO
 * activities mapped to a case type, the verzoek that last arrived for each,
 * and one verzoek whose activity has no mapping yet.
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
 * The DSO activities of the Zuiddrecht set.
 */
class ExampleSetDsoActivitiesTest extends TestCase {

	/**
	 * Each mapping row has a verzoek that matched it; one verzoek waits unmapped.
	 *
	 * @return void
	 */
	public function testEveryActivityHasAnArrivalAndOneWaits(): void {
		$set      = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$mappings = [];
		$matched  = [];
		$waiting  = 0;
		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'dso_activity_mapping') {
				$mappings[] = $object['key'];
			}

			if ($object['schema'] !== 'dso_verzoek') {
				continue;
			}

			if ($object['data']['activityUnmapped'] === true) {
				$waiting++;
				continue;
			}

			foreach ($object['data']['mappedActivities'] as $activity) {
				$matched[] = (string)preg_replace(pattern: '/^\{\{object:(.+)\.id\}\}$/', replacement: '$1', subject: $activity['mappingRow']);
			}
		}

		$this->assertSame(
			expected: ['dso-bouwen-bouwwerk', 'dso-kappen-houtopstand', 'dso-melding-brandveilig'],
			actual: $mappings
		);
		$this->assertSame(expected: $mappings, actual: $matched);
		$this->assertSame(expected: 1, actual: $waiting);
	}//end testEveryActivityHasAnArrivalAndOneWaits()
}//end class
