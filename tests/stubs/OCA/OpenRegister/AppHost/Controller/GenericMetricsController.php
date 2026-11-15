<?php

/**
 * Test stub for OpenRegister's AppHost GenericMetricsController.
 *
 * The real class lives in the peer OpenRegister app (not in vendor). Unit tests
 * mock this stub to exercise integriq's MetricsController delegation path
 * without a full Nextcloud + OpenRegister install.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

/**
 * Minimal stand-in mirroring the engine controller's public surface.
 */
class GenericMetricsController extends Controller {
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
	 * GET /api/metrics — declarative Prometheus metrics.
	 *
	 * @return TextPlainResponse
	 */
	public function index(): TextPlainResponse {
		return new TextPlainResponse('');
	}//end index()
}//end class
