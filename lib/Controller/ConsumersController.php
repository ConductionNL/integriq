<?php

/**
 * Integriq Consumers Controller.
 *
 * Placeholder controller backing the consumers tab of the Integriq
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
 * Controller backing the consumers tab in the Integriq UI.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class ConsumersController extends Controller {
	/**
	 * Constructor for the ConsumerController.
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
