<?php

/**
 * Integriq Open Formulieren Connection.
 *
 * Open Formulieren posts submissions without a Nextcloud session. This
 * service gives such a submission an identity the way the DSO STAM intake
 * got one (dso-intake-through-an-integriq-connection): through a `consumer`.
 * The instance's one `open-formulieren` consumer holds the webhook signature
 * trust and, in `userId`, the Nextcloud account the intake acts as.
 *
 * `authenticate()` finds that consumer, verifies the signature against its
 * trust, resolves its account and checks the account's `create` and `update`
 * rights on `openformulieren_submission`, all before the first write. A bad
 * signature throws {@see DsoSignatureException} (401). Every other problem
 * throws {@see DsoConnectionUnavailableException} with the Open Formulieren
 * channel (503, so Open Formulieren delivers again).
 *
 * Since public-webhooks-on-the-consumer-model it is one profile of
 * {@see WebhookConnection}, the mechanism every signed public webhook shares:
 * the raw consumer read of {@see DsoConnection::findConsumers()}, the rights
 * check of {@see DsoAccountRights}, and OpenRegister's `ObjectService::runAs()`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenFormulieren
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
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-intake-acts-as-the-open-formulieren-connections-account-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenFormulieren;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Intake\WebhookConnection;
use OCA\Integriq\Service\Intake\WebhookProfile;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IUser;

/**
 * Resolves, verifies and checks the identity of an Open Formulieren submission.
 *
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-intake-acts-as-the-open-formulieren-connections-account-req-006
 */
class OpenFormulierenConnection {

	/**
	 * The consumer `authorizationType` of the Open Formulieren connection.
	 *
	 * @var string
	 */
	public const AUTHORIZATION_TYPE = 'open-formulieren';

	/**
	 * The schema the intake writes.
	 *
	 * @var string
	 */
	public const SCHEMA_SUBMISSION = 'openformulieren_submission';

	/**
	 * The rights the account needs on `openformulieren_submission`.
	 *
	 * @var list<string>
	 */
	public const REQUIRED_ACTIONS = ['create', 'update'];

	/**
	 * The signature header when the trust names none.
	 *
	 * @var string
	 */
	public const DEFAULT_HEADER = 'X-OpenFormulieren-Signature';

	/**
	 * The signature scheme when the trust names none.
	 *
	 * @var string
	 */
	public const DEFAULT_SCHEME = 'openconnector';

	/**
	 * Constructor.
	 *
	 * @param WebhookConnection $webhooks      The shared consumer-model mechanism.
	 * @param ORObjectService   $objectService Saves the consumer for the settings section.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function __construct(
		private readonly WebhookConnection $webhooks,
		private readonly ORObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * The Open Formulieren profile of the shared mechanism.
	 *
	 * @return WebhookProfile
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public static function profile(): WebhookProfile {
		return new WebhookProfile(
			authorizationType: self::AUTHORIZATION_TYPE,
			channel: DsoConnectionUnavailableException::CHANNEL_OPEN_FORMULIEREN,
			label: 'Open Formulieren',
			schema: self::SCHEMA_SUBMISSION,
			requiredActions: self::REQUIRED_ACTIONS,
			legacySourceType: 'open-formulieren',
			defaultHeader: self::DEFAULT_HEADER,
			defaultScheme: self::DEFAULT_SCHEME
		);

	}//end profile()

	/**
	 * Authenticate a submission and return the identity its writes run as.
	 *
	 * @param string                   $rawBody  The exact raw request body.
	 * @param callable(string): string $headerOf Reads a request header by name.
	 *
	 * @return DsoIdentity The account and the consumer's uuid.
	 *
	 * @throws DsoConnectionUnavailableException When the connection, the account or its rights are missing.
	 * @throws DsoSignatureException When the signature does not verify.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function authenticate(string $rawBody, callable $headerOf): DsoIdentity {
		return $this->webhooks->authenticate(profile: self::profile(), rawBody: $rawBody, headerOf: $headerOf);

	}//end authenticate()

	/**
	 * The `open-formulieren` consumers on this instance, read raw.
	 *
	 * @return list<ObjectEntity> The consumers, normally zero or one.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function findConsumers(): array {
		return $this->webhooks->findConsumers(profile: self::profile());

	}//end findConsumers()

	/**
	 * The one `open-formulieren` consumer, or null when there is none.
	 *
	 * @return ObjectEntity|null The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException When two or more exist.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/design.md
	 */
	public function findConsumer(): ?ObjectEntity {
		return $this->webhooks->findConsumer(profile: self::profile());

	}//end findConsumer()

	/**
	 * Resolve a uid to an enabled Nextcloud account.
	 *
	 * @param string $userId The uid on the consumer.
	 *
	 * @return IUser The account.
	 *
	 * @throws DsoConnectionUnavailableException With reason no_account, account_unknown or account_disabled.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function resolveAccount(string $userId): IUser {
		return $this->webhooks->resolveAccount(profile: self::profile(), userId: $userId);

	}//end resolveAccount()

	/**
	 * The rights the account lacks on `openformulieren_submission`.
	 *
	 * @param string $userId The uid to check.
	 *
	 * @return list<string>|null The missing actions (empty when all are held), or null
	 *                           when OpenRegister cannot answer the question.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function missingRights(string $userId): ?array {
		return $this->webhooks->missingRights(profile: self::profile(), userId: $userId);

	}//end missingRights()

	/**
	 * Save the open-formulieren consumer as the active user, under its own RBAC.
	 *
	 * The settings section calls this as the administrator. The consumer schema
	 * is admin-only, so nobody else can.
	 *
	 * @param array<string, mixed> $data The consumer data.
	 * @param string|null          $uuid The consumer's uuid, or null to create it.
	 *
	 * @return ObjectEntity The saved consumer.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	public function saveConsumer(array $data, ?string $uuid): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: DsoConnection::REGISTER,
			schema: DsoConnection::SCHEMA_CONSUMER,
			uuid: $uuid
		);

	}//end saveConsumer()

	/**
	 * Run an operation as the account, restoring the previous user afterwards.
	 *
	 * @param IUser    $account   The account to act as.
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function runAs(IUser $account, callable $operation): mixed {
		return $this->webhooks->runAs(account: $account, operation: $operation);

	}//end runAs()
}//end class
