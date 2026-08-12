<?php

/**
 * Test stub for OpenRegister's AppHost GenericHealthController.
 *
 * The real class lives in the peer OpenRegister app (not in vendor). Unit tests
 * mock or subclass this stub to exercise openconnector's HealthController
 * delegation path without a full Nextcloud + OpenRegister install.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Minimal stand-in mirroring the engine controller's public surface.
 */
class GenericHealthController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName Calling app id.
	 * @param IRequest $request HTTP request.
	 */
	public function __construct(string $appName, IRequest $request) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/health — declarative health check.
	 *
	 * @return JSONResponse
	 */
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'status' => 'healthy',
				'app' => $this->appName,
				'checks' => [],
			]
		);
	}//end index()
}//end class
