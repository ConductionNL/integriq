<?php

/**
 * Contract of the WebhookGate.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Intake;

use OCA\Integriq\Service\Dso\DsoIdentity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public operations of {@see WebhookGate} that other components call.
 */
interface WebhookGateInterface {
	/**
	 * Authenticate a delivery against its connection.
	 *
	 * @param WebhookProfile|string $profile The webhook, or its consumer type.
	 * @param string                $rawBody The exact raw request body.
	 * @param IRequest              $request The request, for the signature header.
	 *
	 * @return DsoIdentity|JSONResponse The identity, or the 401/503 answer.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
	 */
	public function identify(WebhookProfile|string $profile, string $rawBody, IRequest $request): DsoIdentity|JSONResponse;

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
	public function deliver(DsoIdentity $identity, callable $operation): mixed;

	/**
	 * Answer 503 for a delivery whose work threw, log it and alert the admins.
	 *
	 * @param WebhookProfile|string $profile The webhook, or its consumer type.
	 * @param string                $reason  Why it was not stored (secret-free).
	 *
	 * @return JSONResponse The 503 answer.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-a-refused-write-is-answered-503
	 */
	public function notStored(WebhookProfile|string $profile, string $reason): JSONResponse;
}
