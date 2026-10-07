<?php

/**
 * Integriq Digital Post Account.
 *
 * The account digital post is stored as. A send or a status poll often runs
 * with nobody signed in (a background job, an event fired from cron), and
 * OpenRegister refuses an anonymous write. So every digital post write runs as
 * the account of the one consumer with `authorizationType` `digital-post`: the
 * consumer model of the webhooks and the DSO intake, with the same raw
 * consumer read ({@see DsoConnection::findConsumers()}), the same rights check
 * ({@see DsoAccountRights}) and OpenRegister's `ObjectService::runAs()`
 * (`setVolatileActiveUser()`, restored in a `finally`).
 *
 * A missing, ambiguous, unknown, disabled or unentitled account throws
 * {@see DsoConnectionUnavailableException} on the digital post channel. The
 * caller refuses the send, and {@see self::alert()} logs it and tells the
 * administrators, so nothing is dropped in silence.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DigitalPost
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
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\Dso\DsoAccountRights;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Finds, checks and acts as the digital post service account.
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */
class DigitalPostAccount {

	/**
	 * The consumer `authorizationType` of digital post.
	 *
	 * @var string
	 */
	public const AUTHORIZATION_TYPE = 'digital-post';

	/**
	 * What the account must be allowed to do on `digitalPostMessage`.
	 *
	 * @var list<string>
	 */
	public const REQUIRED_ACTIONS = ['create', 'read', 'update'];

	/**
	 * Constructor.
	 *
	 * @param DsoConnection       $consumers   The raw consumer read and runAs(), shared with the intakes.
	 * @param IUserManager        $userManager Resolves the consumer's account.
	 * @param DsoAccountRights    $rights      Checks the account's rights on `digitalPostMessage`.
	 * @param DsoConnectionAlerts $alerts      Tells the administrators, throttled.
	 * @param LoggerInterface     $logger      Records every refusal.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function __construct(
		private readonly DsoConnection $consumers,
		private readonly IUserManager $userManager,
		private readonly DsoAccountRights $rights,
		private readonly DsoConnectionAlerts $alerts,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The one digital post consumer, or null when there is none.
	 *
	 * @return ObjectEntity|null The consumer, read raw.
	 *
	 * @throws DsoConnectionUnavailableException When two or more exist.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function findConsumer(): ?ObjectEntity {
		$consumers = $this->consumers->findConsumers(authorizationType: self::AUTHORIZATION_TYPE);
		if (count($consumers) > 1) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::AMBIGUOUS_CONNECTION,
				message: count($consumers) . ' digital-post consumers exist; integriq does not guess which account to use.'
			);
		}

		return ($consumers[0] ?? null);

	}//end findConsumer()

	/**
	 * The account digital post is stored as, checked.
	 *
	 * @return IUser The enabled account, holding the rights on `digitalPostMessage`.
	 *
	 * @throws DsoConnectionUnavailableException When there is no usable account.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function resolve(): IUser {
		$consumer = $this->findConsumer();
		if ($consumer === null) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::NO_CONNECTION,
				message: 'No digital post account is set.'
			);
		}

		$account = $this->account(userId: (string)($consumer->getObject()['userId'] ?? ''));
		$missing = $this->missingRights(userId: $account->getUID());
		if ($missing === null) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::RIGHTS_UNVERIFIABLE,
				message: 'The rights of digital post account "' . $account->getUID() . '" could not be checked.'
			);
		}

		if ($missing !== []) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::ACCOUNT_LACKS_RIGHTS,
				message: 'Digital post account "' . $account->getUID() . '" lacks ' . implode(', ', $missing) . ' on '
				. DigitalPostService::SCHEMA . '.'
			);
		}

		return $account;

	}//end resolve()

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
	public function account(string $userId): IUser {
		if ($userId === '') {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::NO_ACCOUNT,
				message: 'The digital post connection names no account to act as.'
			);
		}

		$account = $this->userManager->get($userId);
		if ($account === null) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::ACCOUNT_UNKNOWN,
				message: 'Digital post account "' . $userId . '" does not exist.'
			);
		}

		if ($account->isEnabled() === false) {
			throw $this->unavailable(
				reason: DsoConnectionUnavailableException::ACCOUNT_DISABLED,
				message: 'Digital post account "' . $userId . '" is disabled.'
			);
		}

		return $account;

	}//end account()

	/**
	 * The rights the account lacks on `digitalPostMessage`.
	 *
	 * @param string $userId The uid.
	 *
	 * @return list<string>|null The missing actions, or null when OpenRegister cannot say.
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	public function missingRights(string $userId): ?array {
		return $this->rights->missing(userId: $userId, actions: self::REQUIRED_ACTIONS, schema: DigitalPostService::SCHEMA);

	}//end missingRights()

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
	public function runAs(IUser $account, callable $operation): mixed {
		return $this->consumers->runAs(account: $account, operation: $operation);

	}//end runAs()

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
	public function alert(DsoConnectionUnavailableException $exception, string $what): void {
		$this->logger->error(
			'digital-post.account.unavailable',
			['refused' => $what, 'reason' => $exception->getReason(), 'message' => $exception->getMessage()]
		);
		$this->alerts->notify(reason: $exception->getReason(), channel: DsoConnectionUnavailableException::CHANNEL_DIGITAL_POST);

	}//end alert()

	/**
	 * Build a refusal on the digital post channel.
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
			channel: DsoConnectionUnavailableException::CHANNEL_DIGITAL_POST
		);

	}//end unavailable()
}//end class
