<?php

/**
 * Integriq KISS Controller.
 *
 * REST controller for the kiss-kcc-bridge: the push endpoint sibling apps
 * (e.g. procest's ContactMomentService) call directly over an authenticated
 * NC session to register a klantcontact in KISS and link it to a case —
 * mirrors `NotifyNlController::send()` / `PeppolController::participants()`.
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
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\CallEventNotFoundException;
use OCA\Integriq\Exception\KissProviderException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\KissSyncServiceInterface;
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
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param KissSyncServiceInterface $syncService Push/pull orchestration logic.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly KissSyncServiceInterface $syncService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
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
	 * @return JSONResponse `{id, localUuid}` on success, or a 400/404/503/502 error envelope.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
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
		$channel = $this->channelOf(params: $params);
		if ($onderwerp === '' || $channel === '') {
			return new JSONResponse(
				[
					'error' => 'missing_fields',
					'message' => $this->l->t('The "onderwerp" and "channel" fields are required'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->push(input: $this->buildPushInput(onderwerp: $onderwerp, channel: $channel, params: $params));

	}//end createCustomerContact()

	/**
	 * The contact moment's channel: as given, or the phone for a call.
	 *
	 * @param array<string,mixed> $params The request params.
	 *
	 * @return string The channel, or an empty string.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
	 */
	private function channelOf(array $params): string {
		$channel = (string)($params['channel'] ?? '');
		if ($channel === '' && (string)($params['callId'] ?? '') !== '') {
			// A contact moment for a call is on the phone channel.
			return 'telefoon';
		}

		return $channel;

	}//end channelOf()

	/**
	 * Push the contact moment and answer the result or the failure envelope.
	 *
	 * @param array<string,mixed> $input The push input.
	 *
	 * @return JSONResponse The result, a 404 for an unknown call, or a 503/502 provider failure.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
	 */
	private function push(array $input): JSONResponse {
		try {
			return new JSONResponse($this->syncService->pushCustomerContact(input: $input));
		} catch (CallEventNotFoundException $exception) {
			return new JSONResponse(
				[
					'error' => 'unknown_call',
					'message' => $this->l->t('No ended call with this call id'),
				],
				Http::STATUS_NOT_FOUND
			);
		} catch (KissProviderException $exception) {
			return $this->providerFailure(exception: $exception);
		}

	}//end push()

	/**
	 * The envelope of a failed push: 503 when no KISS source is active, else 502.
	 *
	 * @param KissProviderException $exception The provider's failure.
	 *
	 * @return JSONResponse The error envelope.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
	 */
	private function providerFailure(KissProviderException $exception): JSONResponse {
		$this->logger->warning('[KissController] push failed: ' . $exception->getMessage());

		$status = Http::STATUS_BAD_GATEWAY;
		$code = 'kiss_push_failed';
		if (str_contains($exception->getMessage(), 'No active KISS source') === true) {
			$status = Http::STATUS_SERVICE_UNAVAILABLE;
			$code = 'not_configured';
		}

		return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);

	}//end providerFailure()

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

		$stringFields = ['tekst', 'occurredOn', 'language', 'caseReference', 'caseObjectType', 'sourceApp', 'callId', 'callSourceId'];
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
