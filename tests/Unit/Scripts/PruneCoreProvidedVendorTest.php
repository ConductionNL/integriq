<?php

/**
 * The release-time prune of packages that Nextcloud core already ships.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Scripts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/prune-core-provided-vendor.php';

/**
 * @spec exclude release packaging script, guards against shadowing Nextcloud core's 3rdparty packages (#2433)
 */
class PruneCoreProvidedVendorTest extends TestCase {

	/**
	 * Fixture vendor directory.
	 *
	 * @var string
	 */
	private string $vendorDir;

	/**
	 * Builds a vendor tree with two core-provided packages and one app package.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->vendorDir = sys_get_temp_dir() . '/integriq-prune-' . bin2hex(random_bytes(6));
		foreach (['symfony/console/Command', 'psr/log/src', 'twig/twig/src'] as $dir) {
			mkdir($this->vendorDir . '/' . $dir, 0777, true);
			file_put_contents($this->vendorDir . '/' . $dir . '/X.php', '<?php');
		}

		mkdir($this->vendorDir . '/composer');
		file_put_contents(
			$this->vendorDir . '/composer/installed.json',
			json_encode([
				'packages' => [['name' => 'symfony/console'], ['name' => 'psr/log'], ['name' => 'twig/twig']],
				'dev' => false,
				'dev-package-names' => [],
			])
		);
		file_put_contents(
			$this->vendorDir . '/composer/installed.php',
			'<?php return ' . var_export([
				'root' => ['name' => 'conductionnl/integriq'],
				'versions' => ['symfony/console' => [], 'psr/log' => [], 'twig/twig' => []],
			], true) . ';'
		);
	}//end setUp()

	/**
	 * Removes the fixture tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		removeDirectory($this->vendorDir);
	}//end tearDown()

	/**
	 * Listed packages leave the vendor tree and its metadata; others stay.
	 *
	 * @return void
	 */
	public function testRemovesListedPackagesAndKeepsTheRest(): void {
		$removed = pruneCoreProvidedPackages($this->vendorDir, ['symfony/console', 'psr/log', 'doctrine/dbal']);

		$this->assertSame(['symfony/console', 'psr/log'], $removed);
		$this->assertDirectoryDoesNotExist($this->vendorDir . '/symfony/console');
		$this->assertDirectoryDoesNotExist($this->vendorDir . '/symfony');
		$this->assertDirectoryDoesNotExist($this->vendorDir . '/psr');
		$this->assertFileExists($this->vendorDir . '/twig/twig/src/X.php');

		$installed = json_decode((string) file_get_contents($this->vendorDir . '/composer/installed.json'), true);
		$this->assertSame(['twig/twig'], array_column($installed['packages'], 'name'));

		$versions = include $this->vendorDir . '/composer/installed.php';
		$this->assertSame(['twig/twig'], array_keys($versions['versions']));
	}//end testRemovesListedPackagesAndKeepsTheRest()

	/**
	 * The shipped list names symfony/console, the package behind #2433.
	 *
	 * @return void
	 */
	public function testComposerJsonListsSymfonyConsole(): void {
		$composer = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true);

		$this->assertContains('symfony/console', $composer['extra']['nextcloud-core-provided']);
		$this->assertContains('@prune-core-provided', $composer['scripts']['post-install-cmd']);
	}//end testComposerJsonListsSymfonyConsole()
}//end class
