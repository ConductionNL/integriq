<?php

/**
 * Contract of the WebhookConnection.
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

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * Public operations of {@see WebhookConnection} that other components call.
 */
interface WebhookConnectionInterface {
	/**
	 * Authenticate a delivery and return the identity its writes run as.
	 *
	 * @param WebhookProfile           $profile  The webhook.
	 * @param string                   $rawBody  The exact raw request body.
	 * @param callable(string): string $headerOf Reads a request header by name.
	 *
	 * @return DsoIdentity The account and the consumer's uuid.
	 *
	 * @throws DsoConnectionUnavailableException When the connection, the account or its rights are missing.
	 * @throws DsoSignatureException When the signature does not verify.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
	 */
	public function authenticate(WebhookProfile $profile, string $rawBody, callable $headerOf): DsoIdentity;

	/**
	 * The consumers of the webhook on this instance, read raw.
	 *
	 * @param WebhookProfile $profile The webhook.
	 *
	 * @return list<ObjectEntity> The consumers, normally zero or one.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function findConsumers(WebhookProfile $profile): array;

	/**
	 * The one consumer of the webhook, or null when there is none.
	 *
	 * @param WebhookProfile $profile The webhook.
	 *
	 * @return ObjectEntity|null The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException When two or more exist.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function findConsumer(WebhookProfile $profile): ?ObjectEntity;

	/**
	 * Resolve a uid to an enabled Nextcloud account.
	 *
	 * @param WebhookProfile $profile The webhook.
	 * @param string         $userId  The uid on the consumer.
	 *
	 * @return IUser The account.
	 *
	 * @throws DsoConnectionUnavailableException With reason no_account, account_unknown or account_disabled.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
	 */
	public function resolveAccount(WebhookProfile $profile, string $userId): IUser;

	/**
	 * The rights the account lacks on the profile's schema.
	 *
	 * @param WebhookProfile $profile The webhook.
	 * @param string         $userId  The uid to check.
	 *
	 * @return list<string>|null The missing actions (empty when all are held), or null
	 *                           when OpenRegister cannot answer the question.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function missingRights(WebhookProfile $profile, string $userId): ?array;

	/**
	 * Run an operation as the account, restoring the previous user afterwards.
	 *
	 * The same OpenRegister `ObjectService::runAs()` the DSO intake uses
	 * (ADR-099: `setVolatileActiveUser()`, restored in a `finally`).
	 *
	 * @param IUser    $account   The account to act as.
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
	 */
	public function runAs(IUser $account, callable $operation): mixed;
}
