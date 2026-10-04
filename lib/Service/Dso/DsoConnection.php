<?php

/**
 * Integriq DSO Connection.
 *
 * DSO-LV pushes verzoeken without a Nextcloud session. This service gives
 * such a push an identity, the way integriq gives every outside caller one:
 * through a `consumer`. The instance's one `dso-stam` consumer holds the
 * signature trust and, in `userId`, the Nextcloud account the intake acts as.
 *
 * `authenticate()` finds that consumer, verifies the signature against its
 * trust, resolves its account and checks the account's `create` and `update`
 * rights on `dso_verzoek`, all before the first write. A bad signature throws
 * {@see DsoSignatureException} (401). Every other problem throws
 * {@see DsoConnectionUnavailableException} (503, so DSO-LV retries).
 *
 * Reads of admin configuration (the consumer, the schema) are engine reads
 * with `_rbac: false`. No write happens here. See the design's "Contract
 * gaps" for the two OpenRegister surfaces this leans on.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
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
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-stam-intake-acts-as-the-dso-connections-account-req-dso-070
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves, verifies and checks the identity of a STAM push.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-stam-intake-acts-as-the-dso-connections-account-req-dso-070
 */
class DsoConnection {

	/**
	 * The OpenRegister register slug that holds consumers and verzoeken.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The consumer schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CONSUMER = 'consumer';

	/**
	 * The schema the intake writes.
	 *
	 * @var string
	 */
	public const SCHEMA_VERZOEK = 'dso_verzoek';

	/**
	 * The consumer `authorizationType` of the DSO connection.
	 *
	 * @var string
	 */
	public const AUTHORIZATION_TYPE = 'dso-stam';

	/**
	 * The rights the account needs on `dso_verzoek`.
	 *
	 * @var list<string>
	 */
	public const REQUIRED_ACTIONS = ['create', 'update'];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService             $objectService     Engine reads of the consumer, and runAs().
	 * @param DSOSignatureVerifierService $signatureVerifier Verifies the push against the consumer's trust.
	 * @param IUserManager                $userManager       Resolves the consumer's account.
	 * @param DsoAccountRights            $rights            Checks the account's rights on `dso_verzoek`.
	 * @param LoggerInterface             $logger            Secret-free diagnostics.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly DSOSignatureVerifierService $signatureVerifier,
		private readonly IUserManager $userManager,
		private readonly DsoAccountRights $rights,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Authenticate a STAM push and return the identity its writes run as.
	 *
	 * @param string      $rawBody         The exact raw request body.
	 * @param string|null $signatureHeader The `X-DSO-Signature` header.
	 *
	 * @return DsoIdentity The account and the consumer's uuid.
	 *
	 * @throws DsoConnectionUnavailableException When the connection, the account or its rights are missing.
	 * @throws DsoSignatureException When the signature does not verify.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function authenticate(string $rawBody, ?string $signatureHeader): DsoIdentity {
		$consumer = $this->requireConsumer();
		$data = $consumer->getObject();

		$trust = $data['authorizationConfiguration'] ?? [];
		if (is_array($trust) === false) {
			$trust = [];
		}

		if ($this->signatureVerifier->verify(signatureHeader: $signatureHeader, rawBody: $rawBody, trust: $trust) === false) {
			throw new DsoSignatureException(message: 'Webhook signature validation failed');
		}

		$account = $this->resolveAccount(userId: (string)($data['userId'] ?? ''));
		$this->requireRights(account: $account);

		return new DsoIdentity(account: $account, consumerUuid: (string)$consumer->getUuid());

	}//end authenticate()

	/**
	 * The `dso-stam` consumers on this instance, read raw.
	 *
	 * Engine read of admin configuration (`_rbac: false`, `_render: false`), so
	 * the write-only trust comes back. Never a write.
	 *
	 * @return list<ObjectEntity> The consumers, normally zero or one.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function findConsumers(): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_CONSUMER,
				],
			],
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);

		$consumers = [];
		foreach ($results as $candidate) {
			if ($candidate instanceof ObjectEntity === false) {
				continue;
			}

			if (strtolower((string)($candidate->getObject()['authorizationType'] ?? '')) !== self::AUTHORIZATION_TYPE) {
				continue;
			}

			$consumers[] = $this->readRaw(consumer: $candidate);
		}

		return $consumers;

	}//end findConsumers()

	/**
	 * The one `dso-stam` consumer, or null when there is none.
	 *
	 * @return ObjectEntity|null The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException When two or more exist.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
	 */
	public function findConsumer(): ?ObjectEntity {
		$consumers = $this->findConsumers();
		if (count($consumers) > 1) {
			throw new DsoConnectionUnavailableException(
				reason: DsoConnectionUnavailableException::AMBIGUOUS_CONNECTION,
				message: count($consumers) . ' dso-stam consumers exist; the intake does not guess which account to use.'
			);
		}

		return ($consumers[0] ?? null);

	}//end findConsumer()

	/**
	 * Resolve a uid to an enabled Nextcloud account.
	 *
	 * @param string $userId The uid, from the consumer or from a queued job.
	 *
	 * @return IUser The account.
	 *
	 * @throws DsoConnectionUnavailableException With reason no_account, account_unknown or account_disabled.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function resolveAccount(string $userId): IUser {
		if ($userId === '') {
			throw new DsoConnectionUnavailableException(
				reason: DsoConnectionUnavailableException::NO_ACCOUNT,
				message: 'The DSO connection names no account to act as.'
			);
		}

		$account = $this->userManager->get($userId);
		if ($account === null) {
			throw new DsoConnectionUnavailableException(
				reason: DsoConnectionUnavailableException::ACCOUNT_UNKNOWN,
				message: 'The DSO connection account "' . $userId . '" does not exist.'
			);
		}

		if ($account->isEnabled() === false) {
			throw new DsoConnectionUnavailableException(
				reason: DsoConnectionUnavailableException::ACCOUNT_DISABLED,
				message: 'The DSO connection account "' . $userId . '" is disabled.'
			);
		}

		return $account;

	}//end resolveAccount()

	/**
	 * The rights the account lacks on `dso_verzoek`.
	 *
	 * @param string $userId The uid to check.
	 *
	 * @return list<string>|null The missing actions (empty when all are held), or null
	 *                           when OpenRegister cannot answer the question.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function missingRights(string $userId): ?array {
		return $this->rights->missing(userId: $userId, actions: self::REQUIRED_ACTIONS);

	}//end missingRights()

	/**
	 * Save the dso-stam consumer as the active user, under its own RBAC.
	 *
	 * The DSO connection settings call this as the administrator. The consumer
	 * schema is admin-only, so nobody else can.
	 *
	 * @param array<string, mixed> $data The consumer data.
	 * @param string|null          $uuid The consumer's uuid, or null to create it.
	 *
	 * @return ObjectEntity The saved consumer.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
	 */
	public function saveConsumer(array $data, ?string $uuid): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_CONSUMER,
			uuid: $uuid
		);

	}//end saveConsumer()

	/**
	 * Run an operation as the account, restoring the previous user afterwards.
	 *
	 * Delegates to OpenRegister's `ObjectService::runAs()`, the scoped identity
	 * of ADR-099 (`setVolatileActiveUser()`, restored in a `finally`).
	 *
	 * @param IUser    $account   The account to act as.
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function runAs(IUser $account, callable $operation): mixed {
		return $this->objectService->runAs($account, $operation);

	}//end runAs()

	/**
	 * Find the one `dso-stam` consumer, or fail with a reason.
	 *
	 * @return ObjectEntity The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException With reason no_connection or ambiguous_connection.
	 */
	private function requireConsumer(): ObjectEntity {
		$consumer = $this->findConsumer();
		if ($consumer === null) {
			throw new DsoConnectionUnavailableException(
				reason: DsoConnectionUnavailableException::NO_CONNECTION,
				message: 'No dso-stam consumer is configured.'
			);
		}

		return $consumer;

	}//end requireConsumer()

	/**
	 * Fail unless the account holds create and update on `dso_verzoek`.
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
			throw new DsoConnectionUnavailableException(
				reason: DsoConnectionUnavailableException::RIGHTS_UNVERIFIABLE,
				message: 'The rights of DSO connection account "' . $account->getUID() . '" could not be checked.'
			);
		}

		if ($missing !== []) {
			throw new DsoConnectionUnavailableException(
				reason: DsoConnectionUnavailableException::ACCOUNT_LACKS_RIGHTS,
				message: 'DSO connection account "' . $account->getUID() . '" lacks ' . implode(', ', $missing)
				. ' on dso_verzoek.'
			);
		}

	}//end requireRights()

	/**
	 * Re-read a consumer raw, so its write-only trust comes back.
	 *
	 * @param ObjectEntity $consumer The consumer as listed.
	 *
	 * @return ObjectEntity The raw consumer, or the listed one when the re-read fails.
	 */
	private function readRaw(ObjectEntity $consumer): ObjectEntity {
		$uuid = $consumer->getUuid();
		if ($uuid === null || $uuid === '') {
			return $consumer;
		}

		try {
			$raw = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER,
				schema: self::SCHEMA_CONSUMER,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[DsoConnection] raw consumer re-read failed; using the listed entity',
				['consumerUuid' => $uuid, 'errorClass' => get_class($exception)]
			);
			return $consumer;
		}

		if ($raw instanceof ObjectEntity === false) {
			return $consumer;
		}

		return $raw;

	}//end readRaw()
}//end class
