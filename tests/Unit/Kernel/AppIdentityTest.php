<?php

/**
 * Tests for the app identity constant.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Kernel;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Kernel\AppIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Pins the app id and keeps components off the Application class.
 */
class AppIdentityTest extends TestCase {

	/**
	 * The value other apps read through Application stays the same.
	 *
	 * @return void
	 */
	public function testApplicationKeepsTheAppId(): void {
		$this->assertSame(expected: 'integriq', actual: AppIdentity::APP_ID);
		$this->assertSame(expected: AppIdentity::APP_ID, actual: Application::APP_ID);

	}//end testApplicationKeepsTheAppId()

	/**
	 * No class outside lib/AppInfo imports Application.
	 *
	 * Application wires every component, so a component that reads a constant
	 * from it depends on its own wiring and forms a cycle with AppInfo.
	 *
	 * @return void
	 */
	public function testNoComponentImportsApplication(): void {
		$lib = realpath(__DIR__ . '/../../../lib');
		$this->assertNotFalse(condition: $lib);

		$files    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		$checked  = 0;
		$offender = [];
		foreach ($files as $file) {
			$path = $file->getPathname();
			if (str_ends_with($path, '.php') === false || str_starts_with($path, $lib . '/AppInfo/') === true) {
				continue;
			}

			$checked++;
			$source = (string) file_get_contents($path);
			if (preg_match('/^use OCA\\\\Integriq\\\\AppInfo\\\\Application;/m', $source) === 1
				|| preg_match('/(?<!@see )\\\\OCA\\\\Integriq\\\\AppInfo\\\\Application::/', $source) === 1
			) {
				$offender[] = substr($path, strlen($lib) + 1);
			}
		}

		$this->assertGreaterThan(expected: 500, actual: $checked, message: 'the walk over lib/ found too few files to mean anything');
		$this->assertSame(expected: [], actual: $offender, message: 'read the app id from AppIdentity, not from Application');

	}//end testNoComponentImportsApplication()
}//end class
