<?php

/**
 * Integriq EndpointResponseCacheController.
 *
 * Clears one endpoint's response cache (gateway-response-cache-and-problem-errors
 * D3): the next call reaches the source again.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Endpoint\EndpointResponseCache;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * POST /api/endpoints/{id}/cache/clear, gated by the ADR-023 action `endpoint.cache.clear`.
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
 */
class EndpointResponseCacheController extends Controller {

	/**
	 * The ADR-023 action clearing an endpoint's cache is gated by (admin by default).
	 *
	 * @var string
	 */
	public const ACTION_CLEAR = 'endpoint.cache.clear';

	/**
	 * Constructor.
	 *
	 * @param string                     $appName     The app id.
	 * @param IRequest                   $request     The request.
	 * @param IUserSession               $userSession Names the principal.
	 * @param ActionAuthServiceInterface $actionAuth  The ADR-023 action gate.
	 * @param ORObjectService            $objects     Finds the endpoint, within the caller's organisation.
	 * @param EndpointResponseCache      $cache       The endpoints' response cache.
	 * @param IL10N                      $l           Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly ORObjectService $objects,
		private readonly EndpointResponseCache $cache,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Clear one endpoint's cached answers.
	 *
	 * @param string $id The endpoint uuid.
	 *
	 * @return JSONResponse 200 `{endpoint, cleared}`; 401 anonymous; 403 without the action; 404 for an unknown endpoint.
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
	 */
	#[NoAdminRequired]
	public function clear(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->actionAuth->can(user: $user, action: self::ACTION_CLEAR) === false) {
			return new JSONResponse(['error' => $this->l->t('You may not clear the cache of this endpoint.')], Http::STATUS_FORBIDDEN);
		}

		$endpoint = null;
		try {
			// The action decides who may clear; the endpoint schema is admin-only,
			// so the read runs past RBAC but stays inside the caller's organisation.
			$endpoint = $this->objects->find(id: $id, register: 'integriq', schema: 'endpoint', _rbac: false);
		} catch (Throwable) {
			$endpoint = null;
		}

		if ($endpoint === null) {
			return new JSONResponse(['error' => $this->l->t('This endpoint does not exist.')], Http::STATUS_NOT_FOUND);
		}

		$uuid = (string)$endpoint->getUuid();
		$this->cache->clear(endpointId: $uuid);

		return new JSONResponse(['endpoint' => $uuid, 'cleared' => true]);
	}//end clear()
}//end class
