<?php

/**
 * Contract of the DigitalPostAccount.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DigitalPost
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

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * Public operations of {@see DigitalPostAccount} that other components call.
 */
interface DigitalPostAccountInterface {
	/**
	 * The consumer `authorizationType` of digital post.
	 *
	 * @var string
	 */
	public const AUTHORIZATION_TYPE = 'digital-post';

	/**
	 * The one digital post consumer, or null when there is none.
	 *
	 * @return ObjectEntity|null The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException When two or more exist.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function findConsumer(): ?ObjectEntity;

	/**
	 * The account digital post is stored as, checked.
	 *
	 * @return IUser The enabled account, holding the rights on `digitalPostMessage`.
	 *
	 * @throws DsoConnectionUnavailableException When there is no usable account.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function resolve(): IUser;

	/**
	 * Resolve a uid to an enabled account.
	 *
	 * @param string $userId The uid on the consumer.
	 *
	 * @return IUser The account.
	 *
	 * @throws DsoConnectionUnavailableException With reason no_account, account_unknown or account_disabled.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function account(string $userId): IUser;

	/**
	 * The rights the account lacks on `digitalPostMessage`.
	 *
	 * @param string $userId The uid.
	 *
	 * @return list<string>|null The missing actions, or null when OpenRegister cannot say.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function missingRights(string $userId): ?array;

	/**
	 * Run an operation as the account, restoring the previous user afterwards.
	 *
	 * @param IUser    $account   The account.
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function runAs(IUser $account, callable $operation): mixed;

	/**
	 * Run an operation as the account, or refuse out loud when there is no usable one.
	 *
	 * Without a usable account the operation does not run: the refusal is
	 * logged, the administrators are told, and `$refuse` gets the reason.
	 *
	 * @param string                 $what      What is being done, for the log (`send`, `status poll`).
	 * @param callable(): void       $operation The operation, run as the account.
	 * @param callable(string): void $refuse    Called with the reason when there is no usable account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function runOrRefuse(string $what, callable $operation, callable $refuse): void;

	/**
	 * The state of the account, for the admin setting and the setup check.
	 *
	 * @return array{configured: bool, userId: string, state: string, displayName: string, message: string}
	 *         `state` is `ok`, or the reason the account is not usable.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-the-setup-check-names-an-unusable-account
	 */
	public function describe(): array;

	/**
	 * Log a refusal and tell the administrators (once an hour per reason).
	 *
	 * @param DsoConnectionUnavailableException $exception Why there is no usable account.
	 * @param string                            $what      What was refused, for the log.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#scenario-a-missing-or-disabled-account-refuses-the-send-out-loud
	 */
	public function alert(DsoConnectionUnavailableException $exception, string $what): void;
}
