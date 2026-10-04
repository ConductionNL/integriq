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
 * It reuses the DSO building blocks: the raw consumer read of
 * {@see DsoConnection::findConsumers()}, the rights check of
 * {@see DsoAccountRights}, and OpenRegister's `ObjectService::runAs()`.
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
use OCA\Integriq\Service\Dso\DsoAccountRights;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IUser;
use OCP\IUserManager;

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
	 * @param DsoConnection           $consumers        The raw consumer read and runAs(), shared with DSO.
	 * @param ORObjectService         $objectService    Saves the consumer for the settings section.
	 * @param WebhookSignatureService $signatureService Verifies the submission against the consumer's trust.
	 * @param IUserManager            $userManager      Resolves the consumer's account.
	 * @param DsoAccountRights        $rights           Checks the account's rights on the submission schema.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/design.md
	 */
	public function __construct(
		private readonly DsoConnection $consumers,
		private readonly ORObjectService $objectService,
		private readonly WebhookSignatureService $signatureService,
		private readonly IUserManager $userManager,
		private readonly DsoAccountRights $rights,
	) {

	}//end __construct()

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
		$consumer = $this->requireConsumer();
		$data = $consumer->getObject();

		$trust = $this->trustOf(data: $data);
		$headerValue = (string)$headerOf((string)($trust['header'] ?? self::DEFAULT_HEADER));

		$verified = $this->signatureService->verify(
			rawBody: $rawBody,
			headerValue: $headerValue,
			config: [
				'scheme' => (string)($trust['scheme'] ?? self::DEFAULT_SCHEME),
				'secret' => (string)($trust['secret'] ?? ''),
				'toleranceSeconds' => (int)($trust['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS),
			]
		);
		if ($verified === false) {
			throw new DsoSignatureException(message: 'Open Formulieren webhook signature validation failed');
		}

		$account = $this->resolveAccount(userId: (string)($data['userId'] ?? ''));
		$this->requireRights(account: $account);

		return new DsoIdentity(account: $account, consumerUuid: (string)$consumer->getUuid());

	}//end authenticate()

	/**
	 * The `open-formulieren` consumers on this instance, read raw.
	 *
	 * @return list<ObjectEntity> The consumers, normally zero or one.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function findConsumers(): array {
		return $this->consumers->findConsumers(authorizationType: self::AUTHORIZATION_TYPE);

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
		$consumers = $this->findConsumers();
		if (count($consumers) > 1) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::AMBIGUOUS_CONNECTION,
				message: count($consumers) . ' open-formulieren consumers exist; the intake does not guess which account to use.'
			);
		}

		return ($consumers[0] ?? null);

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
		if ($userId === '') {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::NO_ACCOUNT,
				message: 'The Open Formulieren connection names no account to act as.'
			);
		}

		$account = $this->userManager->get($userId);
		if ($account === null) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::ACCOUNT_UNKNOWN,
				message: 'The Open Formulieren connection account "' . $userId . '" does not exist.'
			);
		}

		if ($account->isEnabled() === false) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::ACCOUNT_DISABLED,
				message: 'The Open Formulieren connection account "' . $userId . '" is disabled.'
			);
		}

		return $account;

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
		return $this->rights->missing(userId: $userId, actions: self::REQUIRED_ACTIONS, schema: self::SCHEMA_SUBMISSION);

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
	 * The same OpenRegister `ObjectService::runAs()` the DSO intake uses
	 * (ADR-099: `setVolatileActiveUser()`, restored in a `finally`).
	 *
	 * @param IUser    $account   The account to act as.
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function runAs(IUser $account, callable $operation): mixed {
		return $this->consumers->runAs(account: $account, operation: $operation);

	}//end runAs()

	/**
	 * The trust configuration of a consumer, as an array.
	 *
	 * @param array<string, mixed> $data The consumer data.
	 *
	 * @return array<string, mixed> The trust.
	 */
	private function trustOf(array $data): array {
		$trust = ($data['authorizationConfiguration'] ?? []);
		if (is_array($trust) === false) {
			return [];
		}

		return $trust;

	}//end trustOf()

	/**
	 * Find the one `open-formulieren` consumer, or fail with a reason.
	 *
	 * @return ObjectEntity The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException With reason no_connection or ambiguous_connection.
	 */
	private function requireConsumer(): ObjectEntity {
		$consumer = $this->findConsumer();
		if ($consumer === null) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::NO_CONNECTION,
				message: 'No open-formulieren consumer is configured.'
			);
		}

		return $consumer;

	}//end requireConsumer()

	/**
	 * Fail unless the account holds create and update on the submission schema.
	 *
	 * @param IUser $account The account.
	 *
	 * @return void
	 *
	 * @throws DsoConnectionUnavailableException With reason account_lacks_rights or rights_unverifiable.
	 */
	private function requireRights(IUser $account): void {
		$missing = $this->missingRights(userId: $account->getUID());
		if ($missing === null) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::RIGHTS_UNVERIFIABLE,
				message: 'The rights of Open Formulieren connection account "' . $account->getUID() . '" could not be checked.'
			);
		}

		if ($missing !== []) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::ACCOUNT_LACKS_RIGHTS,
				message: 'Open Formulieren connection account "' . $account->getUID() . '" lacks ' . implode(', ', $missing)
				. ' on openformulieren_submission.'
			);
		}

	}//end requireRights()

	/**
	 * Build a refusal on the Open Formulieren channel.
	 *
	 * @param string $reason  One of the reason constants.
	 * @param string $message A secret-free description.
	 *
	 * @return DsoConnectionUnavailableException The refusal.
	 */
	private function unavailable(string $reason, string $message): DsoConnectionUnavailableException {
		return new DsoConnectionUnavailableException(
			reason: $reason,
			message: $message,
			channel: DsoConnectionUnavailableException::CHANNEL_OPEN_FORMULIEREN
		);

	}//end unavailable()
}//end class
