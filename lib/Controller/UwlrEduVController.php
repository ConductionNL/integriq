<?php

/**
 * Integriq UWLR/Edu-V Controller.
 *
 * REST controller for integriq-adapter-uwlr-eduv: four push/sync
 * endpoints learniq's `uwlr`/`edu-v`/`basispoort`/`entree-content`
 * DataExchangeJobs call directly over an authenticated NC session —
 * mirrors `OsoController::export()` — plus the shared signed
 * acknowledgement/retour receiver.
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Four push/sync endpoints + one shared signed acknowledgement/retour receiver.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md
 */
class UwlrEduVController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param UwlrEduVService $uwlrEduVService Send/sync/retour orchestration logic.
	 * @param WebhookGate $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IUserSession $userSession The user session (push/sync endpoints).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly UwlrEduVService $uwlrEduVService,
		private readonly WebhookGate $gate,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Register one outbound UWLR export.
	 *
	 * @return JSONResponse `{ref, target, status}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-export-endpoint-returns-a-ref-on-success
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uwlr(): JSONResponse {
		$user = $this->requireUser();
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$this->actionAuth->requireAction(user: $user, action: 'uwlr.push');

		$params = $this->request->getParams();
		$kenmerk = (string)($params['kenmerk'] ?? '');
		$subtype = (string)($params['subtype'] ?? '');
		$payload = (array)($params['payload'] ?? []);

		if ($kenmerk === '') {
			return $this->missingFieldResponse(field: 'kenmerk');
		}

		try {
			return new JSONResponse($this->uwlrEduVService->sendUwlrExport(kenmerk: $kenmerk, subtype: $subtype, payload: $payload));
		} catch (UwlrEduVTranslationException $exception) {
			return $this->invalidExportResponse(exception: $exception);
		} catch (UwlrEduVProviderException $exception) {
			return $this->providerFailureResponse(context: 'uwlr', exception: $exception);
		}
	}//end uwlr()

	/**
	 * Register one outbound Edu-V export.
	 *
	 * @return JSONResponse `{ref, target, status}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-each-edu-v-subtype-names-its-own-targetschema
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function eduV(): JSONResponse {
		$user = $this->requireUser();
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$this->actionAuth->requireAction(user: $user, action: 'edu-v.push');

		$params = $this->request->getParams();
		$kenmerk = (string)($params['kenmerk'] ?? '');
		$dataService = (string)($params['dataService'] ?? '');
		$payload = (array)($params['payload'] ?? []);

		if ($kenmerk === '') {
			return $this->missingFieldResponse(field: 'kenmerk');
		}

		try {
			return new JSONResponse($this->uwlrEduVService->sendEduVExport(kenmerk: $kenmerk, dataService: $dataService, payload: $payload));
		} catch (UwlrEduVTranslationException $exception) {
			return $this->invalidExportResponse(exception: $exception);
		} catch (UwlrEduVProviderException $exception) {
			return $this->providerFailureResponse(context: 'edu-v', exception: $exception);
		}
	}//end eduV()

	/**
	 * Register one Basispoort sync.
	 *
	 * @return JSONResponse `{ref, target, status}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function basispoort(): JSONResponse {
		$user = $this->requireUser();
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$this->actionAuth->requireAction(user: $user, action: 'basispoort.push');

		$params = $this->request->getParams();
		$kenmerk = (string)($params['kenmerk'] ?? '');
		$payload = (array)($params['payload'] ?? []);

		if ($kenmerk === '') {
			return $this->missingFieldResponse(field: 'kenmerk');
		}

		try {
			return new JSONResponse($this->uwlrEduVService->syncBasispoort(kenmerk: $kenmerk, payload: $payload));
		} catch (UwlrEduVTranslationException $exception) {
			return $this->invalidExportResponse(exception: $exception);
		} catch (UwlrEduVProviderException $exception) {
			return $this->providerFailureResponse(context: 'basispoort', exception: $exception);
		}
	}//end basispoort()

	/**
	 * Register one Entree content sync.
	 *
	 * @return JSONResponse `{ref, target, status}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-005-entree-content-sso-hand-off-translation
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function entreeContent(): JSONResponse {
		$user = $this->requireUser();
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$this->actionAuth->requireAction(user: $user, action: 'entree-content.push');

		$params = $this->request->getParams();
		$kenmerk = (string)($params['kenmerk'] ?? '');
		$payload = (array)($params['payload'] ?? []);

		if ($kenmerk === '') {
			return $this->missingFieldResponse(field: 'kenmerk');
		}

		try {
			return new JSONResponse($this->uwlrEduVService->syncEntreeContent(kenmerk: $kenmerk, payload: $payload));
		} catch (UwlrEduVTranslationException $exception) {
			return $this->invalidExportResponse(exception: $exception);
		} catch (UwlrEduVProviderException $exception) {
			return $this->providerFailureResponse(context: 'entree-content', exception: $exception);
		}
	}//end entreeContent()

	/**
	 * Receive an inbound UWLR/Edu-V/Basispoort/Entree-content acknowledgement/retour.
	 *
	 * The delivery authenticates the `uwlr-eduv-webhook` consumer, and every write runs
	 * as that consumer's account. A missing connection, account or right, and
	 * a write OpenRegister refuses, answer 503 so the partner retries.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @contract tests/Unit/Controller/XmlWebhooksConsumerTest.php — signed delivery stored as the
	 *           connection's account, 503 without an account, 401 on a wrong signature
	 *           (data provider `webhooks()`, which calls the method by name)
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-an-unsigned-retour-is-rejected-before-processing
	 * @spec openspec/changes/uwlr-eduv-retour-on-the-consumer-model/specs/uwlr-eduv-adapter/spec.md#requirement-the-retour-acts-as-the-uwlr-and-edu-v-connections-account-req-020
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function retour(): JSONResponse {
		return $this->handleSignedInbound(
			handler: fn (string $rawBody) => $this->uwlrEduVService->receiveReturn(rawXml: $rawBody)
		);
	}//end retour()

	/**
	 * Require an authenticated user, or a ready-made 401 response.
	 *
	 * @return IUser|JSONResponse The current user, or a 401 JSONResponse.
	 */
	private function requireUser(): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		return $user;
	}//end requireUser()

	/**
	 * A 400 response naming a missing required field.
	 *
	 * @param string $field The missing field's name.
	 *
	 * @return JSONResponse The 400 error envelope.
	 */
	private function missingFieldResponse(string $field): JSONResponse {
		return new JSONResponse(
			['error' => 'missing_fields', 'message' => $this->l->t('The "%s" field is required', [$field])],
			Http::STATUS_BAD_REQUEST
		);
	}//end missingFieldResponse()

	/**
	 * A 400 response for a translation failure.
	 *
	 * @param UwlrEduVTranslationException $exception The translation failure.
	 *
	 * @return JSONResponse The 400 error envelope.
	 */
	private function invalidExportResponse(UwlrEduVTranslationException $exception): JSONResponse {
		return new JSONResponse(
			['error' => 'invalid_export', 'message' => $exception->getMessage()],
			Http::STATUS_BAD_REQUEST
		);
	}//end invalidExportResponse()

	/**
	 * A 502/503 response for a provider failure.
	 *
	 * @param string $context Log context label (the target name).
	 * @param UwlrEduVProviderException $exception The provider failure.
	 *
	 * @return JSONResponse The error envelope.
	 */
	private function providerFailureResponse(string $context, UwlrEduVProviderException $exception): JSONResponse {
		$this->logger->warning('[UwlrEduVController] ' . $context . ' failed: ' . $exception->getMessage());

		$status = Http::STATUS_BAD_GATEWAY;
		$code = 'uwlr_eduv_send_failed';
		if (str_contains($exception->getMessage(), 'No active UWLR/Edu-V source') === true) {
			$status = Http::STATUS_SERVICE_UNAVAILABLE;
			$code = 'not_configured';
		}

		return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);
	}//end providerFailureResponse()

	/**
	 * Shared HMAC-verify-then-process flow for the signed inbound endpoint.
	 *
	 * Gated by the same HMAC scheme as the `webhook_signature` rule: an
	 * unsigned or tampered request is rejected 401 BEFORE any state change.
	 * A verified request always acknowledges `{received: true}`, even when
	 * `$handler` fails internally (never a 500).
	 *
	 * The delivery authenticates the `uwlr-eduv-webhook` consumer, and every write runs
	 * as that consumer's account. A missing connection, account or right, and
	 * a write OpenRegister refuses, answer 503 so the partner retries.
	 *
	 * @param callable $handler Receives the raw verified body; return value is ignored.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 * @spec openspec/changes/uwlr-eduv-retour-on-the-consumer-model/specs/uwlr-eduv-adapter/spec.md#requirement-the-retour-acts-as-the-uwlr-and-edu-v-connections-account-req-020
	 */
	private function handleSignedInbound(callable $handler): JSONResponse {
		$rawBody = $this->getRawContent();

		$identity = $this->gate->identify(profile: WebhookProfiles::UWLR_EDUV, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($handler, $rawBody): void {
					$handler($rawBody);
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so the UWLR/Edu-V partner delivers again.
			return $this->gate->notStored(profile: WebhookProfiles::UWLR_EDUV, reason: $exception->getMessage());
		}//end try

		return new JSONResponse(['received' => true]);
	}//end handleSignedInbound()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * @return string The raw request body.
	 */
	private function getRawContent(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;
	}//end getRawContent()
}//end class
