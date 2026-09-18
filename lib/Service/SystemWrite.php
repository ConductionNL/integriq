<?php

/**
 * Integriq's one way of saying "this write runs as the system".
 *
 * 🔴 THERE USED TO BE THREE, AND ALL THREE DEGRADED. `ConnectionStore`,
 * `MigrateStoredJobClasses` and `MaterializeCatalogItems` each carried the same
 * shape: check whether OpenRegister's context class exists, elevate if it does,
 * and otherwise call the operation plainly. The fallback is not a graceful
 * degradation. It runs the identical write as whoever is signed in and returns
 * the same value the elevated call would have, so nothing on any screen
 * distinguishes it from having worked.
 *
 * It also made the app unsweepable. A reviewer asking which writes run as the
 * system could not answer it statically, because a site naming the context may
 * or may not have elevated. A per-call scan of 205 write calls could not
 * identify a safe subset for exactly this reason, so integriq's schemas still
 * declare no `create` cascade.
 *
 * 🔴 THE GUARD STAYS, THE FALLBACK GOES. Integriq genuinely cannot hard-depend
 * on OpenRegister, so asking is right. Answering "no" by doing it anyway is
 * what was wrong.
 *
 * ⚠️ OPENREGISTER ITSELF DOES NOT GUARD, AND MUST NOT START. It uses
 * SystemOperationContext unguarded in fourteen files because it owns the class:
 * there, `class_exists` is always true and a guard would be dead code. Do not
 * "fix" those to match this.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Integriq\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://Integriq.app
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\SystemWriteUnavailableException;

/**
 * Runs a declared system write, or refuses to run it at all.
 */
final class SystemWrite {

	/**
	 * OpenRegister's context class, by name so this file never imports it.
	 *
	 * @var string
	 */
	public const CONTEXT = '\\OCA\\OpenRegister\\Service\\SystemOperationContext';

	/**
	 * This class is a static helper and must not be instantiated.
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * Refuse a declared system write when elevation cannot be granted.
	 *
	 * 🔴 SPLIT OUT SO THE RULE IS TESTABLE. Inside `run()` this branch is
	 * unreachable wherever OpenRegister is installed, which is everywhere the
	 * suite runs — so mutating the throw away reddened nothing and the most
	 * important rule in this class was pinned by no test at all. Taking the
	 * availability as an argument makes the decision itself assertable, rather
	 * than only the environment that happens to be present.
	 *
	 * @param bool   $available Whether elevation can be granted.
	 * @param string $what      What is being written.
	 *
	 * @return void
	 *
	 * @throws SystemWriteUnavailableException When it cannot.
	 *
	 * @spec exclude no openspec change describes this; SystemWrite came out of a code sweep and its rule is pinned by tests/Unit/Service/SystemWriteTest.php
	 */
	public static function refuseWhenUnavailable(bool $available, string $what): void {
		if ($available === true) {
			return;
		}

		// NOT `return $operation()`. That is the whole point: the degraded call
		// runs the same write as the acting user and returns the same value.
		throw new SystemWriteUnavailableException(what: $what);
	}//end refuseWhenUnavailable()

	/**
	 * Whether the platform can grant elevation in this process.
	 *
	 * @return bool True when a declared system write can run.
	 */
	public static function isAvailable(): bool {
		return class_exists(self::CONTEXT);
	}//end isAvailable()

	/**
	 * Run an operation as the system, or throw.
	 *
	 * Prefers OpenRegister's `assertSystem()`, which verifies the elevation
	 * actually took effect on both sides of the operation, and falls back to
	 * `run()` only for an OpenRegister old enough not to have it. That fallback
	 * is between two ELEVATED paths, not between elevated and not.
	 *
	 * @param string   $what      What is being written, for the refusal.
	 * @param callable $operation The trusted operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @throws SystemWriteUnavailableException When elevation cannot be granted.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext is OpenRegister's static scope guard; there is no instance API.
	 *
	 * @spec exclude no openspec change describes this; SystemWrite came out of a code sweep and its rule is pinned by tests/Unit/Service/SystemWriteTest.php
	 */
	public static function run(string $what, callable $operation): mixed {
		self::refuseWhenUnavailable(available: self::isAvailable(), what: $what);

		$context = self::CONTEXT;
		if (method_exists($context, 'assertSystem') === true) {
			return $context::assertSystem($what, $operation);
		}

		return $context::run($operation);
	}//end run()
}//end class
