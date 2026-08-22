<?php

/**
 * Integriq StUF-ZKN Controller.
 *
 * REST/SOAP controller for the stuf-zkn-bridge: the inbound SOAP endpoint
 * accepts a `zakLk01`/`edcLk01` kennisgeving from a municipality's legacy
 * StUF-ZKN estate and replies with a `Bv03`/`Fo03` StUF reply body (mirrors
 * `PeppolController::inbound()`/`IwmoIjwController::inbound()`'s signed-
 * webhook shape, adapted to StUF's own ack/fault berichten instead of a
 * bare HTTP status); the outbound push endpoint lets sibling apps (e.g.
 * procest) register a zaak change for kennisgeving dispatch to the
 * subscribed StUF consumer (mirrors
 * `IwmoIjwController::createMessage()`/`KissController::createCustomerContact()`).
 *
 * INBOUND AUTHENTICATION: a real StUF-ZKN deployment typically establishes
 * trust at the transport layer (PKIoverheid mTLS / municipal network
 * trust), outside this app's control. This endpoint additionally requires
 * the SAME HMAC scheme `IwmoIjwController::inbound()` already uses
 * (`WebhookSignatureService`, `X-OpenConnector-Signature` over the raw XML
 * bytes) as a demonstrable, testable authentication layer in this
 * environment — see design.md "Inbound authentication".
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\StufZknSyncService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Inbound SOAP kennisgeving receiver + authenticated outbound push endpoint for stuf-zkn-bridge.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md
 */
class StufZknController extends Controller {

	/**
	 * `text/xml` response content type used for every StUF SOAP reply.
	 *
	 * @var string
	 */
	private const XML_CONTENT_TYPE = 'text/xml; charset=utf-8';

	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param StufZknSyncService $syncService Inbound/outbound orchestration logic.
	 * @param WebhookSignatureService $signatureService HMAC verification for the inbound endpoint.
	 * @param IUserSession $userSession The user session (push endpoint).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StufZknSyncService $syncService,
		private readonly WebhookSignatureService $signatureService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Receive an inbound StUF-ZKN SOAP kennisgeving (`zakLk01`/`edcLk01`).
	 *
	 * Gated by the same HMAC scheme as `IwmoIjwController::inbound()`
	 * (constant-time compare, timestamp tolerance): an unsigned or tampered
	 * request is rejected 401 BEFORE any XML is even parsed. The
	 * `#[PublicPage]`/`#[NoCSRFRequired]` attributes are present because
	 * this endpoint authenticates via webhook signature, not NC session —
	 * the signature check IS the auth body for this route.
	 *
	 * @return DataDisplayResponse|JSONResponse A `Bv03`/`Fo03` StUF reply body (200), or a
	 *                                          401 JSON error envelope on signature failure.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-inbound-soap-endpoint-with-bv03-fo03-shaping-req-005
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): DataDisplayResponse|JSONResponse {
		$rawBody = $this->getRawContent();

		try {
			$source = $this->syncService->resolveActiveSource();
		} catch (StufZknProviderException) {
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

		// Signature verification runs over the exact raw bytes; the
		// kennisgeving is XML (not JSON), so the body is passed to the sync
		// service verbatim — never a second decode pass. receiveInbound()
		// never throws: any internal failure is already shaped into a Fo03.
		$replyXml = $this->syncService->receiveInbound(soapXml: $rawBody);

		return new DataDisplayResponse($replyXml, Http::STATUS_OK, ['Content-Type' => self::XML_CONTENT_TYPE]);
	}//end inbound()

	/**
	 * Register one outbound `zakLk01` kennisgeving for a zaak create/update/status change.
	 *
	 * Expected JSON body: `{zaak: {...see design.md's outbound field table}, verwerkingssoort:
	 * "T"|"W"|"V"}`.
	 *
	 * @return JSONResponse `{referentienummer, ref}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function outbound(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'stuf-zkn.push');

		$params = $this->request->getParams();
		$case = (array)($params['zaak'] ?? []);
		$processingKind = (string)($params['verwerkingssoort'] ?? '');
		if ($case === [] || $processingKind === '') {
			return new JSONResponse(
				[
					'error' => 'missing_fields',
					'message' => $this->l->t('The "zaak" and "verwerkingssoort" fields are required'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->syncService->sendNotification(case: $case, processingKind: $processingKind);
			return new JSONResponse($result);
		} catch (StufZknTranslationException $exception) {
			return new JSONResponse(
				['error' => 'invalid_kennisgeving', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (StufZknProviderException $exception) {
			$this->logger->warning('[StufZknController] send failed: ' . $exception->getMessage());

			$status = Http::STATUS_BAD_GATEWAY;
			$code = 'stuf_zkn_send_failed';
			if (str_contains($exception->getMessage(), 'No active StUF-ZKN source') === true) {
				$status = Http::STATUS_SERVICE_UNAVAILABLE;
				$code = 'not_configured';
			}

			return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);
		}//end try

	}//end outbound()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * Signature verification MUST run over the exact bytes the sender
	 * signed, not `$this->request->getParams()` (decoded/normalized, would
	 * desync) — mirrors `IwmoIjwController::getRawContent()`.
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
