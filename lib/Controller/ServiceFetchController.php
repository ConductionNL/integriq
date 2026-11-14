<?php

/**
 * Integriq Service Fetch Controller.
 *
 * Lists the service fetches an app may call, for a form designer wiring a
 * fetch into a form. Only what a designer needs is returned: slug, title,
 * description, inputs and outputs. The source, path, query and mappings stay
 * with the administrator.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-a-form-designer-can-list-the-fetches-an-app-may-call-req-sf-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\ServiceFetch\ServiceFetchCatalogue;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The designer's list of service fetches (REQ-SF-003).
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-a-form-designer-can-list-the-fetches-an-app-may-call-req-sf-003
 */
class ServiceFetchController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string              $appName The app name.
	 * @param IRequest            $request The request.
	 * @param ServiceFetchCatalogue $catalogue The service fetches.
	 * @param LoggerInterface     $logger  Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ServiceFetchCatalogue $catalogue,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The enabled fetches the app may call, projected for a designer.
	 *
	 * Any signed-in user may ask: the projection holds nothing an
	 * administrator keeps to themselves, and a designer is not an admin.
	 *
	 * @param string $app The app whose fetches to list.
	 *
	 * @return JSONResponse `{results: [...]}`, 400 without an app.
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-a-form-designer-can-list-the-fetches-an-app-may-call-req-sf-003
	 */
	#[NoAdminRequired]
	public function index(string $app = ''): JSONResponse {
		$app = trim($app);
		if ($app === '') {
			return new JSONResponse(['error' => 'app is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$results = $this->catalogue->listFor(app: $app);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: could not list service fetches', ['app' => $app, 'error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The service fetches could not be listed.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['results' => $results]);
	}//end index()
}//end class
