<?php

/**
 * Integriq EventBrokersController.
 *
 * Lists the broker transports a subscription can publish through, so the
 * subscription form reads them instead of a fixed list.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-the-app-lists-the-broker-transports-it-has-req-ebsc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Broker\BrokerTransportRegistry;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/events/brokers.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-the-app-lists-the-broker-transports-it-has-req-ebsc-001
 */
class EventBrokersController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param BrokerTransportRegistry $brokerRegistry The broker transports this instance has.
	 * @param IUserSession $userSession The signed-in user.
	 * @param ActionAuthService $actionAuth The action check (`event.subscriptions`).
	 * @param IL10N $l The translator.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BrokerTransportRegistry $brokerRegistry,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List the broker transports: id, label, whether a topic is needed, content modes.
	 *
	 * @return JSONResponse `{results: [{id, label, needsTopic, contentModes}]}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-the-app-lists-the-broker-transports-it-has-req-ebsc-001
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'event.subscriptions');

		return new JSONResponse(['results' => $this->brokerRegistry->describeAll()]);

	}//end index()
}//end class
