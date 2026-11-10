<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Settings\SectionAppearance.
 *
 * The real class lives in the peer OpenRegister app (not in vendor). Mirrors
 * its constructor so unit tests can build the generic settings classes.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Settings
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

namespace OCA\OpenRegister\AppHost\Settings;

/**
 * Minimal stand-in for how a leaf settings section is shown.
 */
final class SectionAppearance {
	/**
	 * @param string $name     The display name.
	 * @param string $iconFile The icon file name.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $iconFile,
	) {
	}//end __construct()
}//end class
