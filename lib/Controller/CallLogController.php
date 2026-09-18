<?php

/**
 * Integriq CallLogController.
 *
 * The acts on the call log: read one call behind its own permission, preview
 * a replay, replay singly or in bulk, dry run, and fire a call by hand.
 * Listing the log is the declarative page over `call_log`, so it is not here.
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
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Outbound\Call\CallReplayService;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read, replay, dry run and hand-fire endpoints for the call log.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
class CallLogController extends Controller {

	/**
	 * The ADR-023 action reading a call, request and response and all, is gated by.
	 *
	 * @var string
	 */
	public const ACTION_READ = 'call-log.read';

	/**
	 * The ADR-023 action replaying or hand-firing a call is gated by.
	 *
	 * @var string
	 */
	public const ACTION_REPLAY = 'call-log.replay';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Names the principal.
	 * @param ActionAuthService $actionAuth The ADR-023 action gate.
	 * @param CallRecorder $recorder Reads the call records.
	 * @param CallReplayService $replayService Replays, dry runs and hand-fires.
	 * @param IL10N $l Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly CallRecorder $recorder,
		private readonly CallReplayService $replayService,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * One call with its request and its response.
	 *
	 * @param string $id The call record uuid.
	 *
	 * @return JSONResponse The call.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_READ);

		try {
			$call = $this->recorder->read($id);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => $this->l->t('No such call.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['id' => $id, 'call' => $call]);

	}//end show()

	/**
	 * What a replay would send, and which mapping versions are on offer.
	 *
	 * @param string $id The call record uuid.
	 *
	 * @return JSONResponse The preview.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-replay-names-the-mapping-version-it-ran-under-req-ocd-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function preview(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_READ);

		try {
			$preview = $this->replayService->preview($id);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => $this->l->t('No such call.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($preview);

	}//end preview()

	/**
	 * Replay one call, or several, or dry run them.
	 *
	 * @param string $id The call record uuid, empty for a bulk replay.
	 *
	 * @return JSONResponse The per-item outcomes.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
	 */
	#[NoAdminRequired]
	public function replay(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_REPLAY);

		$ids = $this->request->getParam('calls', []);
		if ($id !== '') {
			$ids = [$id];
		}

		if (is_array($ids) === false || $ids === []) {
			return new JSONResponse(
				['error' => $this->l->t('Name the calls to replay.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$options = [
			'dryRun' => ($this->request->getParam('dryRun', false) === true),
			'mappingVersion' => $this->request->getParam('mappingVersion'),
		];

		try {
			$result = $this->replayService->replayAll(array_map('strval', $ids), $user->getUID(), $options);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => $this->l->t('No such call.')], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);

	}//end replay()

	/**
	 * Fire a call by hand, for something that has not happened yet.
	 *
	 * @return JSONResponse What happened.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003
	 */
	#[NoAdminRequired]
	public function fire(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_REPLAY);

		$target = (string)$this->request->getParam('target', '');
		if (trim($target) === '') {
			return new JSONResponse(
				['error' => $this->l->t('Name the target to call.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$payload = $this->request->getParam('request', []);
		if (is_array($payload) === false) {
			$payload = [];
		}

		$result = $this->replayService->fire(
			$target,
			$payload,
			$user->getUID(),
			($this->request->getParam('dryRun', false) === true)
		);

		return new JSONResponse($result, Http::STATUS_CREATED);

	}//end fire()

}//end class
