<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Settings\AdminFormServices.
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

use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;

/**
 * Minimal stand-in for the services the admin settings form renders through.
 */
final class AdminFormServices {
	/**
	 * @param IAppManager     $appManager   The app manager.
	 * @param IInitialState   $initialState The initial state service.
	 * @param IAppConfig|null $appConfig    The app config service.
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly IInitialState $initialState,
		public readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()
}//end class
