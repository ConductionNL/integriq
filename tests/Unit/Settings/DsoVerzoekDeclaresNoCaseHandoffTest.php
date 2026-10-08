<?php

/**
 * A DSO verzoek is never handed off to a case by integriq.
 *
 * The case system makes the case. Dossiq's listener reads the `dso_verzoek`
 * once integriq has mapped it, files it on the case type in
 * `mappedCaseTypes`, and makes one case per verzoek. Integriq's manual
 * `verzoek-to-case` handoff sent OpenRegister's `ns#Case` contract (title,
 * summary, channel, source, priority) with no case type, so it answered 502
 * "caseType missing", and once it worked it would have made a second case
 * for the same verzoek.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retire-dso-case-handoff/specs/dso-omgevingsloket/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Controller\DSOController;
use OCA\Integriq\Service\DsoIngestService;
use PHPUnit\Framework\TestCase;

/**
 * The shipped dso_verzoek schema, the route table and the services hold no case handoff.
 */
class DsoVerzoekDeclaresNoCaseHandoffTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Both shipped registers.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function registers(): array {
		return [
			'register' => ['lib/Settings/integriq_register.json'],
			'mock register' => ['lib/Settings/integriq_mock_register.json'],
		];
	}//end registers()

	/**
	 * The dso_verzoek schema declares no handoff.
	 *
	 * @param string $file The register file, relative to the app root.
	 *
	 * @dataProvider registers
	 *
	 * @return void
	 */
	public function testTheVerzoekSchemaDeclaresNoHandoff(string $file): void {
		$register = json_decode((string)file_get_contents($this->root() . '/' . $file), true);
		$schema = ($register['components']['schemas']['dso_verzoek'] ?? null);

		self::assertIsArray($schema, $file . ' ships dso_verzoek');
		self::assertArrayNotHasKey('x-openregister-handoff', $schema, 'the case system makes the DSO case, not an integriq handoff');
	}//end testTheVerzoekSchemaDeclaresNoHandoff()

	/**
	 * No route triggers a DSO handoff.
	 *
	 * @return void
	 */
	public function testNoRouteTriggersADsoHandoff(): void {
		$routes = require $this->root() . '/appinfo/routes.php';
		self::assertIsArray($routes);

		$offenders = [];
		foreach (($routes['routes'] ?? []) as $route) {
			if (str_starts_with((string)($route['name'] ?? ''), 'dSO#') === true
				&& str_contains((string)($route['url'] ?? ''), 'handoff') === true
			) {
				$offenders[] = (string)$route['url'];
			}
		}

		self::assertSame([], $offenders);
	}//end testNoRouteTriggersADsoHandoff()

	/**
	 * Neither the controller nor the service can still execute one.
	 *
	 * @return void
	 */
	public function testNoDsoClassExecutesAHandoff(): void {
		self::assertFalse(method_exists(DSOController::class, 'handoff'));
		self::assertFalse(method_exists(DsoIngestService::class, 'handoff'));
	}//end testNoDsoClassExecutesAHandoff()
}//end class
