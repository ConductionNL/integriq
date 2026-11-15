<?php

/**
 * Integriq CloudEventReceiverController.
 *
 * The receiver URL an outside system sends CloudEvents to, one per source.
 * The webhook gate checks the source's signature or credential over the raw
 * bytes and answers 401 before anything is read. The event is then decoded in
 * structured or binary content mode, refused with 400 when a required
 * attribute is missing, stored once per source and id, and routed to the
 * matching subscriptions.
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
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Broker\CloudEventHttpBindingInterface;
use OCA\Integriq\Exception\CloudEventRefusedException;
use OCA\Integriq\Service\Event\CloudEventReceiver;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Receives CloudEvents from outside systems.
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */
class CloudEventReceiverController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                         $appName  The app name.
	 * @param IRequest                       $request  The request.
	 * @param WebhookGateInterface           $gate     Checks the source's signature or credential and runs the store as its account.
	 * @param CloudEventHttpBindingInterface $binding  Reads the event in either content mode.
	 * @param CloudEventReceiver             $receiver Stores the event once and routes it.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WebhookGateInterface $gate,
		private readonly CloudEventHttpBindingInterface $binding,
		private readonly CloudEventReceiver $receiver,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Receive one CloudEvent from a source.
	 *
	 * @param string $source The source id from the receiver URL.
	 *
	 * @return JSONResponse 202 with the stored event id, 200 for an id already received,
	 *                      400 naming what is wrong, 401 without a valid signature or credential,
	 *                      503 when it could not be stored.
	 *
	 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function receive(string $source): JSONResponse {
		$profile = WebhookProfilesInterface::CLOUDEVENTS_SOURCE_PREFIX . strtolower($source);
		$rawBody = $this->getRawContent();
		$identity = $this->gate->identify(profile: $profile, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		try {
			$cloudEvent = $this->binding->decode(
				body: $rawBody,
				headerOf: fn (string $name): string => (string)$this->request->getHeader($name)
			);
		} catch (CloudEventRefusedException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$stored = $this->gate->deliver(
				identity: $identity,
				operation: fn (): ?ObjectEntity => $this->receiver->receive(receiver: strtolower($source), cloudEvent: $cloudEvent)
			);
		} catch (Throwable $exception) {
			// Not stored: answer 503 so the source sends it again.
			return $this->gate->notStored(profile: $profile, reason: $exception->getMessage());
		}

		if ($stored === null) {
			return new JSONResponse(['received' => true, 'duplicate' => true], Http::STATUS_OK);
		}

		return new JSONResponse(['received' => true, 'id' => (string)$stored->getUuid()], Http::STATUS_ACCEPTED);

	}//end receive()

	/**
	 * The raw request body, exactly as signed. Overridable in tests.
	 *
	 * @return string
	 */
	protected function getRawContent(): string {
		return (new RawRequestBody())->read();

	}//end getRawContent()
}//end class
