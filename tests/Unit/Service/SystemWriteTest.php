<?php

/**
 * Integriq's one way of declaring a system write.
 *
 * 🔴 THERE USED TO BE THREE, AND ALL THREE DEGRADED. ConnectionStore,
 * MigrateStoredJobClasses and MaterializeCatalogItems each checked whether
 * OpenRegister's context class existed, elevated if it did, and otherwise
 * called the operation plainly. That fallback runs the identical write as
 * whoever is signed in and returns the same value the elevated call would have,
 * so nothing on any screen distinguishes it from having worked.
 *
 * It also made the app unsweepable: a per-call scan of 205 write calls could
 * not identify which writes run as the system, because a site naming the
 * context may or may not have elevated.
 *
 * @category  Test
 * @package   OCA\Integriq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * There is deliberately no @spec here. The tag this file carried named
 * `openspec/changes/three-degrading-system-writes/`, and no such change was ever
 * written, in this repo or any other. A citation that resolves to nothing is
 * worse than none: it reads as coverage and stops anyone looking for the
 * requirement. SystemWrite came out of a code sweep, not a spec, and the rule it
 * enforces — that a write which cannot be elevated refuses instead of running as
 * whoever is signed in — is pinned by the assertions below and by nothing else.
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\SystemWriteUnavailableException;
use OCA\Integriq\Service\SystemWrite;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SystemWrite.
 */
class SystemWriteTest extends TestCase {

	/**
	 * 🔴 NO CALLER NAMES THE CONTEXT DIRECTLY ANY MORE. This is the assertion
	 * the whole change exists for: a repo-wide check that the degrading shape
	 * cannot be reintroduced by a copy-paste.
	 *
	 * It found more than the three wrappers I set out to fix.
	 * `SynchronizationContractService` called the context UNGUARDED in two
	 * places — a fourth shape, which does not degrade but would fatal if
	 * OpenRegister were absent, and which a scan for `class_exists` never sees.
	 * Both now go through SystemWrite, so the elevation is verified rather
	 * than merely entered.
	 *
	 * @return void
	 */
	public function testNoCallerDegradesToAnUnelevatedWrite(): void {
		$root = dirname(__DIR__, 3).'/lib';
		$offenders = [];
		$scanned = 0;

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$scanned++;
			$text = (string)file_get_contents($file->getPathname());
			// Actual USE of the class, not a comment naming it. Three files
			// discuss it in prose while touching a different concern, and a
			// test that forbade the word would push that documentation out.
			if (str_contains($text, 'SystemOperationContext::') === false
				&& str_contains($text, "'SystemOperationContext'") === false
			) {
				continue;
			}

			// SystemWrite is the ONE place allowed to name the context.
			if (str_ends_with($file->getPathname(), 'lib/Service/SystemWrite.php') === true) {
				continue;
			}

			$offenders[] = $file->getPathname();
		}

		// A scan that walked nothing would pass silently.
		$this->assertGreaterThan(100, $scanned, 'the scan must actually have walked the tree');
		$this->assertSame([], $offenders, 'only SystemWrite may name OpenRegister\'s context class');
	}//end testNoCallerDegradesToAnUnelevatedWrite()

	/**
	 * A declared write runs and returns its value when elevation is available.
	 *
	 * OpenRegister is present in this checkout, so this exercises the real
	 * path rather than a double.
	 *
	 * @return void
	 */
	public function testADeclaredWriteRunsWhenElevationIsAvailable(): void {
		if (SystemWrite::isAvailable() === false) {
			$this->markTestSkipped('OpenRegister is not on the include path in this run');
		}

		$this->assertSame(
			'written',
			SystemWrite::run(what: 'a test write', operation: static fn () => 'written')
		);
	}//end testADeclaredWriteRunsWhenElevationIsAvailable()

	/**
	 * 🔴 AND IT REFUSES RATHER THAN RUNNING UNELEVATED. The refusal names the
	 * write, so an operator meeting it in a log knows which one stopped.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheWriteAndSaysItWasNotRunAnyway(): void {
		$refusal = new SystemWriteUnavailableException(what: 'a connection registry write');

		$this->assertStringContainsString('a connection registry write', $refusal->getMessage());
		$this->assertStringContainsString('NOT run as the acting user', $refusal->getMessage());
	}//end testTheRefusalNamesTheWriteAndSaysItWasNotRunAnyway()

	/**
	 * 🔴 WITHOUT ELEVATION THE WRITE DOES NOT HAPPEN AT ALL. This is the rule
	 * the change exists for, and inside run() it is unreachable wherever
	 * OpenRegister is installed — which is everywhere this suite runs. So the
	 * decision is asserted directly instead of relying on an environment that
	 * cannot be produced here.
	 *
	 * @return void
	 */
	public function testAnUnavailableContextRefusesRatherThanRunningPlainly(): void {
		$this->expectException(SystemWriteUnavailableException::class);
		$this->expectExceptionMessageMatches('/NOT run as the acting user/');

		SystemWrite::refuseWhenUnavailable(available: false, what: 'a connection registry write');
	}//end testAnUnavailableContextRefusesRatherThanRunningPlainly()

	/**
	 * And an available context refuses nothing, so the rule is not a blanket.
	 *
	 * @return void
	 */
	public function testAnAvailableContextIsNotRefused(): void {
		SystemWrite::refuseWhenUnavailable(available: true, what: 'a connection registry write');

		$this->addToAssertionCount(1);
	}//end testAnAvailableContextIsNotRefused()

	/**
	 * The operation's own failure propagates unchanged, rather than being
	 * reported as an elevation problem.
	 *
	 * @return void
	 */
	public function testTheOperationsOwnFailurePropagates(): void {
		if (SystemWrite::isAvailable() === false) {
			$this->markTestSkipped('OpenRegister is not on the include path in this run');
		}

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('the write failed');

		SystemWrite::run(
			what: 'a test write',
			operation: static function (): void {
				throw new \RuntimeException('the write failed');
			}
		);
	}//end testTheOperationsOwnFailurePropagates()

	/**
	 * ⚠️ OPENREGISTER ITSELF MUST NOT GAIN THIS GUARD. It uses the context
	 * unguarded in fourteen files because it OWNS the class: there
	 * `class_exists` is always true and a guard would be dead code. This test
	 * records that so nobody "fixes" those files to match integriq's shape.
	 *
	 * @return void
	 */
	public function testTheGuardBelongsInTheConsumerNotInTheOwner(): void {
		$this->assertSame(
			'\\OCA\\OpenRegister\\Service\\SystemOperationContext',
			SystemWrite::CONTEXT,
			'integriq names the class by string precisely because it cannot depend on it'
		);
	}//end testTheGuardBelongsInTheConsumerNotInTheOwner()
}//end class
