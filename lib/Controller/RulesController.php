<?php

/**
 * Integriq Rules Controller.
 *
 * Placeholder controller backing the rules tab of the Integriq
 * Vue UI. All UI rendering is delegated to UiController; this class
 * is retained for Nextcloud's DI container registration only.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Controller;

use OCP\AppFramework\Controller;
use OCP\IRequest;

/**
 * Controller for managing rules in the Integriq app.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class RulesController extends Controller {
	/**
	 * Constructor for the RuleController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 */
	public function __construct(
		$appName,
		IRequest $request,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()
}//end class
