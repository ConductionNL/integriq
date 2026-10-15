<?php

/**
 * Integriq StUF-ZDS Confirmation Controller.
 *
 * The public endpoint a case system posts its asynchronous Bv01/Fo01 to,
 * after it processed a ZDS kennisgeving integriq sent. Authentication is the
 * WS-Security UsernameToken of the source the kennisgeving went to, checked by
 * {@see \OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiver}; there is
 * no Nextcloud session, so the route is public and the receiver is its auth.
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiver;
use OCA\Integriq\Service\StufZkn\StufZknRequestBody;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * Receives asynchronous StUF-ZDS confirmations.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */
class StufZdsConfirmationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                      $appName  App identifier ("integriq").
	 * @param IRequest                    $request  Current request.
	 * @param StufZdsConfirmationReceiver $receiver Authenticates and records the confirmation.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StufZdsConfirmationReceiver $receiver,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Receive one confirmation envelope.
	 *
	 * Rate-limit rationale: the caller is a case system on its own retry
	 * schedule, as for the StUF-ZKN kennisgeving receiver.
	 *
	 * @return DataDisplayResponse A Bv03 (200) or a Fo03 (400, 422 or 503).
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function receive(): DataDisplayResponse {
		$answer = $this->receiver->receive(rawXml: $this->readRawBody());

		return new DataDisplayResponse($answer['body'], $answer['statusCode'], ['Content-Type' => 'text/xml; charset=utf-8']);
	}//end receive()

	/**
	 * Read the raw request body (a seam: `php://input` cannot be driven from a unit test).
	 *
	 * @return string The raw body, at most one byte past the ceiling (the receiver answers 413); '' when there is none.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	protected function readRawBody(): string {
		return StufZknRequestBody::read();
	}//end readRawBody()
}//end class
