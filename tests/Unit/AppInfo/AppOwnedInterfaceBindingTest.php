<?php

/**
 * Every app-owned interface injected bare must be bound in the container.
 *
 * `DnsResolverInterface` and `CallDispatcherInterface` each shipped as a
 * required, non-nullable constructor parameter of a class only Nextcloud's DI
 * ever builds, with exactly one implementation and nothing binding them. So
 * `DomainAlignmentChecker` and `CallReplayService` could not be constructed,
 * `SenderIdentityController` and `CallLogController` could not be built, and
 * NINE routes answered 500 — including `GET /unsubscribe/{token}`, the opt-out
 * link in outbound mail, which is the one a recipient follows rather than an
 * operator (integriq#1983 review 5278999788, from PRs #2060 and #2063).
 *
 * Nothing existing could have caught it. psalm and phpstan are green because
 * the types are right. The unit suite is green because it binds its own
 * fixtures. The route-reachability gate checks that a route resolves to a class
 * and a method, not that the class can be INSTANTIATED.
 *
 * This test pins the property rather than those two instances: add a new
 * app-owned interface as a bare constructor parameter and forget the binding,
 * and this fails by name instead of a route failing in production.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

class AppOwnedInterfaceBindingTest extends TestCase {

	/**
	 * Every `.php` file under lib/.
	 *
	 * @return array<int,string> Absolute paths.
	 */
	private function libFiles(): array {
		$root = dirname(__DIR__, 3) . '/lib';
		$found = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($it as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$found[] = $file->getPathname();
			}
		}

		sort($found);
		return $found;

	}//end libFiles()

	/**
	 * Interface names this app declares itself.
	 *
	 * @param array<int,string> $files The files to scan.
	 *
	 * @return array<int,string> Short interface names.
	 */
	private function appOwnedInterfaces(array $files): array {
		$names = [];
		foreach ($files as $file) {
			$source = (string)file_get_contents($file);
			preg_match_all('/^\s*interface\s+(\w+)/m', $source, $matches);
			foreach ($matches[1] as $name) {
				$names[$name] = true;
			}
		}

		return array_keys($names);

	}//end appOwnedInterfaces()

	/**
	 * The parameter list of a file's `__construct`, docblocks removed.
	 *
	 * Docblocks are stripped first: an `@param FooInterface $bar` line would
	 * otherwise read as an injection and make this test cry wolf, which is how a
	 * property test gets deleted rather than fixed.
	 *
	 * @param string $source The file's contents.
	 *
	 * @return string The raw parameter list, or the empty string.
	 */
	private function constructorParams(string $source): string {
		$source = (string)preg_replace('#/\*\*.*?\*/#s', '', $source);
		$at = strpos($source, 'function __construct');
		if ($at === false) {
			return '';
		}

		$open = strpos($source, '(', $at);
		if ($open === false) {
			return '';
		}

		$depth = 0;
		$length = strlen($source);
		for ($i = $open; $i < $length; $i++) {
			if ($source[$i] === '(') {
				$depth++;
				continue;
			}

			if ($source[$i] === ')') {
				$depth--;
				if ($depth === 0) {
					return substr($source, ($open + 1), ($i - $open - 1));
				}
			}
		}

		return '';

	}//end constructorParams()

	/**
	 * No app-owned interface is injected bare without a container binding.
	 *
	 * @return void
	 *
	 * @spec exclude Container-wiring invariant, not a behaviour any requirement states. No spec describes which interfaces exist, so an anchor here would name an unrelated requirement to satisfy the gate.
	 */
	public function testEveryBareAppOwnedInterfaceIsBound(): void {
		$files = $this->libFiles();
		$owned = $this->appOwnedInterfaces($files);
		$this->assertNotEmpty($owned, 'The scan found no interfaces at all, so it is not scanning.');

		$application = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');

		$unbound = [];
		foreach ($files as $file) {
			$params = $this->constructorParams((string)file_get_contents($file));
			if ($params === '') {
				continue;
			}

			// A required, non-nullable, non-variadic parameter typed as an
			// interface: `Foo $bar` but not `?Foo $bar`, `Foo ...$bar` or
			// `Foo $bar = null`.
			preg_match_all(
				'/(?:^|,)\s*(?:(?:private|protected|public)\s+)?(?:readonly\s+)?([A-Za-z_\\\\]*\b\w*Interface)\s+\$(\w+)(?!\s*=)/',
				$params,
				$matches,
				PREG_SET_ORDER
			);

			foreach ($matches as $match) {
				$parts = explode('\\', $match[1]);
				$short = end($parts);
				if (in_array($short, $owned, true) === false) {
					continue;
				}

				if (str_contains($application, $short . '::class') === true) {
					continue;
				}

				$unbound[] = $short . ' (injected by ' . basename($file) . ')';
			}
		}

		$unbound = array_values(array_unique($unbound));

		$this->assertSame(
			[],
			$unbound,
			"These app-owned interfaces are injected as required constructor parameters but nothing binds them in "
			. "Application.php, so every class that needs them fails to construct and its routes answer 500:\n  "
			. implode("\n  ", $unbound)
		);

	}//end testEveryBareAppOwnedInterfaceIsBound()
}//end class
