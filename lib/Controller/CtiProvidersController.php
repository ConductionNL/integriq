<?php

/**
 * Integriq CTI Providers Controller.
 *
 * Answers which telephony (CTI) bindings this instance has, and what
 * configuration each of them reads, so the source form's binding picker is
 * built from the bindings CtiSourceResolver resolves rather than from a list
 * written beside it. A picker that drifts from the resolver saves a binding
 * name nothing answers to, and every call event to that source is refused.
 *
 * Mirrors {@see DigitalPostProvidersController::providers()}.
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Kiss\CtiSourceResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Describes the CTI bindings this instance carries.
 */
class CtiProvidersController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string            $appName     The app id.
	 * @param IRequest          $request     The request.
	 * @param CtiSourceResolver $resolver    The resolver that holds the bindings.
	 * @param IUserSession      $userSession The session, for the authentication check.
	 * @param IL10N             $l           Translations.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CtiSourceResolver $resolver,
		private readonly IUserSession $userSession,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every CTI binding on this instance, with the configuration it reads.
	 *
	 * @return JSONResponse The binding descriptions, or 401 without a signed-in user.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function providers(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['providers' => $this->resolver->describeAll()]);

	}//end providers()

}//end class
