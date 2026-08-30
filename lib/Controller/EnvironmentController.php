<?php

/**
 * Integriq environment CRUD controller.
 *
 * Thin, routed wrapper over {@see \OCA\Integriq\Service\EnvironmentService}
 * (environments-and-promotion REQ-001/REQ-005). Environment CRUD is gated by
 * the ADR-023 `environment.manage` action key, distinct from the
 * `environment.promote` key that gates the promotion endpoints on
 * {@see PromotionController}.
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
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EnvironmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing environment list (REQ-001) and create.
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
 */
class EnvironmentController extends Controller {
	/**
	 * Constructor for the EnvironmentController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param EnvironmentService $environmentService The environment CRUD service.
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 *
	 * @return void
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly EnvironmentService $environmentService,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List registered environments (REQ-001).
	 *
	 * `#[NoAdminRequired]` + the ADR-023 `environment.manage` action gate in
	 * the body (hydra no-admin-idor gate).
	 *
	 * @return JSONResponse The list of environment objects.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'environment.manage');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$environments = array_map(
			static fn ($entity) => $entity->getObject() + ['uuid' => $entity->getUuid()],
			$this->environmentService->list()
		);

		return new JSONResponse(['results' => $environments, 'total' => count($environments)]);
	}//end index()

	/**
	 * Create an environment (REQ-001 scenario 1).
	 *
	 * `#[NoAdminRequired]` + the ADR-023 `environment.manage` action gate in
	 * the body (hydra no-admin-idor gate).
	 *
	 * @return JSONResponse The created environment object.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-creating-an-environment-requires-an-existing-source-reference
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'environment.manage');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$data = $this->request->getParams();

		try {
			$environment = $this->environmentService->create(data: $data);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new JSONResponse(
				['error' => $this->l->t('Could not create environment: %s', [$e->getMessage()])],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($environment->getObject() + ['uuid' => $environment->getUuid()], Http::STATUS_CREATED);
	}//end create()
}//end class
