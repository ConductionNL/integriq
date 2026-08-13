<?php

/**
 * OpenConnector NotifyNL Controller.
 *
 * REST controller for the notifynl-sms-channel: the send endpoint sibling apps
 * (e.g. procest) call directly over an authenticated NC session (mirrors
 * `PeppolController::participants()`), a status-polling endpoint, and the
 * signed inbound NotifyNL delivery-status webhook (mirrors
 * `PeppolController::inbound()`).
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
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\SmsProviderException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\SmsDispatchService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
	 * Constructor.
	 *
	 * @param string $appName App identifier ("openconnector").
	 * @param IRequest $request Current request.
	 * @param SmsDispatchService $dispatchService Send / status-poll / callback logic.
	 * @param WebhookSignatureService $signatureService HMAC verification for the inbound webhook.
	 * @param IUserSession $userSession The user session (send/status endpoints).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SmsDispatchService $dispatchService,
		private readonly WebhookSignatureService $signatureService,
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
	 * Expected JSON body: `{to, body, templateId, personalisation, sourceApp, objectUri}`.
	 *
	 * @return JSONResponse The created `sms_message` record, or a 400/502 error envelope.
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
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function inbound(): JSONResponse {
		$rawBody = $this->getRawContent();

		try {
			$source = $this->dispatchService->resolveActiveSource();
		} catch (SmsProviderException) {
			// No source configured => no secret to verify against => fail closed.
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		$webhookConfig = ($source->getObject()['configuration']['webhookSignature'] ?? []);
		$scheme = ($webhookConfig['scheme'] ?? 'openconnector');
		$secret = (string)($webhookConfig['secret'] ?? '');
		$headerName = ($webhookConfig['header'] ?? 'X-OpenConnector-Signature');
		$tolerance = (int)($webhookConfig['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS);

		$headerValue = (string)$this->request->getHeader($headerName);

		$verified = $this->signatureService->verify(
			rawBody: $rawBody,
			headerValue: $headerValue,
			config: ['scheme' => $scheme, 'secret' => $secret, 'toleranceSeconds' => $tolerance]
		);

		if ($verified === false) {
			// Undifferentiated error body: never leak which check failed.
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		// Payload access goes through the framework's normalised params (NC decodes
		// a JSON body into params), NOT a second json_decode($rawBody) — signature
		// verification already ran over the exact raw bytes above.
		$body = $this->request->getParams();

		try {
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
		} catch (Throwable $exception) {
			// Never 500 on a verified callback: log and acknowledge receipt.
			$this->logger->error(
				'[NotifyNlController] inbound webhook processing failed: ' . $exception->getMessage(),
				['exception' => $exception]
			);
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
