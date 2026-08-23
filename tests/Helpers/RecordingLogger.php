<?php

/**
 * A PSR-3 logger that records every line, so tests can assert no secret ever reaches it.
 *
 * "No secret in any log" is only a real assertion if the test can SEE every line the
 * code under test emitted — including the ones from failure paths, where an exception
 * message is the classic leak vector. This double keeps them all, flattened with their
 * context, for a simple `assertStringNotContainsString()` sweep.
 *
 * It is a Helper (autoloaded via the Tests PSR-4 map) rather than a class declared
 * inside a test file, so any test FILE can use it when run on its own — a class
 * declared inside another test file is NOT PSR-4 autoloadable and fatals in a
 * single-file phpunit run.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
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

namespace OCA\Integriq\Tests\Helpers;

use Psr\Log\AbstractLogger;

/**
 * Records every log call for secret-leak assertions.
 */
class RecordingLogger extends AbstractLogger {

	/**
	 * Every line and context this logger was handed, flattened to a string.
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * Record a log call.
	 *
	 * Untyped $message/$level to stay compatible with the PSR-3 version pinned here.
	 *
	 * @param mixed $level The log level.
	 * @param mixed $message The message.
	 * @param array $context The context.
	 *
	 * @return void
	 */
	public function log($level, $message, array $context = []): void {
		$this->lines[] = (string)$message . ' ' . json_encode($context);
	}//end log()

	/**
	 * Every recorded line, joined — convenient for a single leak assertion.
	 *
	 * @return string The flattened log.
	 */
	public function flatten(): string {
		return implode("\n", $this->lines);
	}//end flatten()
}//end class
