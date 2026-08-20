<?php

/**
 * OpenConnector KISS Controller.
 *
 * REST controller for the kiss-kcc-bridge: the push endpoint sibling apps
 * (e.g. procest's ContactMomentService) call directly over an authenticated
 * NC session to register a klantcontact in KISS and link it to a case —
 * mirrors `NotifyNlController::send()` / `PeppolController::participants()`.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\KissProviderException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\KissSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * KISS klantcontact push (register + link-to-case) endpoint.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */
class KissController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("openconnector").
	 * @param IRequest $request Current request.
	 * @param KissSyncService $syncService Push/pull orchestration logic.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly KissSyncService $syncService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Register one klantcontact in KISS, optionally linking it to a case as
	 * an onderwerpobject. The production binding for sibling apps' (e.g.
	 * procest's ContactMomentService) own push adapter.
	 *
	 * Expected JSON body: `{onderwerp, kanaal, tekst, plaatsgevondenOp,
	 * indicatieContactGelukt, taal, betrokkene, caseReference,
	 * caseObjectType, sourceApp}`.
	 *
	 * When no active KISS source is configured this reports a clean 503
	 * `not_configured` envelope rather than a 500 crash.
	 *
	 * @return JSONResponse `{id, localUuid}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createCustomerContact(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'kiss.push');

		$params = $this->request->getParams();
		$onderwerp = (string)($params['onderwerp'] ?? '');
		$channel = (string)($params['channel'] ?? '');
		if ($onderwerp === '' || $channel === '') {
			return new JSONResponse(
				[
					'error' => 'missing_fields',
					'message' => $this->l->t('The "onderwerp" and "channel" fields are required'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$input = $this->buildPushInput(onderwerp: $onderwerp, channel: $channel, params: $params);

		try {
			$result = $this->syncService->pushCustomerContact(input: $input);
			return new JSONResponse($result);
		} catch (KissProviderException $exception) {
			$this->logger->warning('[KissController] push failed: ' . $exception->getMessage());

			$status = Http::STATUS_BAD_GATEWAY;
			$code = 'kiss_push_failed';
			if (str_contains($exception->getMessage(), 'No active KISS source') === true) {
				$status = Http::STATUS_SERVICE_UNAVAILABLE;
				$code = 'not_configured';
			}

			return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);
		}//end try

	}//end createCustomerContact()

	/**
	 * Assemble the `KissSyncService::pushCustomerContact()` input array from the
	 * validated required fields plus every optional field present in the
	 * request params.
	 *
	 * @param string $onderwerp The already-validated, non-empty subject.
	 * @param string $channel The already-validated, non-empty channel.
	 * @param array $params The full request params.
	 *
	 * @return array The assembled push input.
	 */
	private function buildPushInput(string $onderwerp, string $channel, array $params): array {
		$input = [
			'onderwerp' => $onderwerp,
			'channel' => $channel,
		];

		$stringFields = ['tekst', 'occurredOn', 'language', 'caseReference', 'caseObjectType', 'sourceApp'];
		foreach ($stringFields as $stringField) {
			if (isset($params[$stringField]) === true) {
				$input[$stringField] = (string)$params[$stringField];
			}
		}

		if (isset($params['indicationContactSucceeded']) === true) {
			$input['indicationContactSucceeded'] = (bool)$params['indicationContactSucceeded'];
		}

		if (isset($params['involvedParty']) === true && is_array($params['involvedParty']) === true) {
			$input['involvedParty'] = $params['involvedParty'];
		}

		return $input;
	}//end buildPushInput()
}//end class
