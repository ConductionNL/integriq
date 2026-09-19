<?php

/**
 * Integriq VerdictController.
 *
 * Takes a verdict from an external checker and hands it back to the app that
 * owns the object. The inbound leg is public and gated by a webhook signature
 * verified over the raw bytes before the body is read, like every other
 * inbound endpoint here.
 *
 * Integriq stores the verdict and does nothing else with it: the object it
 * judges is never changed, and what the verdict means is the owning app's
 * decision.
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

use InvalidArgumentException;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Outbound\Call\VerdictService;
use OCA\Integriq\Service\WebhookSignatureService;
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

/**
 * Inbound verdicts, and reading them back.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-an-external-verdict-is-recorded-against-the-record-it-judges-req-ocd-006
 */
class VerdictController extends Controller {

	/**
	 * The channel id the verdict endpoint's signing secret is configured under.
	 *
	 * @var string
	 */
	public const CHANNEL_ID = 'verdicts';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Names the principal reading verdicts back.
	 * @param VerdictService $verdicts Stores and reads the verdicts.
	 * @param IntakeChannelSourceResolver $sourceResolver Finds the signing secret.
	 * @param WebhookSignatureService $signatureService Verifies the signature.
	 * @param IL10N $l Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly VerdictService $verdicts,
		private readonly IntakeChannelSourceResolver $sourceResolver,
		private readonly WebhookSignatureService $signatureService,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Take a verdict from an external checker.
	 *
	 * @return JSONResponse Whether it was stored.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): JSONResponse {
		$rawBody = $this->getRawContent();
		$configuration = $this->sourceResolver->configurationFor(self::CHANNEL_ID);
		if ($configuration === null) {
			// No source, no secret to verify against: an unverifiable verdict
			// is not a verdict, and storing it would put an unsigned claim
			// beside somebody's case.
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		$signature = ($configuration['webhookSignature'] ?? []);
		if (is_array($signature) === false) {
			$signature = [];
		}

		$verified = $this->signatureService->verify(
			rawBody: $rawBody,
			headerValue: (string)$this->request->getHeader(
				(string)($signature['header'] ?? 'X-OpenConnector-Signature')
			),
			config: [
				'scheme' => (string)($signature['scheme'] ?? 'openconnector'),
				'secret' => (string)($signature['secret'] ?? ''),
				'toleranceSeconds' => (int)($signature['toleranceSeconds']
					?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS),
			]
		);

		if ($verified === false) {
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->request->getParams();

		$verdictPayload = ($body['payload'] ?? null);
		if (is_array($verdictPayload) === false) {
			$verdictPayload = [];
		}

		try {
			$verdict = $this->verdicts->record(
				(string)($body['objectRef'] ?? ''),
				(string)($body['state'] ?? ''),
				(string)($body['source'] ?? ''),
				(string)($body['reason'] ?? ''),
				$verdictPayload,
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			['id' => (string)$verdict->getUuid(), 'stored' => true],
			Http::STATUS_CREATED
		);

	}//end inbound()

	/**
	 * The verdicts recorded against one object.
	 *
	 * @return JSONResponse The verdicts, newest first.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$objectRef = (string)$this->request->getParam('objectRef', '');
		if (trim($objectRef) === '') {
			return new JSONResponse(
				['error' => $this->l->t('Name the object whose verdicts you want.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['verdicts' => $this->verdicts->forObject($objectRef)]);

	}//end index()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * @return string The raw request body.
	 *
	 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
	 */
	protected function getRawContent(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;

	}//end getRawContent()

}//end class
