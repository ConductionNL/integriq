<?php

/**
 * Contract of the DsoConnection.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Portal
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

namespace OCA\Integriq\Service\Portal;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * Public operations of {@see \OCA\Integriq\Service\Dso\DsoConnection} that other components call.
 */
interface PortalConnectionInterface {
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
	 * The consumer `authorizationType` of the DSO connection.
	 *
	 * @var string
	 */
	public const AUTHORIZATION_TYPE = 'dso-stam';

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
	public function authenticate(string $rawBody, ?string $signatureHeader): DsoIdentity;

	/**
	 * The consumers of one authorization type on this instance, read raw.
	 *
	 * Engine read of admin configuration (`_rbac: false`, `_render: false`), so
	 * the write-only trust comes back. Never a write. `dso-stam` by default;
	 * the Open Formulieren connection reads its `open-formulieren` consumer the
	 * same way.
	 *
	 * @param string $authorizationType The consumer `authorizationType`, compared lower-cased.
	 *
	 * @return list<ObjectEntity> The consumers, normally zero or one.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/design.md
	 */
	public function findConsumers(string $authorizationType = self::AUTHORIZATION_TYPE): array;

	/**
	 * The one `dso-stam` consumer, or null when there is none.
	 *
	 * @return ObjectEntity|null The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException When two or more exist.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
	 */
	public function findConsumer(): ?ObjectEntity;

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
	public function resolveAccount(string $userId): IUser;

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
	public function missingRights(string $userId): ?array;

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
	public function saveConsumer(array $data, ?string $uuid): ObjectEntity;

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
	public function runAs(IUser $account, callable $operation): mixed;
}
