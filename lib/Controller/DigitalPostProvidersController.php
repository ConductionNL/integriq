<?php

/**
 * Integriq Digital Post Providers Controller.
 *
 * Answers what digital post bindings this instance actually has, and what
 * configuration each of them needs, so the source form's provider picker is
 * built FROM the registry rather than from a list written beside it.
 *
 * That distinction is the whole point of the endpoint. A hardcoded picker goes
 * stale silently: a binding added to the registry is invisible until somebody
 * remembers the form, and a binding removed leaves an option that saves a
 * provider id nothing answers to. Neither failure says anything on screen; the
 * source simply never sends.
 *
 * Mirrors {@see IntakeChannelsController::channels()}.
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
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Describes the digital post bindings this instance carries.
 */
class DigitalPostProvidersController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param DigitalPostProviderRegistry $registry The bindings this instance carries.
	 * @param IUserSession $userSession The session, for the authentication check.
	 * @param IL10N $l Translations.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DigitalPostProviderRegistry $registry,
		private readonly IUserSession $userSession,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every digital post binding on this instance, with the configuration it needs.
	 *
	 * @return JSONResponse The provider descriptions.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function providers(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['providers' => $this->registry->describeAll()]);

	}//end providers()

}//end class
