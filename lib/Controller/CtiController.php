<?php

/**
 * Integriq CTI (telephony) Controller.
 *
 * Where a phone system posts its call events. No Nextcloud session is
 * involved: a PBX posts here directly, authenticated by the consumer apiKey
 * mechanism, and the source it names decides which provider reads the payload.
 *
 * Mirrors {@see NotificatiesSubscriberController::callback()}, including the
 * things that look like details and are not:
 *
 * - The source is resolved FIRST, only to read its provider and its auth
 *   configuration. No side effect of any kind runs before verification passes.
 * - Every refusal is the same undifferentiated 401. A different status, a
 *   different body or a faster answer for an unknown source turns this into an
 *   oracle for which sources exist.
 * - The credential is read here, at the point of denial, not inside a helper,
 *   so the 401 below cannot come to read as denying on nothing.
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Kiss\CtiEventIntake;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives inbound call events from a phone system.
 */
class CtiController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param CtiEventIntake $intake Verifies, normalises, deduplicates and dispatches.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CtiEventIntake $intake,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Receive call events from a phone system.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): a busy KCC rings constantly, and one
	 * call produces up to four events. The limit is set for a switchboard, not
	 * for a webhook that fires occasionally.
	 *
	 * @param string $sourceId The CTI source the events arrived for.
	 *
	 * @return JSONResponse `{received: <count>}` on success, 401 on auth failure, 400 on a malformed body.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 600, period: 60)]
	public function events(string $sourceId): JSONResponse {
		$source = $this->intake->findSource(sourceId: $sourceId);
		if ($source === null) {
			// Undifferentiated: never leak whether the source exists.
			return $this->unauthorized();
		}

		$headers = $this->intake->headersFrom(request: $this->request);
		$rawBody = $this->rawBody();

		if ($this->intake->verify(source: $source, headers: $headers, rawBody: $rawBody) === false) {
			return $this->unauthorized();
		}

		$payload = json_decode($rawBody, true);
		if (is_array($payload) === false) {
			return new JSONResponse(['error' => 'malformed_body'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$accepted = $this->intake->handle(source: $source, sourceId: $sourceId, payload: $payload);
		} catch (Throwable $e) {
			$this->logger->error(
				'[CtiController] call event processing failed: '.$e->getMessage(),
				['exception' => $e, 'sourceId' => $sourceId]
			);

			return new JSONResponse(['error' => 'processing_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// A payload carrying nothing this integration handles answers 200 with
		// zero accepted. A refusal would make the PBX retry a keep-alive that
		// will never be wanted, forever.
		return new JSONResponse(['received' => $accepted]);

	}//end events()

	/**
	 * The request body exactly as received.
	 *
	 * Read raw rather than through getParams(), because a provider may verify
	 * a signature over the bytes, and a decoded-and-re-encoded body is not the
	 * same bytes.
	 *
	 * @return string The raw body.
	 */
	private function rawBody(): string {
		$body = file_get_contents('php://input');

		if (is_string($body) === true) {
			return $body;
		}

		return '';

	}//end rawBody()

	/**
	 * The one refusal this endpoint gives.
	 *
	 * @return JSONResponse The 401.
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);

	}//end unauthorized()

}//end class
