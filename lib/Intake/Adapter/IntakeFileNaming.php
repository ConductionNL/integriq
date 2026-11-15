<?php

/**
 * Integriq IntakeFileNaming: the name and type an intake channel gives a file
 * that arrives without them.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake\Adapter;

/**
 * Defaults for an unnamed, untyped file.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */
final class IntakeFileNaming {
	/**
	 * Constructor.
	 *
	 * @param string $nameFormat The sprintf format of a default name, given the file's 1-based position.
	 * @param string $mime       The default type.
	 */
	public function __construct(
		public readonly string $nameFormat,
		public readonly string $mime,
	) {
	}//end __construct()
}//end class
