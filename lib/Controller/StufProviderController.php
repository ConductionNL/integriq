<?php

/**
 * Integriq StUF Provider Controller.
 *
 * The public endpoints a case system posts its StUF provider requests to
 * (zakLv01, zakLk01, edcLk01 on cases; npsLv01 on persons), decision 199.
 * Authentication is the WS-Security UsernameToken of the source the route
 * names, checked by {@see \OCA\Integriq\Service\StufZkn\StufProviderReceiver};
 * there is no Nextcloud session, so the routes are public and the receiver is
 * their auth.
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
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCA\Integriq\Service\StufZkn\StufZknRequestBody;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * Receives StUF provider requests.
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */
class StufProviderController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string               $appName     App identifier ("integriq").
	 * @param IRequest             $request     Current request.
	 * @param StufProviderReceiver $receiver    Authenticates, asks the owning app and renders the answer.
	 * @param StufZknRequestBody   $requestBody Reads the request body up to the ceiling.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StufProviderReceiver $receiver,
		private readonly StufZknRequestBody $requestBody,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Receive a case request (zakLv01, zakLk01, edcLk01).
	 *
	 * Rate-limit rationale: the caller is a case system answering its own users,
	 * as for the StUF-ZKN kennisgeving receiver.
	 *
	 * @param string $sourceId The integriq StUF-ZKN source.
	 *
	 * @return DataDisplayResponse A zakLa01 or Bv01 (200), or a Fo01/Fo02.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function cases(string $sourceId): DataDisplayResponse {
		return $this->respond(sourceId: $sourceId, service: StufProviderReceiver::SERVICE_CASES);
	}//end cases()

	/**
	 * Receive a person request (npsLv01).
	 *
	 * @param string $sourceId The integriq StUF-ZKN source.
	 *
	 * @return DataDisplayResponse An npsLa01 (200), or a Fo02.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function persons(string $sourceId): DataDisplayResponse {
		return $this->respond(sourceId: $sourceId, service: StufProviderReceiver::SERVICE_PERSONS);
	}//end persons()

	/**
	 * Run the receiver and wrap its answer.
	 *
	 * @param string $sourceId The source.
	 * @param string $service  The service.
	 *
	 * @return DataDisplayResponse The SOAP answer.
	 */
	private function respond(string $sourceId, string $service): DataDisplayResponse {
		$answer = $this->receiver->receive(rawXml: $this->readRawBody(), sourceId: $sourceId, service: $service);

		return new DataDisplayResponse($answer['body'], $answer['statusCode'], ['Content-Type' => 'text/xml; charset=utf-8']);
	}//end respond()

	/**
	 * Read the raw request body (a seam: `php://input` cannot be driven from a unit test).
	 *
	 * @return string The raw body, at most one byte past the ceiling (the receiver answers 413); '' when there is none.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	protected function readRawBody(): string {
		return $this->requestBody->read();
	}//end readRawBody()
}//end class
