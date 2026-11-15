<?php

/**
 * The Zuiddrecht set's app connections name their source and nothing the
 * connection check writes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Status, status message, last report and check time are runtime state: the
 * connection check writes them on every save, so a set that declares them
 * reads its own values back as "written but not kept".
 */
class ZuiddrechtConnectionsDeclareTheirSourceTest extends TestCase {

	/**
	 * The fields the connection check owns.
	 */
	private const RUNTIME_FIELDS = ['lastReport', 'status', 'statusMessage', 'checkedAt'];

	/**
	 * No change to an app connection sets a field the connection check owns.
	 *
	 * @return void
	 */
	public function testNoConnectionChangeSetsRuntimeState(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$checked = 0;
		foreach (($set['changes'] ?? []) as $change) {
			if (($change['schema'] ?? '') !== 'app_connection') {
				continue;
			}

			$checked++;
			$owned = array_intersect(array_keys($change['set'] ?? []), self::RUNTIME_FIELDS);
			$this->assertSame(expected: [], actual: array_values($owned), message: $change['key']);
		}

		$this->assertGreaterThan(expected: 0, actual: $checked);
	}//end testNoConnectionChangeSetsRuntimeState()
}//end class
