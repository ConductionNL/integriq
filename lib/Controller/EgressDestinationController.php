<?php

/**
 * Integriq EgressDestinationController.
 *
 * The admin endpoints of data residency: the destinations list with its
 * verdict (also the auditor's JSON export), EU-only mode, and an
 * administrator's residency on one source. Every method checks that the
 * caller is a Nextcloud administrator before it reads or writes anything.
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
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Service\Residency\EgressDestinationService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Data residency endpoints, administrators only.
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */
class EgressDestinationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName      The app id.
	 * @param IRequest                 $request      The request.
	 * @param EgressDestinationService $destinations The destinations and EU-only mode.
	 * @param IUserSession             $userSession  The signed-in user.
	 * @param IGroupManager            $groupManager Answers whether the user is an administrator.
	 * @param IL10N                    $l10n         Translates the refusals.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EgressDestinationService $destinations,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every outbound destination with its location and the verdict.
	 *
	 * @return JSONResponse The list, or 403 for a non-administrator.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function index(): JSONResponse {
		if ($this->adminId() === null) {
			return $this->forbidden();
		}

		return new JSONResponse($this->destinations->list());
	}//end index()

	/**
	 * Switch EU-only mode; switching on over a destination outside the EU is refused.
	 *
	 * @return JSONResponse The stored state, 409 naming the blocking destinations, or 403.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setEuOnly(): JSONResponse {
		if ($this->adminId() === null) {
			return $this->forbidden();
		}

		$enabled = filter_var($this->request->getParam('enabled', false), FILTER_VALIDATE_BOOLEAN);
		$result = $this->destinations->setEuOnly(enabled: $enabled);
		if ($result['blocking'] !== []) {
			$names = [];
			foreach ($result['blocking'] as $destination) {
				$names[] = $destination['name'];
				if ($destination['name'] === '') {
					$names[array_key_last($names)] = $destination['host'];
				}
			}

			return new JSONResponse(
				[
					'error' => $this->l10n->t('EU only mode stays off. These destinations are not shown to be in the EU: %s', [implode(', ', $names)]),
					'blocking' => $result['blocking'],
				],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(['euOnly' => $result['euOnly']]);
	}//end setEuOnly()

	/**
	 * Record where a source sits, as an administrator.
	 *
	 * @param string $id The source uuid.
	 *
	 * @return JSONResponse The stored residency, 400 for an invalid country, 404, or 403.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function setSourceResidency(string $id): JSONResponse {
		$adminId = $this->adminId();
		if ($adminId === null) {
			return $this->forbidden();
		}

		$country = strtoupper(trim((string)$this->request->getParam('country', '')));
		$evidence = trim((string)$this->request->getParam('evidence', ''));

		try {
			$residency = $this->destinations->setSourceResidency(
				sourceId: $id,
				country: $country,
				evidence: $evidence,
				userId: $adminId
			);
		} catch (InvalidArgumentException) {
			return new JSONResponse(
				['error' => $this->l10n->t('Choose a country from the list.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($residency === null) {
			return new JSONResponse(['error' => $this->l10n->t('This source does not exist.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['residency' => $residency]);
	}//end setSourceResidency()

	/**
	 * The signed-in administrator's user id, or null when the caller is not one.
	 *
	 * @return string|null
	 */
	private function adminId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return null;
		}

		return $user->getUID();
	}//end adminId()

	/**
	 * The refusal for a caller who is not an administrator.
	 *
	 * @return JSONResponse 403.
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(
			['error' => $this->l10n->t('Only an administrator can manage data residency.')],
			Http::STATUS_FORBIDDEN
		);
	}//end forbidden()
}//end class
