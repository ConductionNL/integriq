<?php

/**
 * The identity of the Integriq app.
 *
 * @category Kernel
 * @package  OCA\Integriq\Kernel
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

namespace OCA\Integriq\Kernel;

/**
 * The identity of the Integriq app.
 *
 * Components read the app id here, so they do not depend on the Application
 * class that wires them. Application::APP_ID keeps the same value for other
 * apps.
 *
 * @spec exclude constants only, no behaviour of their own
 */
final class AppIdentity {

	/**
	 * The app id.
	 *
	 * @var string
	 */
	public const APP_ID = 'integriq';
}//end class
