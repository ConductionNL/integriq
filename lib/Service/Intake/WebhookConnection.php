<?php

/**
 * Integriq Webhook Connection.
 *
 * The consumer model of the DSO STAM and Open Formulieren intakes, with the
 * webhook as a parameter. A partner posts without a Nextcloud session. Its
 * consumer (one per {@see WebhookProfile::$authorizationType}) holds the
 * signature trust and, in `userId`, the Nextcloud account every write runs as.
 *
 * `authenticate()` finds that consumer, verifies the signature against its
 * trust, resolves its account and checks the account's rights on the
 * profile's schema, all before the first write. A bad signature throws
 * {@see DsoSignatureException} (401). Every other problem throws
 * {@see DsoConnectionUnavailableException} on the profile's channel (503, so
 * the partner delivers again).
 *
 * It reuses the building blocks of the two intakes: the raw consumer read of
 * {@see DsoConnection::findConsumers()}, the rights check of
 * {@see DsoAccountRights}, and OpenRegister's `ObjectService::runAs()`.
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
use OCA\Integriq\Service\Dso\DsoAccountRights;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Resolves, verifies and checks the identity of a signed webhook delivery.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#requirement-a-signed-webhook-acts-as-its-consumers-account-req-cm-020
 */
class WebhookConnection {

	/**
	 * Constructor.
	 *
	 * @param DsoConnection           $consumers        The raw consumer read and runAs(), shared with DSO.
	 * @param WebhookSignatureService $signatureService Verifies a delivery against the consumer's trust.
	 * @param IUserManager            $userManager      Resolves the consumer's account.
	 * @param DsoAccountRights        $rights           Checks the account's rights on the profile's schema.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function __construct(
		private readonly DsoConnection $consumers,
		private readonly WebhookSignatureService $signatureService,
		private readonly IUserManager $userManager,
		private readonly DsoAccountRights $rights,
	) {

	}//end __construct()

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
	public function authenticate(WebhookProfile $profile, string $rawBody, callable $headerOf): DsoIdentity {
		$consumer = $this->requireConsumer(profile: $profile);
		$data = $consumer->getObject();

		$trust = ($data['authorizationConfiguration'] ?? []);
		if (is_array($trust) === false) {
			$trust = [];
		}

		$headerValue = (string)$headerOf((string)($trust['header'] ?? $profile->defaultHeader));

		$verified = $this->signatureService->verify(
			rawBody: $rawBody,
			headerValue: $headerValue,
			config: [
				'scheme' => (string)($trust['scheme'] ?? $profile->defaultScheme),
				'secret' => (string)($trust['secret'] ?? ''),
				'toleranceSeconds' => (int)($trust['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS),
			]
		);
		if ($verified === false) {
			throw new DsoSignatureException(message: $profile->label . ' webhook signature validation failed');
		}

		$account = $this->resolveAccount(profile: $profile, userId: (string)($data['userId'] ?? ''));
		$this->requireRights(profile: $profile, account: $account);

		return new DsoIdentity(account: $account, consumerUuid: (string)$consumer->getUuid());

	}//end authenticate()

	/**
	 * The consumers of the webhook on this instance, read raw.
	 *
	 * @param WebhookProfile $profile The webhook.
	 *
	 * @return list<ObjectEntity> The consumers, normally zero or one.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function findConsumers(WebhookProfile $profile): array {
		return $this->consumers->findConsumers(authorizationType: $profile->authorizationType);

	}//end findConsumers()

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
	public function findConsumer(WebhookProfile $profile): ?ObjectEntity {
		$consumers = $this->findConsumers(profile: $profile);
		if (count($consumers) > 1) {
			throw $this->unavailable(
				profile: $profile,
				reason: DsoConnectionUnavailableException::AMBIGUOUS_CONNECTION,
				message: count($consumers) . ' ' . $profile->authorizationType . ' consumers exist; the webhook does not guess which account to use.'
			);
		}

		return ($consumers[0] ?? null);

	}//end findConsumer()

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
	public function resolveAccount(WebhookProfile $profile, string $userId): IUser {
		if ($userId === '') {
			throw $this->unavailable(
				profile: $profile,
				reason: DsoConnectionUnavailableException::NO_ACCOUNT,
				message: 'The ' . $profile->label . ' connection names no account to act as.'
			);
		}

		$account = $this->userManager->get($userId);
		if ($account === null) {
			throw $this->unavailable(
				profile: $profile,
				reason: DsoConnectionUnavailableException::ACCOUNT_UNKNOWN,
				message: 'The ' . $profile->label . ' connection account "' . $userId . '" does not exist.'
			);
		}

		if ($account->isEnabled() === false) {
			throw $this->unavailable(
				profile: $profile,
				reason: DsoConnectionUnavailableException::ACCOUNT_DISABLED,
				message: 'The ' . $profile->label . ' connection account "' . $userId . '" is disabled.'
			);
		}

		return $account;

	}//end resolveAccount()

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
	public function missingRights(WebhookProfile $profile, string $userId): ?array {
		return $this->rights->missing(userId: $userId, actions: $profile->requiredActions, schema: $profile->schema);

	}//end missingRights()

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
	public function runAs(IUser $account, callable $operation): mixed {
		return $this->consumers->runAs(account: $account, operation: $operation);

	}//end runAs()

	/**
	 * Find the one consumer of the webhook, or fail with a reason.
	 *
	 * @param WebhookProfile $profile The webhook.
	 *
	 * @return ObjectEntity The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException With reason no_connection or ambiguous_connection.
	 */
	private function requireConsumer(WebhookProfile $profile): ObjectEntity {
		$consumer = $this->findConsumer(profile: $profile);
		if ($consumer === null) {
			throw $this->unavailable(
				profile: $profile,
				reason: DsoConnectionUnavailableException::NO_CONNECTION,
				message: 'No ' . $profile->authorizationType . ' consumer is configured.'
			);
		}

		return $consumer;

	}//end requireConsumer()

	/**
	 * Fail unless the account holds the profile's rights on its schema.
	 *
	 * @param WebhookProfile $profile The webhook.
	 * @param IUser          $account The account.
	 *
	 * @return void
	 *
	 * @throws DsoConnectionUnavailableException With reason account_lacks_rights or rights_unverifiable.
	 */
	private function requireRights(WebhookProfile $profile, IUser $account): void {
		$missing = $this->missingRights(profile: $profile, userId: $account->getUID());
		if ($missing === null) {
			throw $this->unavailable(
				profile: $profile,
				reason: DsoConnectionUnavailableException::RIGHTS_UNVERIFIABLE,
				message: 'The rights of ' . $profile->label . ' connection account "' . $account->getUID() . '" could not be checked.'
			);
		}

		if ($missing !== []) {
			throw $this->unavailable(
				profile: $profile,
				reason: DsoConnectionUnavailableException::ACCOUNT_LACKS_RIGHTS,
				message: $profile->label . ' connection account "' . $account->getUID() . '" lacks ' . implode(', ', $missing)
				. ' on ' . $profile->schema . '.'
			);
		}

	}//end requireRights()

	/**
	 * Build a refusal on the profile's channel.
	 *
	 * @param WebhookProfile $profile The webhook.
	 * @param string         $reason  One of the reason constants.
	 * @param string         $message A secret-free description.
	 *
	 * @return DsoConnectionUnavailableException The refusal.
	 */
	private function unavailable(WebhookProfile $profile, string $reason, string $message): DsoConnectionUnavailableException {
		return new DsoConnectionUnavailableException(reason: $reason, message: $message, channel: $profile->channel);

	}//end unavailable()
}//end class
