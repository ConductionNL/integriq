<?php

/**
 * Unit tests for the two install-level promises of the SFTP and FTPS sources:
 * the SFTP library integriq ships, and no TimedJob class for them.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/tasks.md#task-1-settle-the-library
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileSessionFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Nextcloud 32 to 34 bundle phpseclib 2 (`phpseclib\`), Nextcloud 35 bundles
 * phpseclib 3. The SFTP session needs `phpseclib3\Net\SFTP` on every supported
 * version, so integriq requires phpseclib 3 itself and never prunes it as a
 * package core provides.
 */
#[CoversNothing]
class RemoteFileLibraryAndSchedulingTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Set the app root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname(__DIR__, 6);
	}//end setUp()

	/**
	 * Integriq requires phpseclib 3 directly instead of relying on another package to pull it in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/tasks.md#task-1-settle-the-library
	 */
	public function testPhpseclibThreeIsADirectRequirement(): void {
		$composer = $this->json(file: 'composer.json');

		$this->assertArrayHasKey(key: 'phpseclib/phpseclib', array: $composer['require']);
		$this->assertMatchesRegularExpression(pattern: '/^\^3\./', string: $composer['require']['phpseclib/phpseclib']);
	}//end testPhpseclibThreeIsADirectRequirement()

	/**
	 * phpseclib is never pruned from a release: Nextcloud 32 to 34 provide only phpseclib 2.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/tasks.md#task-1-settle-the-library
	 */
	public function testPhpseclibIsNotPrunedAsCoreProvided(): void {
		$composer = $this->json(file: 'composer.json');

		$this->assertNotContains(needle: 'phpseclib/phpseclib', haystack: ($composer['extra']['nextcloud-core-provided'] ?? []));
	}//end testPhpseclibIsNotPrunedAsCoreProvided()

	/**
	 * The lock carries a phpseclib 3 release as a runtime package, not a development one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/tasks.md#task-1-settle-the-library
	 */
	public function testTheLockShipsPhpseclibThreeAtRuntime(): void {
		$lock     = $this->json(file: 'composer.lock');
		$versions = [];
		foreach ($lock['packages'] as $package) {
			if ($package['name'] === 'phpseclib/phpseclib') {
				$versions[] = ltrim((string)$package['version'], 'v');
			}
		}

		$this->assertCount(expectedCount: 1, haystack: $versions);
		$this->assertStringStartsWith(prefix: '3.', string: $versions[0]);
	}//end testTheLockShipsPhpseclibThreeAtRuntime()

	/**
	 * The class the session factory instantiates is phpseclib 3's, and it loads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/tasks.md#task-1-settle-the-library
	 */
	public function testTheSessionFactoryUsesThePhpseclibThreeNamespace(): void {
		$this->assertStringStartsWith(prefix: 'phpseclib3\\', string: RemoteFileSessionFactory::SFTP_CLASS);
		$this->assertStringStartsWith(prefix: 'phpseclib3\\', string: RemoteFileSessionFactory::KEY_LOADER_CLASS);
		$this->assertTrue(condition: class_exists(RemoteFileSessionFactory::SFTP_CLASS));
		$this->assertTrue(condition: class_exists(RemoteFileSessionFactory::KEY_LOADER_CLASS));
	}//end testTheSessionFactoryUsesThePhpseclibThreeNamespace()

	/**
	 * No TimedJob class runs the SFTP or FTPS sources (REQ-DIC-005).
	 *
	 * A class counts when it extends TimedJob and either lives under the remote
	 * file adapter or names one of its classes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-infra-connectors/spec.md#requirement-scheduled-pulls-shall-run-as-openregister-scheduledworkflow-records-not-as-integriq-timedjob-classes-req-dic-005
	 */
	public function testNoTimedJobRunsARemoteFileSource(): void {
		$offenders = [];
		$scanned   = 0;
		$files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/lib', RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$scanned++;
			$path   = $file->getPathname();
			$source = (string)file_get_contents($path);
			if (preg_match('/\bextends\s+\\\\?(?:OCP\\\\BackgroundJob\\\\)?TimedJob\b/', $source) !== 1) {
				continue;
			}

			$underAdapter = str_contains($path, '/Adapter/DataInfra/RemoteFiles/');
			$adapterNames = '/\b(?:FilePickup|FilePickupSynchronization|RemoteFileClient|SftpSession|FtpsSession|RemoteFileSessionFactory)\b/';
			$namesAdapter = preg_match($adapterNames, $source) === 1;
			if ($underAdapter === true || $namesAdapter === true) {
				$offenders[] = substr($path, strlen($this->root) + 1);
			}
		}

		$this->assertGreaterThan(expected: 100, actual: $scanned, message: 'The scan must read the app\'s lib/ tree.');
		$this->assertSame(expected: [], actual: $offenders, message: 'REQ-DIC-005: no TimedJob class for SFTP or FTPS sources.');
	}//end testNoTimedJobRunsARemoteFileSource()

	/**
	 * A JSON file from the app root, decoded.
	 *
	 * @param string $file The file name.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private function json(string $file): array {
		$decoded = json_decode((string)file_get_contents($this->root . '/' . $file), true);
		$this->assertIsArray(actual: $decoded, message: $file . ' must be JSON.');

		return $decoded;
	}//end json()
}//end class
