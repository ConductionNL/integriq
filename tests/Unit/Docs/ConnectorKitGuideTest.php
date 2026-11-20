<?php

/**
 * ConnectorKitGuideTest
 *
 * The connector kit guide names only files the skeleton has, so a developer
 * following it never looks for a file that is not there.
 *
 * @category  Tests
 * @package   OCA\Integriq\Tests\Unit\Docs
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-a-developer-builds-an-extension-from-a-guide-a-skeleton-and-contract-tests-req-cxk-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The guide against the skeleton and the contract test cases.
 */
final class ConnectorKitGuideTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Every path the guide names, in backticks.
	 *
	 * @return array<int, string>
	 */
	private static function namedPaths(): array {
		$guide = (string)file_get_contents(self::root() . '/docs/developers/connector-kit.md');
		preg_match_all('#`((?:examples/connector-extension|\.github/workflows|tests/Contract)/[^`\s]+)`#', $guide, $matches);
		return array_values(array_unique($matches[1]));
	}//end namedPaths()

	/**
	 * Every file the guide names exists.
	 *
	 * @return void
	 */
	public function testEveryFileTheGuideNamesExists(): void {
		$paths = self::namedPaths();
		$this->assertGreaterThanOrEqual(8, count($paths), 'The guide should walk through the skeleton file by file.');
		foreach ($paths as $path) {
			$this->assertFileExists(self::root() . '/' . rtrim($path, '/'), 'The guide names ' . $path . ', which the skeleton does not have.');
		}
	}//end testEveryFileTheGuideNamesExists()

	/**
	 * Every contract test case the guide names is shipped in tests/Contract.
	 *
	 * @return void
	 */
	public function testEveryContractCaseTheGuideNamesIsShipped(): void {
		$guide = (string)file_get_contents(self::root() . '/docs/developers/connector-kit.md');
		preg_match_all('#`(\w+ContractTestCase)`#', $guide, $matches);
		$this->assertSame(
			['IntakeChannelContractTestCase', 'PaymentProviderContractTestCase', 'DigitalPostProviderContractTestCase'],
			array_values(array_unique($matches[1]))
		);
		foreach ($matches[1] as $case) {
			$this->assertFileExists(self::root() . '/tests/Contract/' . $case . '.php');
		}
	}//end testEveryContractCaseTheGuideNamesIsShipped()
}//end class
