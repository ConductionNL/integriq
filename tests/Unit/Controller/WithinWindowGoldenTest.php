<?php

/**
 * Golden characterization of the dead-letter time window filters.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EventsController;
use OCA\Integriq\Controller\SyncDeadLetterController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every timestamp and window combination of EventsController::withinWindow()
 * and SyncDeadLetterController::withinWindow(), recorded against a golden file.
 *
 * Regenerate the golden file on the code it must describe with
 * WITHIN_WINDOW_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Controller\EventsController
 * @covers \OCA\Integriq\Controller\SyncDeadLetterController
 * @uses   \OCA\Integriq\Controller\TimeWindow
 */
class WithinWindowGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/controller/within-window.json';

	/**
	 * The timestamps and bounds the cases combine.
	 *
	 * @return array<string, string|null>
	 */
	private function values(): array {
		return [
			'null' => null,
			'empty' => '',
			'garbage' => 'not a date',
			'early' => '2026-01-01T00:00:00Z',
			'mid' => '2026-06-15T12:00:00Z',
			'late' => '2026-12-31T23:59:59Z',
			'mid-local' => '2026-06-15 12:00:00',
		];
	}//end values()

	/**
	 * The outputs of both controllers for every combination, keyed by case.
	 *
	 * @return array<string, string>
	 */
	private function record(): array {
		$out = [];
		foreach ([EventsController::class, SyncDeadLetterController::class] as $class) {
			$reflection = new ReflectionClass($class);
			$controller = $reflection->newInstanceWithoutConstructor();
			$method = $reflection->getMethod('withinWindow');
			$short = $reflection->getShortName();
			foreach ($this->values() as $tsName => $ts) {
				foreach ($this->values() as $fromName => $from) {
					foreach ($this->values() as $toName => $to) {
						$result = $method->invoke($controller, $ts, $from, $to);
						$out[$short . ' ' . $tsName . ' ' . $fromName . ' ' . $toName] = var_export($result, true);
					}
				}
			}
		}

		return $out;
	}//end record()

	/**
	 * Every case matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$zone = date_default_timezone_get();
		date_default_timezone_set('UTC');
		try {
			$actual = $this->record();
		} finally {
			date_default_timezone_set($zone);
		}

		if (getenv('WITHIN_WINDOW_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertCount(count($golden), $actual);
		foreach ($actual as $case => $value) {
			$this->assertSame($golden[$case], $value, $case);
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
