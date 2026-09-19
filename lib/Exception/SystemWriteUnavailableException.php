<?php

/**
 * A write integriq declares as the system's, in a process that cannot grant it.
 *
 * 🔴 THROWN RATHER THAN DEGRADING. Integriq cannot hard-depend on OpenRegister,
 * so it must ask whether the elevation exists. What it must NOT do is answer no
 * by running the write anyway: that runs the identical write as whoever is
 * signed in, returns the same value, and records the wrong principal — or fails
 * a permission check somewhere unrelated, for a reason nobody traces back to a
 * missing class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Exception
 * @package   OCA\Integriq\Exception
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://Integriq.app
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use RuntimeException;

/**
 * Thrown when a declared system write cannot be elevated.
 */
class SystemWriteUnavailableException extends RuntimeException {

	/**
	 * Build the refusal.
	 *
	 * @param string $what What was being written.
	 */
	public function __construct(string $what) {
		parent::__construct(
			message: sprintf(
				'"%s" is a system write and OpenRegister\'s system-operation context is not available to '
				.'grant it. It was NOT run as the acting user instead: that would record the wrong '
				.'principal against the write, or fail a permission check somewhere unrelated. Install or '
				.'upgrade OpenRegister.',
				$what
			)
		);
	}//end __construct()
}//end class
