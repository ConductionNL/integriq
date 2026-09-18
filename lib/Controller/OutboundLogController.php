<?php

/**
 * Integriq OutboundLogController.
 *
 * The acts on the outbound communication log: read a stored body behind its
 * own permission, retry a failed send, forward a message onward, and ask when
 * a recipient was last told anything. Listing the log itself is the
 * declarative page over the `outbound_message` schema, so it is not here.
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
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Outbound\ForwardService;
use OCA\Integriq\Outbound\LastContactQuery;
use OCA\Integriq\Outbound\MessageBodyReader;
use OCA\Integriq\Outbound\OutboundRetryService;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Body, retry, forward and last-contact endpoints for the outbound log.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-sent-message-and-its-real-recipients-are-readable-behind-their-own-permission-req-ocl-002
 */
class OutboundLogController extends Controller {

	/**
	 * The ADR-023 action retrying a failed send is gated by.
	 *
	 * @var string
	 */
	public const ACTION_RETRY = 'outbound.retry';

	/**
	 * The ADR-023 action forwarding a message is gated by.
	 *
	 * @var string
	 */
	public const ACTION_FORWARD = 'outbound.forward';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Names the principal.
	 * @param ActionAuthService $actionAuth The ADR-023 action gate.
	 * @param MessageBodyReader $bodyReader Reads a stored body, for those allowed to.
	 * @param OutboundRetryService $retryService Retries through the delivery pipeline.
	 * @param ForwardService $forwardService Forwards as a new linked record.
	 * @param LastContactQuery $lastContactQuery Answers when a recipient was last told anything.
	 * @param IL10N $l Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly MessageBodyReader $bodyReader,
		private readonly OutboundRetryService $retryService,
		private readonly ForwardService $forwardService,
		private readonly LastContactQuery $lastContactQuery,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Read one message's stored body and resolved recipients.
	 *
	 * @param string $id The record uuid.
	 *
	 * @return JSONResponse The body, or a refusal.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-sent-message-and-its-real-recipients-are-readable-behind-their-own-permission-req-ocl-002
	 */
	#[NoAdminRequired]
	public function body(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$body = $this->bodyReader->read($id, $user);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => $this->l->t('No such message.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($body);

	}//end body()

	/**
	 * Retry one message, or several.
	 *
	 * @param string $id The record uuid, empty for a bulk retry.
	 *
	 * @return JSONResponse The per-item outcomes.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-failed-send-is-retried-from-the-screen-and-the-retry-is-recorded-req-ocl-003
	 */
	#[NoAdminRequired]
	public function retry(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_RETRY);

		$ids = $this->request->getParam('messages', []);
		if ($id !== '') {
			$ids = [$id];
		}

		if (is_array($ids) === false || $ids === []) {
			return new JSONResponse(
				['error' => $this->l->t('Name the messages to retry.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->retryService->retryAll(array_map('strval', $ids), $user->getUID());
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => $this->l->t('No such message.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);

	}//end retry()

	/**
	 * Forward one recorded message onward.
	 *
	 * @param string $id The record uuid.
	 *
	 * @return JSONResponse The forward record, or a refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-message-is-forwarded-onward-and-the-forwarding-is-a-record-req-ocl-004
	 */
	#[NoAdminRequired]
	public function forward(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_FORWARD);

		$recipients = $this->request->getParam('recipients', []);
		if (is_array($recipients) === false) {
			$recipients = [];
		}

		try {
			$forward = $this->forwardService->forward(
				$id,
				$recipients,
				$user->getUID(),
				(string)$this->request->getParam('note', ''),
				($this->request->getParam('channel') === null ? null : (string)$this->request->getParam('channel')),
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => $this->l->t('No such message.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			['id' => (string)$forward->getUuid(), 'message' => $forward->getObject()],
			Http::STATUS_CREATED
		);

	}//end forward()

	/**
	 * When was this recipient last told anything about this subject.
	 *
	 * @return JSONResponse The answer, which may be "never".
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-log-answers-when-a-recipient-was-last-told-anything-req-ocl-006
	 */
	#[NoAdminRequired]
	public function lastContact(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$subjectRef = (string)$this->request->getParam('subjectRef', '');
		$recipient = (string)$this->request->getParam('recipient', '');
		if (trim($subjectRef) === '' || trim($recipient) === '') {
			return new JSONResponse(
				['error' => $this->l->t('Name both the subject and the recipient.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($this->lastContactQuery->lastContact($subjectRef, $recipient));

	}//end lastContact()

}//end class
