<?php

/**
 * The signed route a source calls when it destroyed one record.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\SourceDestructionService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * `POST /api/synchronizations/{id}/destroyed`, for a source without a ZGW
 * Notificaties component: one call per destroyed record, signed with the
 * synchronization's source's webhook secret.
 *
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
 */
class SourceDestroyedController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                   $appName           App identifier ("integriq").
	 * @param IRequest                 $request           The request.
	 * @param SourceDestructionService $sourceDestruction The destruction notice path (REQ-SDP-002).
	 * @param WebhookSignatureService  $signatureService  The signature check.
	 * @param IL10N                    $l                 The localization service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SourceDestructionService $sourceDestruction,
		private readonly WebhookSignatureService $signatureService,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * A source says it destroyed one record: purge, or apply the policy to, the object made from it.
	 *
	 * Public: no Nextcloud session is involved, so the signature of the
	 * synchronization's source (`configuration.webhookSignature`) is the only
	 * thing between an anonymous caller and a permanent delete. It is checked
	 * over the raw bytes BEFORE the body is read; an unknown synchronization,
	 * a source without a secret and a bad signature all get the same 401.
	 * The body carries the record's id as `originId` (the route's `{id}` is
	 * the synchronization) and an optional `reference` for the contract log.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): a source sends one call per destroyed
	 * record, so a destruction list arrives as a burst.
	 *
	 * @param string $id The synchronization.
	 *
	 * @return JSONResponse The outcome, or a 400/401/404 error envelope.
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function destroyed(string $id): JSONResponse {
		$config = $this->sourceDestruction->signatureConfig(synchronizationId: $id);
		if ($config === null) {
			// Undifferentiated: never leak whether the synchronization exists.
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		$verified = $this->signatureService->verify(
			rawBody: $this->getRawContent(),
			headerValue: (string)$this->request->getHeader($config['header']),
			config: $config
		);
		if ($verified === false) {
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		// Only now is the body read, through the framework's decoded params.
		$body = $this->request->getParams();
		$originId = (string)($body['originId'] ?? '');
		if ($originId === '') {
			return new JSONResponse(
				['error' => 'missing_origin_id', 'message' => $this->l->t('The "originId" field is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$reference = null;
		if (is_string($body['reference'] ?? null) === true && $body['reference'] !== '') {
			$reference = $body['reference'];
		}

		$outcome = $this->sourceDestruction->handleDestroyed(synchronizationId: $id, originId: $originId, reference: $reference);
		if ($outcome['outcome'] === SynchronizationService::DESTRUCTION_NO_CONTRACT) {
			return new JSONResponse($outcome, Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($outcome);
	}//end destroyed()

	/**
	 * The raw request body, for the signature check.
	 *
	 * Protected so a unit test can hand in the bytes php://input cannot carry.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	protected function getRawContent(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;
	}//end getRawContent()
}//end class
