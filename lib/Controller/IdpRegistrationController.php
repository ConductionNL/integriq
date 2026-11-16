<?php

/**
 * Integriq IdpRegistrationController.
 *
 * The server side of Beheer > Authenticatie (portal-idp-broker-config
 * REQ-IDPC-008). Registrations are created, edited and deleted through the
 * OpenRegister object store; this controller only lists them with the
 * readiness the runtime uses, and runs the "Test" action. Both are
 * administrator-only, and neither returns a secret.
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Auth\Idp\IdpRegistrationAdminInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists the registrations with their readiness, and tests one.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
class IdpRegistrationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                        $appName The app id.
	 * @param IRequest                      $request The request.
	 * @param IdpRegistrationAdminInterface $admin   The registrations with their readiness, and their checks.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IdpRegistrationAdminInterface $admin,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every registration with its status, the reasons for it and its metadata address,
	 * and whether the broker flag is on and Keepiq is there.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(): JSONResponse {
		return new JSONResponse($this->admin->overview());

	}//end index()

	/**
	 * Run the checks of one registration: the provider document and each required credential.
	 *
	 * @param string $id The registration's uuid.
	 *
	 * @return JSONResponse The checks, or 404 for an unknown registration.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function test(string $id): JSONResponse {
		$checks = $this->admin->checks(id: $id);
		if ($checks !== null) {
			return new JSONResponse(['id' => $id, 'checks' => $checks]);
		}

		return new JSONResponse(['error' => 'No such registration.'], Http::STATUS_NOT_FOUND);

	}//end test()

}//end class
