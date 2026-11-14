<?php

/**
 * Integriq NotifyNL Controller.
 *
 * REST controller for the notifynl-sms-channel: the send endpoint sibling apps
 * (e.g. procest) call directly over an authenticated NC session (mirrors
 * `PeppolController::participants()`), a status-polling endpoint, and the
 * signed inbound NotifyNL delivery-status webhook (mirrors
 * `PeppolController::inbound()`).
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
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\SmsDispatchService;
use OCA\Integriq\Service\SmsDispatchServiceInterface;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * NotifyNL SMS send + status-poll + signed inbound delivery-status webhook.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.ElseExpression) -- mirrors PeppolController::inbound(): the
 * if/else dispatch on the inbound payload shape (a known providerMessageId vs. an
 * unrecognised payload) is more readable as one linear branch than an early return.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- a controller wires its services, gate, session, l10n and log
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */
class NotifyNlController extends Controller {

	/**
	 * The decision codes that refuse a send before any provider call (opt-out-before-send).
	 *
	 * @var array<int,string>
	 */
	public const REFUSAL_CODES = ['opted-out', 'no-consent', 'invalid-address', 'authority-unavailable'];

	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param SmsDispatchServiceInterface $dispatchService Send / status-poll / callback logic.
	 * @param WebhookGateInterface $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IUserSession $userSession The user session (send/status endpoints).
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SmsDispatchServiceInterface $dispatchService,
		private readonly WebhookGateInterface $gate,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Send one SMS message. The production binding for sibling apps' (e.g.
	 * procest's) own local send adapter — mirrors PeppolController::participants().
	 *
	 * Expected JSON body: `{to, body, templateId, personalisation, sourceApp, objectUri, category, caseRef,
	 * sourceSlug}`. `category` (default `service`) decides whether an opt-out stops the message;
	 * `sourceSlug` names the SMS source that sends (default: the first enabled one).
	 *
	 * @return JSONResponse The created `sms_message` record, a 400/502 error envelope (400 `unknown-source`
	 *                      for a slug that is not an enabled SMS source), or 409 with `error` set to the
	 *                      decision code when the opt-out list refused the send.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function send(): JSONResponse {
		$refused = $this->refusedCaller(action: 'sms.send');
		if ($refused !== null) {
			return $refused;
		}

		$params = $this->request->getParams();
		$to = (string)($params['to'] ?? '');
		if ($to === '') {
			return new JSONResponse(
				['error' => 'missing_recipient', 'message' => $this->l->t('The "to" field is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->dispatchSend(to: $to, params: $params);

	}//end send()

	/**
	 * Hand one checked send request to the dispatch service and answer its outcome.
	 *
	 * @param string              $to     The recipient.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return JSONResponse The created `sms_message` record, or the refusal or failure envelope.
	 */
	private function dispatchSend(string $to, array $params): JSONResponse {
		try {
			$message = $this->dispatchService->sendMessage(
				to: $to,
				body: (string)($params['body'] ?? ''),
				options: $this->sendOptions(params: $params),
				sourceApp: $this->optionalString(params: $params, key: 'sourceApp'),
				objectUri: $this->optionalString(params: $params, key: 'objectUri')
			);

			return new JSONResponse($message->getObject() + ['id' => $message->getUuid()]);
		} catch (SmsProviderException $exception) {
			return $this->sendFailure(exception: $exception);
		}

	}//end dispatchSend()

	/**
	 * Answer a send the dispatch service did not complete.
	 *
	 * @param SmsProviderException $exception The provider or opt-out failure.
	 *
	 * @return JSONResponse 409 with the decision code when the opt-out list refused the send, 400 for a
	 *                      source that is not an enabled SMS source, else 502.
	 */
	private function sendFailure(SmsProviderException $exception): JSONResponse {
		if ($exception->getErrorCode() === SmsDispatchService::ERROR_UNKNOWN_SOURCE) {
			return new JSONResponse(
				['error' => $exception->getErrorCode(), 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (in_array($exception->getErrorCode(), self::REFUSAL_CODES, true) === true) {
			// The opt-out list refused the send: no provider call happened.
			return new JSONResponse(
				['error' => $exception->getErrorCode(), 'message' => $exception->getMessage()],
				Http::STATUS_CONFLICT
			);
		}

		$this->logger->warning('[NotifyNlController] send failed: ' . $exception->getMessage());
		return new JSONResponse(
			['error' => 'sms_send_failed', 'message' => $exception->getMessage()],
			Http::STATUS_BAD_GATEWAY
		);

	}//end sendFailure()

	/**
	 * A request parameter as a string, or null when the request does not carry it.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 * @param string              $key    The parameter name.
	 *
	 * @return string|null The parameter, or null.
	 */
	private function optionalString(array $params, string $key): string|null {
		if (isset($params[$key]) === true) {
			return (string)$params[$key];
		}

		return null;

	}//end optionalString()

	/**
	 * The send options a request carries.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return array<string,mixed> `templateId`, `personalisation`, `category` (default `service`), `caseRef`,
	 *                             `sourceSlug`.
	 */
	private function sendOptions(array $params): array {
		$options = [];
		if (isset($params['templateId']) === true) {
			$options['templateId'] = $params['templateId'];
		}

		if (isset($params['personalisation']) === true && is_array($params['personalisation']) === true) {
			$options['personalisation'] = $params['personalisation'];
		}

		$options['category'] = $this->sendCategory(params: $params);
		if (is_string($params['caseRef'] ?? null) === true) {
			$options['caseRef'] = $params['caseRef'];
		}

		if (is_string($params['sourceSlug'] ?? null) === true && trim($params['sourceSlug']) !== '') {
			$options['sourceSlug'] = trim($params['sourceSlug']);
		}

		return $options;

	}//end sendOptions()

	/**
	 * The category a request sends under (opt-out-before-send: the category
	 * decides whether an opt-out stops the message).
	 *
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return string The trimmed category, or `service` when it is missing or blank.
	 */
	private function sendCategory(array $params): string {
		if (is_string($params['category'] ?? null) === true && trim($params['category']) !== '') {
			return trim($params['category']);
		}

		return 'service';

	}//end sendCategory()

	/**
	 * Poll the provider for a message's current delivery status.
	 *
	 * @param string $id The `sms_message` uuid.
	 *
	 * @return JSONResponse The (possibly updated) message, or a 400/404/502 error envelope.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(string $id = ''): JSONResponse {
		$refused = $this->refusedCaller(action: 'sms.status');
		if ($refused !== null) {
			return $refused;
		}

		if ($id === '') {
			return new JSONResponse(
				['error' => 'missing_id', 'message' => $this->l->t('The message id is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->pollStatus(id: $id);

	}//end status()

	/**
	 * Poll the provider for one message and answer its current state.
	 *
	 * @param string $id The `sms_message` uuid.
	 *
	 * @return JSONResponse The (possibly updated) message, or the 502 error envelope.
	 */
	private function pollStatus(string $id): JSONResponse {
		try {
			$message = $this->dispatchService->pollStatus(uuid: $id);
			return new JSONResponse($message->getObject() + ['id' => $message->getUuid()]);
		} catch (SmsProviderException $exception) {
			$this->logger->warning('[NotifyNlController] status poll failed: ' . $exception->getMessage());
			return new JSONResponse(
				['error' => 'sms_status_unavailable', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_GATEWAY
			);
		}

	}//end pollStatus()

	/**
	 * The answer for a caller who may not use the send and status endpoints.
	 *
	 * Throws the action service's refusal when the signed-in user may not run
	 * the action.
	 *
	 * @param string $action The action the endpoint needs.
	 *
	 * @return JSONResponse|null 401 without a session, or null when the caller may go on.
	 */
	private function refusedCaller(string $action): JSONResponse|null {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: $action);
		return null;

	}//end refusedCaller()

	/**
	 * Receive a NotifyNL delivery-status callback.
	 *
	 * Gated by the same HMAC scheme as `webhook_signature` (constant-time
	 * compare, timestamp tolerance): an unsigned or tampered callback is
	 * rejected 401 BEFORE any state change or event emission — mirrors
	 * PeppolController::inbound().
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure, 503 when not stored.
	 *
	 * The callback authenticates the `notifynl-webhook` consumer, and every
	 * write runs as that consumer's account. A missing connection, account or
	 * right, and a write OpenRegister refuses, answer 503 so NotifyNL retries.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 * @spec openspec/changes/notifynl-inbound-on-the-consumer-model/specs/notifynl-sms-channel/spec.md#requirement-the-status-callback-acts-as-the-notifynl-connections-account-req-020
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): JSONResponse {
		$rawBody = $this->getRawContent();

		$identity = $this->gate->identify(profile: WebhookProfilesInterface::NOTIFYNL, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		// Payload access goes through the framework's normalised params (NC decodes
		// a JSON body into params), NOT a second json_decode($rawBody) — signature
		// verification already ran over the exact raw bytes above.
		return $this->deliverCallback(identity: $identity, body: $this->request->getParams());
	}//end inbound()

	/**
	 * Record a signed status callback as the connection's account.
	 *
	 * @param DsoIdentity         $identity The consumer the callback authenticated as.
	 * @param array<string,mixed> $body     The callback's params.
	 *
	 * @return JSONResponse `{received: true}`, or 503 when the account's write was refused.
	 */
	private function deliverCallback(DsoIdentity $identity, array $body): JSONResponse {
		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($body): void {
					$this->recordStatusCallback(body: $body);
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so NotifyNL delivers again.
			return $this->gate->notStored(profile: WebhookProfilesInterface::NOTIFYNL, reason: $exception->getMessage());
		}

		return new JSONResponse(['received' => true]);

	}//end deliverCallback()

	/**
	 * Hand a callback's delivery status to the dispatch service, or log a
	 * callback that names no message.
	 *
	 * @param array<string,mixed> $body The callback's params.
	 *
	 * @return void
	 */
	private function recordStatusCallback(array $body): void {
		if (isset($body['providerMessageId']) === false) {
			$this->logger->warning(
				'[NotifyNlController] inbound webhook payload missing providerMessageId',
				['keys' => array_keys($body)]
			);
			return;
		}

		$this->dispatchService->handleStatusCallback(
			providerMessageId: (string)$body['providerMessageId'],
			status: (string)($body['status'] ?? ''),
			detail: $this->optionalString(params: $body, key: 'detail')
		);

	}//end recordStatusCallback()

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
