<?php

/**
 * OpenConnector PDOK Controller
 *
 * REST controller for the four PDOK Locatieserver proxy endpoints (suggest,
 * lookup, free-text, reverse). All endpoints require an authenticated NC
 * session; delegates to `PdokConnector` for caching, retry, breaker and
 * write-through.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Connectors\PdokConnector;
use OCA\OpenConnector\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * PDOK Locatieserver proxy controller.
 */
class PdokController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("openconnector").
	 * @param IRequest $request Current request.
	 * @param PdokConnector $pdokConnector Connector providing PDOK access.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PdokConnector $pdokConnector,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Address autocomplete (PDOK Locatieserver `/suggest`).
	 *
	 * @param string $q Partial address text (min 1 char).
	 *
	 * @return JSONResponse Normalised suggestion documents or a 400 / 503 error envelope.
	 *
	 * @spec openspec/changes/add-pdok-adapter/tasks.md#OC-8
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function suggestAction(string $q = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'pdok.suggest');

		if (trim($q) === '') {
			return new JSONResponse(
				['error' => 'missing_query', 'message_key' => 'pdok.error.missing_query'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($this->pdokConnector->suggest($q));
	}//end suggestAction()

	/**
	 * Look up a PDOK document by id.
	 *
	 * @param string $id PDOK identifier.
	 *
	 * @return JSONResponse Normalised lookup payload, or 400 / 404 / 503.
	 *
	 * @spec openspec/changes/add-pdok-adapter/tasks.md#OC-8
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function lookupAction(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'pdok.lookup');

		if (trim($id) === '') {
			return new JSONResponse(
				['error' => 'missing_query', 'message_key' => 'pdok.error.missing_query'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$payload = $this->pdokConnector->lookup($id);
		if ($payload['numFound'] === 0 && empty($payload['stale']) === true) {
			return new JSONResponse(
				['error' => 'pdok_unavailable', 'message_key' => 'pdok.unavailable'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		if ($payload['numFound'] === 0) {
			return new JSONResponse(
				['error' => 'not_found', 'message_key' => 'pdok.error.not_found'],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($payload);
	}//end lookupAction()

	/**
	 * Free-text search.
	 *
	 * @param string $q Search query.
	 * @param int $rows Page size.
	 * @param int $start Page offset.
	 *
	 * @return JSONResponse Normalised results or 400 / 503.
	 *
	 * @spec openspec/changes/add-pdok-adapter/tasks.md#OC-8
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function freeAction(string $q = '', int $rows = 10, int $start = 0): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'pdok.free');

		if (trim($q) === '') {
			return new JSONResponse(
				['error' => 'missing_query', 'message_key' => 'pdok.error.missing_query'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($this->pdokConnector->free($q, $rows, $start));
	}//end freeAction()

	/**
	 * Reverse geocode coordinates.
	 *
	 * @param float|null $lat Latitude (WGS84).
	 * @param float|null $lng Longitude (WGS84).
	 *
	 * @return JSONResponse Normalised address or 400 / 503.
	 *
	 * @spec openspec/changes/add-pdok-adapter/tasks.md#OC-8
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reverseAction(?float $lat = null, ?float $lng = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'pdok.reverse');

		if ($lat === null || $lng === null) {
			return new JSONResponse(
				['error' => 'missing_coordinates', 'message_key' => 'pdok.error.missing_coordinates'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$payload = $this->pdokConnector->reverse($lat, $lng);
		if ($payload['numFound'] === 0 && empty($payload['stale']) === true) {
			return new JSONResponse(
				['error' => 'pdok_unavailable', 'message_key' => 'pdok.unavailable'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		if ($payload['numFound'] === 0) {
			return new JSONResponse(
				['error' => 'not_found', 'message_key' => 'pdok.error.not_found'],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($payload);
	}//end reverseAction()
}//end class
