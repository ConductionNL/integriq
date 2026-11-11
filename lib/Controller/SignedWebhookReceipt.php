<?php

/**
 * Integriq SignedWebhookReceipt.
 *
 * Receives a signed XML delivery the way the ROD, Verzuimloket, iWMO/iJW,
 * OSO and UWLR/Edu-V receivers do: the webhook gate verifies the signature
 * over the exact raw bytes and answers 401 before any state change, the
 * handler then runs as the connection's account, a write that account is
 * refused answers 503 so the partner delivers again, and a stored delivery
 * answers `{received: true}`.
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
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Service\Intake\WebhookProfile;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Verifies and stores one signed delivery of one webhook.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
 */
final class SignedWebhookReceipt {

	/**
	 * Constructor.
	 *
	 * @param WebhookGateInterface  $gate    Verifies the signature and runs the handler as the account.
	 * @param IRequest              $request The delivery request.
	 * @param WebhookProfile|string $profile The webhook.
	 */
	public function __construct(
		private readonly WebhookGateInterface $gate,
		private readonly IRequest $request,
		private readonly WebhookProfile|string $profile,
	) {
	}//end __construct()

	/**
	 * Verify the delivery and hand its raw bytes to the handler as the connection's account.
	 *
	 * @param string   $rawBody The delivery's raw bytes, as signed.
	 * @param callable $handler Receives the raw bytes; runs as the connection's account.
	 *
	 * @return JSONResponse `{received: true}`, the gate's 401/503, or 503 when the write was refused.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	public function receive(string $rawBody, callable $handler): JSONResponse {
		$identity = $this->gate->identify(profile: $this->profile, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($handler, $rawBody): void {
					$handler($rawBody);
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so the partner delivers again.
			return $this->gate->notStored(profile: $this->profile, reason: $exception->getMessage());
		}//end try

		return new JSONResponse(['received' => true]);
	}//end receive()
}//end class
