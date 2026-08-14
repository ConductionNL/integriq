<?php

/**
 * OpenConnector DSO Controller
 *
 * Controller for the DSO / Omgevingsloket STAM koppelvlak: the signed
 * inbound endpoint (receives vergunningaanvragen, meldingen, and
 * informatieverzoeken from DSO-LV), plus the authenticated read/handoff/
 * outbound surface added by dso-connector-adapter — a status-read and list
 * endpoint, the handoff-trigger endpoint that executes the declared
 * `verzoek-to-case` handoff under the calling user's own session/RBAC (see
 * design.md §1 for why this is a separate, authenticated step rather than
 * automatic at webhook-receipt time), and the outbound status/besluit-post
 * endpoint.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\DsoProviderException;
use OCA\OpenConnector\Exception\DsoTranslationException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\DsoIngestService;
use OCA\OpenConnector\Service\DSOParserService;
use OCA\OpenConnector\Service\DSOSignatureVerifierService;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
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
 * Controller for the DSO STAM koppelvlak inbound endpoint plus the
 * authenticated dso-connector-adapter read/handoff/outbound surface.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the handoff-trigger error mapping
 * legitimately switches on DsoTranslationException/HandoffException/NotAuthorizedException/
 * Throwable in addition to the controller's normal HTTP/auth collaborators (mirrors
 * OpenFormulierenController's error-mapping breadth).
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- this controller now spans the inbound
 * webhook (parser/signatureVerifier) and the authenticated read/handoff/outbound surface
 * (ingestService/actionAuth/userSession/l) added by dso-connector-adapter; splitting it would
 * fragment one cohesive DSO feature into two controllers for no behavioural benefit.
 */
class DSOController extends Controller {
	/**
	 * DSOController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request Request object.
	 * @param DSOParserService $parser The DSO payload parser service.
	 * @param LoggerInterface $logger Logger for error handling.
	 * @param DSOSignatureVerifierService $signatureVerifier PKIoverheid / HMAC webhook signature verifier.
	 * @param DsoIngestService $ingestService dso_verzoek persistence, mapping, handoff, outbound.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IUserSession $userSession The user session (status/list/handoff/outbound
	 *                                  endpoints).
	 * @param IL10N $l The localization service.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DSOParserService $parser,
		private readonly LoggerInterface $logger,
		private readonly DSOSignatureVerifierService $signatureVerifier,
		private readonly DsoIngestService $ingestService,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Receive a DSO-verzoek via the STAM koppelvlak.
	 *
	 * Accepts POST requests with DSO-verzoek payloads (JSON or XML),
	 * validates the request signature and payload schema, and returns
	 * HTTP 202 Accepted with the verzoekId for asynchronous processing.
	 *
	 * The route is registered in `appinfo/routes.php` guarded by
	 * {@see DSOSignatureVerifierService}: every request MUST carry an
	 * `X-DSO-Signature` header that cryptographically verifies (HMAC
	 * shared-secret in pre-production, PKIoverheid certificate-chain RSA in
	 * production) before the payload is parsed. The `#[PublicPage]` /
	 * `#[NoCSRFRequired]` attributes are present because the endpoint
	 * authenticates via webhook signature, not NC session — the hydra
	 * semantic-auth gate (`public-page-annotation-with-auth-body`) is
	 * satisfied because the signature check IS the auth body for this route.
	 *
	 * @return JSONResponse HTTP 202 on success, 400 on validation error, 401 on signature error.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-4
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function receiveVerzoek(): JSONResponse {
		$rawBody = $this->getRawContent();
		$body = $this->request->getParams();

		// Validate webhook signature over the exact raw body bytes.
		$signatureHeader = $this->request->getHeader('X-DSO-Signature');
		if ($this->signatureVerifier->verify(signatureHeader: $signatureHeader, rawBody: $rawBody) === false) {
			$this->logger->warning(
				'DSO STAM: Webhook signature validation failed',
				['hasSignatureHeader' => ($signatureHeader !== '' && $signatureHeader !== null)]
			);
			return new JSONResponse(
				data: [
					'error' => 'invalid_signature',
					'message' => 'Webhook signature validation failed',
				],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		// Validate the payload schema.
		$validationErrors = $this->parser->validatePayload(payload: $body);
		if (empty($validationErrors) === false) {
			$this->logger->info('DSO STAM: Payload validation failed', ['errors' => $validationErrors]);
			return new JSONResponse(
				data: [
					'error' => 'validation_failed',
					'errors' => $validationErrors,
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		// Parse the verzoek.
		$request = $this->parser->parseVerzoek(payload: $body);

		// Tag with environment if provided by DSO-LV.
		$environment = $this->request->getHeader('X-DSO-Environment');
		if ($environment !== '' && $environment !== null) {
			$request['environment'] = $environment;
		}

		$requestId = ($request['verzoekId'] ?? uniqid(prefix: 'dso-', more_entropy: true));

		$this->logger->info(
			'DSO STAM: Verzoek received',
			[
				'verzoekId' => $requestId,
				'type' => ($request['type'] ?? 'unknown'),
			]
		);

		// Persist as a dso_verzoek OR record (received -> mapped|failed) and
		// run the normalising translator — see dso-connector-adapter. This
		// is a fire-and-forget side effect from the webhook's point of view:
		// a persistence/translation failure is logged, never surfaced as a
		// non-202 response, matching the STAM koppelvlak's documented
		// asynchronous-processing contract (mirrors
		// IwmoIjwSyncService::receiveRetour()'s "never throws out to the
		// controller" isolation).
		try {
			$this->ingestService->ingest(parsedRequest: $request);
		} catch (Throwable $exception) {
			$this->logger->error(
				'DSO STAM: verzoek persistence/mapping failed',
				['verzoekId' => $requestId, 'exception' => $exception->getMessage()]
			);
		}

		return new JSONResponse(
			data: [
				'verzoekId' => $requestId,
				'status' => 'ontvangen',
				'message' => 'Verzoek ontvangen en wordt verwerkt',
			],
			statusCode: Http::STATUS_ACCEPTED
		);

	}//end receiveVerzoek()

	/**
	 * List `dso_verzoek` records, optionally filtered by `?status=`.
	 *
	 * @return JSONResponse `{results: [...]}`, or a 401 error envelope.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-rest-surface-to-list-and-complete-mapped-verzoeken-req-004
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listVerzoeken(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'dso.list');

		$status = $this->request->getParam('status');
		if (is_string($status) === false || $status === '') {
			$status = null;
		}

		$results = $this->ingestService->listVerzoeken(status: $status);

		return new JSONResponse(['results' => $results]);
	}//end listVerzoeken()

	/**
	 * Read one `dso_verzoek`'s current status.
	 *
	 * @param string $id The `dso_verzoek` uuid.
	 *
	 * @return JSONResponse The verzoek record, or a 401/404 error envelope.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-rest-surface-to-list-and-complete-mapped-verzoeken-req-004
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'dso.status');

		if ($id === '') {
			return new JSONResponse(
				['error' => 'missing_id', 'message' => $this->l->t('The verzoek id is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->ingestService->getVerzoek(uuid: $id);
		} catch (DsoTranslationException $exception) {
			return new JSONResponse(
				['error' => 'verzoek_not_found', 'message' => $exception->getMessage()],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($result);
	}//end status()

	/**
	 * Trigger the declared `verzoek-to-case` handoff for a `mapped`
	 * verzoek, as the authenticated caller — never a system-account
	 * shortcut (design.md §1).
	 *
	 * @param string $id The `dso_verzoek` uuid.
	 *
	 * @return JSONResponse The engine's execute() result, or a 400/401/403/404/409 error envelope.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handoff(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'dso.handoff');

		if ($id === '') {
			return new JSONResponse(
				['error' => 'missing_id', 'message' => $this->l->t('The verzoek id is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->ingestService->handoff(uuid: $id);
			return new JSONResponse($result);
		} catch (DsoTranslationException $exception) {
			return new JSONResponse(
				['error' => 'verzoek_not_ready', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (HandoffException $exception) {
			$status = Http::STATUS_CONFLICT;
			if ($exception->getErrorCode() === HandoffException::NOT_DECLARED) {
				$status = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(
				['error' => $exception->getErrorCode(), 'message' => $exception->getMessage()],
				$status
			);
		} catch (NotAuthorizedException $exception) {
			return new JSONResponse(
				['error' => 'handoff_not_authorized', 'message' => $exception->getMessage()],
				Http::STATUS_FORBIDDEN
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[DSOController] handoff failed unexpectedly: ' . $exception->getMessage(),
				['exception' => $exception]
			);
			return new JSONResponse(
				['error' => 'handoff_failed', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_GATEWAY
			);
		}//end try

	}//end handoff()

	/**
	 * Build and dispatch one outbound `status` (voortgangsinformatie) or
	 * `besluit` message for a previously received verzoek.
	 *
	 * @param string $id The `dso_verzoek` uuid.
	 *
	 * @return JSONResponse The dispatch outcome, or a 400/401/404/502/503 error envelope.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function postOutbound(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'dso.status-post');

		if ($id === '') {
			return new JSONResponse(
				['error' => 'missing_id', 'message' => $this->l->t('The verzoek id is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$type = (string)$this->request->getParam('type', 'status');
		$body = $this->request->getParams();
		unset($body['type'], $body['id']);

		try {
			$result = $this->ingestService->postOutbound(verzoekUuid: $id, type: $type, fields: $body);
			return new JSONResponse($result);
		} catch (DsoTranslationException $exception) {
			return new JSONResponse(
				['error' => 'invalid_request', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (DsoProviderException $exception) {
			$status = Http::STATUS_BAD_GATEWAY;
			if (str_contains($exception->getMessage(), 'No active DSO source') === true) {
				$status = Http::STATUS_SERVICE_UNAVAILABLE;
			}

			return new JSONResponse(
				['error' => 'outbound_failed', 'message' => $exception->getMessage()],
				$status
			);
		}//end try

	}//end postOutbound()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * Signature verification MUST run over the exact bytes DSO-LV signed,
	 * not `$this->request->getParams()` (which is decoded/normalized and
	 * would desync from the signed payload for non-form content types).
	 *
	 * @return string The raw request body.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
	 */
	private function getRawContent(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;
	}//end getRawContent()
}//end class
