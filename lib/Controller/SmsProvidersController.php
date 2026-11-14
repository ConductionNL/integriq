<?php

/**
 * Integriq SMS Providers Controller.
 *
 * Answers which SMS bindings this instance carries and what configuration each
 * needs, so the source form's provider picker for a source of type `sms` is
 * built from the registry rather than from a list written beside it.
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
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Sms\SmsProviderRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Describes the SMS bindings this instance carries.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
 */
class SmsProvidersController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string              $appName     The app id.
	 * @param IRequest            $request     The request.
	 * @param SmsProviderRegistry $registry    The SMS bindings this instance carries.
	 * @param IUserSession        $userSession The session, for the authentication check.
	 * @param IL10N               $l           Translations.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SmsProviderRegistry $registry,
		private readonly IUserSession $userSession,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every SMS binding on this instance, with its name and the configuration it needs.
	 *
	 * @return JSONResponse `{providers: [{providerId, name, configSchema}]}`, or 401 without a session.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
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
