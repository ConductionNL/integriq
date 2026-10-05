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
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\SmsDispatchService;
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
	 * @param SmsDispatchService $dispatchService Send / status-poll / callback logic.
	 * @param WebhookGate $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IUserSession $userSession The user session (send/status endpoints).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SmsDispatchService $dispatchService,
		private readonly WebhookGate $gate,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Send one SMS message. The production binding for sibling apps' (e.g.
	 * procest's) own local send adapter — mirrors PeppolController::participants().
	 *
	 * Expected JSON body: `{to, body, templateId, personalisation, sourceApp, objectUri, category, caseRef}`.
	 * `category` (default `service`) decides whether an opt-out stops the message.
	 *
	 * @return JSONResponse The created `sms_message` record, a 400/502 error envelope, or 409 with
	 *                      `error` set to the decision code when the opt-out list refused the send.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function send(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'sms.send');

		$params = $this->request->getParams();
		$to = (string)($params['to'] ?? '');
		$body = (string)($params['body'] ?? '');
		if ($to === '') {
			return new JSONResponse(
				['error' => 'missing_recipient', 'message' => $this->l->t('The "to" field is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$options = [];
		if (isset($params['templateId']) === true) {
			$options['templateId'] = $params['templateId'];
		}

		if (isset($params['personalisation']) === true && is_array($params['personalisation']) === true) {
			$options['personalisation'] = $params['personalisation'];
		}

		// opt-out-before-send: the category decides whether an opt-out stops
		// the message. Default `service`.
		$options['category'] = 'service';
		if (isset($params['category']) === true && is_string($params['category']) === true && trim($params['category']) !== '') {
			$options['category'] = trim($params['category']);
		}

		if (isset($params['caseRef']) === true && is_string($params['caseRef']) === true) {
			$options['caseRef'] = $params['caseRef'];
		}

		$sourceApp = null;
		if (isset($params['sourceApp']) === true) {
			$sourceApp = (string)$params['sourceApp'];
		}

		$objectUri = null;
		if (isset($params['objectUri']) === true) {
			$objectUri = (string)$params['objectUri'];
		}

		try {
			$message = $this->dispatchService->sendMessage(
				to: $to,
				body: $body,
				options: $options,
				sourceApp: $sourceApp,
				objectUri: $objectUri
			);

			return new JSONResponse($message->getObject() + ['id' => $message->getUuid()]);
		} catch (SmsProviderException $exception) {
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
		}//end try

	}//end send()

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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'sms.status');

		if ($id === '') {
			return new JSONResponse(
				['error' => 'missing_id', 'message' => $this->l->t('The message id is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$message = $this->dispatchService->pollStatus(uuid: $id);
			return new JSONResponse($message->getObject() + ['id' => $message->getUuid()]);
		} catch (SmsProviderException $exception) {
			$this->logger->warning('[NotifyNlController] status poll failed: ' . $exception->getMessage());
			return new JSONResponse(
				['error' => 'sms_status_unavailable', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_GATEWAY
			);
		}//end try

	}//end status()

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

		$identity = $this->gate->identify(profile: WebhookProfiles::NOTIFYNL, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		// Payload access goes through the framework's normalised params (NC decodes
		// a JSON body into params), NOT a second json_decode($rawBody) — signature
		// verification already ran over the exact raw bytes above.
		$body = $this->request->getParams();

		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($body): void {
					if (isset($body['providerMessageId']) === true) {
						$detail = null;
						if (isset($body['detail']) === true) {
							$detail = (string)$body['detail'];
						}

						$this->dispatchService->handleStatusCallback(
							providerMessageId: (string)$body['providerMessageId'],
							status: (string)($body['status'] ?? ''),
							detail: $detail
						);
					} else {
						$this->logger->warning(
							'[NotifyNlController] inbound webhook payload missing providerMessageId',
							['keys' => array_keys($body)]
						);
					}
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so NotifyNL delivers again.
			return $this->gate->notStored(profile: WebhookProfiles::NOTIFYNL, reason: $exception->getMessage());
		}//end try

		return new JSONResponse(['received' => true]);
	}//end inbound()

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
