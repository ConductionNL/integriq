<?php

/**
 * Integriq Webhook Gate.
 *
 * The controller half of the consumer model, shared by every signed public
 * webhook. `identify()` authenticates a delivery through
 * {@see WebhookConnection} and turns a refusal into the answer the partner
 * gets: 401 for a bad signature, 503 (and an admin alert) when the
 * connection, its account or the account's rights are missing. `deliver()`
 * runs the webhook's work as the consumer's account. `notStored()` answers
 * 503 when that work threw, so the partner delivers again instead of
 * believing a lost delivery arrived.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Intake;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Authenticates a webhook delivery and answers its refusals.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
 */
class WebhookGate {

	/**
	 * Constructor.
	 *
	 * @param WebhookConnection   $webhooks The shared consumer-model mechanism.
	 * @param DsoConnectionAlerts $alerts   Admin alerts when a delivery is refused.
	 * @param IL10N               $l        The refusal message.
	 * @param LoggerInterface     $logger   Secret-free diagnostics.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function __construct(
		private readonly WebhookConnection $webhooks,
		private readonly DsoConnectionAlerts $alerts,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Authenticate a delivery against its connection.
	 *
	 * @param WebhookProfile $profile The webhook.
	 * @param string         $rawBody The exact raw request body.
	 * @param IRequest       $request The request, for the signature header.
	 *
	 * @return DsoIdentity|JSONResponse The identity, or the 401/503 answer.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
	 */
	public function identify(WebhookProfile $profile, string $rawBody, IRequest $request): DsoIdentity|JSONResponse {
		try {
			return $this->webhooks->authenticate(
				profile: $profile,
				rawBody: $rawBody,
				headerOf: static fn (string $name): string => (string)$request->getHeader($name)
			);
		} catch (DsoSignatureException) {
			// Undifferentiated error body: never leak which check failed.
			$this->logger->warning('[WebhookGate] ' . $profile->label . ' webhook signature validation failed');
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		} catch (DsoConnectionUnavailableException $exception) {
			$this->logger->error(
				'[WebhookGate] ' . $profile->label . ' delivery refused, the connection is not usable; answering 503 so the sender retries',
				['reason' => $exception->getReason(), 'detail' => $exception->getMessage()]
			);
			$this->alerts->notify(reason: $exception->getReason(), channel: $profile->channel);

			return $this->unavailable(error: $exception->getErrorCode());
		}//end try

	}//end identify()

	/**
	 * Run the webhook's work as the connection's account.
	 *
	 * @param DsoIdentity $identity  The authenticated identity.
	 * @param callable    $operation The work.
	 *
	 * @return mixed Whatever the work returns.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
	 */
	public function deliver(DsoIdentity $identity, callable $operation): mixed {
		return $this->webhooks->runAs(account: $identity->account, operation: $operation);

	}//end deliver()

	/**
	 * Answer 503 for a delivery whose work threw, log it and alert the admins.
	 *
	 * @param WebhookProfile $profile The webhook.
	 * @param string         $reason  Why it was not stored (secret-free).
	 *
	 * @return JSONResponse The 503 answer.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-a-refused-write-is-answered-503
	 */
	public function notStored(WebhookProfile $profile, string $reason): JSONResponse {
		$this->logger->error(
			'[WebhookGate] ' . $profile->label . ' delivery not stored, answering 503 so the sender retries',
			['exception' => $reason]
		);
		$this->alerts->notify(reason: DsoConnectionAlerts::REASON_DELIVERY_NOT_STORED, channel: $profile->channel);

		return $this->unavailable(error: $profile->channel . '_' . DsoConnectionAlerts::REASON_DELIVERY_NOT_STORED);

	}//end notStored()

	/**
	 * The 503 answer.
	 *
	 * @param string $error The machine-readable error code.
	 *
	 * @return JSONResponse
	 */
	private function unavailable(string $error): JSONResponse {
		return new JSONResponse(
			['error' => $error, 'message' => $this->l->t('The delivery could not be stored. Try again later.')],
			Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end unavailable()
}//end class
