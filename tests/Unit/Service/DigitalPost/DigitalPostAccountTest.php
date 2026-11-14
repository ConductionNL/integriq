<?php

/**
 * Unit tests for DigitalPostAccount: every reason the service account is
 * refused, how the refusal is reported, and the description the admin
 * setting and setup check show.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DigitalPost;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccount;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCA\Integriq\Tests\Helpers\RecordingLogger;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The account over the in-memory DSO world: real connection and rights
 * check, with OpenRegister, users and alerts as the boundaries.
 *
 * @covers \OCA\Integriq\Service\DigitalPost\DigitalPostAccount
 * @uses   \OCA\Integriq\Exception\DsoConnectionUnavailableException
 * @uses   \OCA\Integriq\Service\Dso\DsoConnection
 * @uses   \OCA\Integriq\Service\Dso\DsoAccountRights
 * @uses   \OCA\Integriq\Service\Dso\AccountRightsCheck
 */
class DigitalPostAccountTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * Alerts raised, as [reason, channel].
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private array $alerts = [];

	/**
	 * The logger.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $logger;

	/**
	 * Reset the world.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetWorld();
		$this->alerts = [];
		$this->logger = new RecordingLogger();

	}//end setUp()

	/**
	 * Add a digital-post consumer naming an account.
	 *
	 * @param string $userId The account uid.
	 * @param string $uuid The consumer uuid.
	 *
	 * @return void
	 */
	private function addConsumer(string $userId, string $uuid = 'consumer-digital-post'): void {
		$this->worldConsumers[$uuid] = [
			'name' => 'Digital post',
			'authorizationType' => DigitalPostAccount::AUTHORIZATION_TYPE,
			'userId' => $userId,
		];

	}//end addConsumer()

	/**
	 * The subject over the world.
	 *
	 * @return DigitalPostAccount
	 */
	private function account(): DigitalPostAccount {
		$connection = $this->buildWorldConnection(objectService: $this->buildWorldObjectService());
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => (array_key_exists($uid, $this->worldAccounts) === true ? $this->worldUser($uid) : null)
		);
		$alerts = $this->createMock(DsoConnectionAlerts::class);
		$alerts->method('notify')->willReturnCallback(
			function (string $reason, string $channel = 'dso'): bool {
				$this->alerts[] = [$reason, $channel];
				return true;
			}
		);

		return new DigitalPostAccount(
			consumers: $connection,
			userManager: $userManager,
			rights: (new ReflectionProperty(DsoConnection::class, 'rights'))->getValue($connection),
			alerts: $alerts,
			logger: $this->logger
		);

	}//end account()

	/**
	 * Resolve and return the refusal.
	 *
	 * @param DigitalPostAccount $account The subject.
	 *
	 * @return DsoConnectionUnavailableException The refusal.
	 */
	private function refusal(DigitalPostAccount $account): DsoConnectionUnavailableException {
		try {
			$account->resolve();
		} catch (DsoConnectionUnavailableException $exception) {
			$this->assertSame(DsoConnectionUnavailableException::CHANNEL_DIGITAL_POST, $exception->getChannel());
			return $exception;
		}

		$this->fail('The account resolved.');

	}//end refusal()

	/**
	 * Without a consumer there is no connection.
	 *
	 * @return void
	 */
	public function testNoConsumerIsNoConnection(): void {
		$account = $this->account();

		$this->assertNull($account->findConsumer());
		$refusal = $this->refusal($account);
		$this->assertSame(DsoConnectionUnavailableException::NO_CONNECTION, $refusal->getReason());
		$this->assertSame('No digital post account is set.', $refusal->getMessage());

	}//end testNoConsumerIsNoConnection()

	/**
	 * Two consumers are refused rather than guessed between, and only
	 * digital-post consumers count.
	 *
	 * @return void
	 */
	public function testTwoConsumersAreAmbiguous(): void {
		$this->addAccount(uid: 'digitalepost');
		$this->addDsoConsumer(userId: 'digitalepost');
		$this->addConsumer(userId: 'digitalepost');
		$this->assertSame('consumer-digital-post', $this->account()->findConsumer()?->getUuid());

		$this->addConsumer(userId: 'digitalepost', uuid: 'consumer-digital-post-2');
		$refusal = $this->refusal($this->account());

		$this->assertSame(DsoConnectionUnavailableException::AMBIGUOUS_CONNECTION, $refusal->getReason());
		$this->assertSame('2 digital-post consumers exist; integriq does not guess which account to use.', $refusal->getMessage());

	}//end testTwoConsumersAreAmbiguous()

	/**
	 * A consumer naming no account, an unknown one or a disabled one is refused
	 * with its own reason.
	 *
	 * @return void
	 */
	public function testTheNamedAccountMustExistAndBeEnabled(): void {
		$this->addConsumer(userId: '');
		$refusal = $this->refusal($this->account());
		$this->assertSame(DsoConnectionUnavailableException::NO_ACCOUNT, $refusal->getReason());
		$this->assertSame('The digital post connection names no account to act as.', $refusal->getMessage());

		$this->addConsumer(userId: 'verdwenen');
		$refusal = $this->refusal($this->account());
		$this->assertSame(DsoConnectionUnavailableException::ACCOUNT_UNKNOWN, $refusal->getReason());
		$this->assertSame('Digital post account "verdwenen" does not exist.', $refusal->getMessage());

		$this->addAccount(uid: 'uitgezet', enabled: false);
		$this->addConsumer(userId: 'uitgezet');
		$refusal = $this->refusal($this->account());
		$this->assertSame(DsoConnectionUnavailableException::ACCOUNT_DISABLED, $refusal->getReason());
		$this->assertSame('Digital post account "uitgezet" is disabled.', $refusal->getMessage());

	}//end testTheNamedAccountMustExistAndBeEnabled()

	/**
	 * When the rights cannot be checked the account is refused, not trusted.
	 *
	 * @return void
	 */
	public function testUncheckableRightsRefuseTheAccount(): void {
		$this->addAccount(uid: 'digitalepost');
		$this->addConsumer(userId: 'digitalepost');
		$this->worldHasPermissionHandler = false;

		$account = $this->account();
		$this->assertNull($account->missingRights(userId: 'digitalepost'));
		$refusal = $this->refusal($account);

		$this->assertSame(DsoConnectionUnavailableException::RIGHTS_UNVERIFIABLE, $refusal->getReason());
		$this->assertSame('The rights of digital post account "digitalepost" could not be checked.', $refusal->getMessage());

	}//end testUncheckableRightsRefuseTheAccount()

	/**
	 * Missing rights are named in the refusal, in the required order.
	 *
	 * @return void
	 */
	public function testMissingRightsAreNamed(): void {
		$this->addAccount(uid: 'digitalepost', grants: ['read']);
		$this->addConsumer(userId: 'digitalepost');

		$account = $this->account();
		$this->assertSame(['create', 'update'], $account->missingRights(userId: 'digitalepost'));
		$refusal = $this->refusal($account);

		$this->assertSame(DsoConnectionUnavailableException::ACCOUNT_LACKS_RIGHTS, $refusal->getReason());
		$this->assertSame(
			'Digital post account "digitalepost" lacks create, update on ' . DigitalPostService::SCHEMA . '.',
			$refusal->getMessage()
		);

	}//end testMissingRightsAreNamed()

	/**
	 * A usable account resolves, and the operation runs as it.
	 *
	 * @return void
	 */
	public function testAUsableAccountRunsTheOperationAsItself(): void {
		$this->addAccount(uid: 'digitalepost');
		$this->addConsumer(userId: 'digitalepost');
		$account = $this->account();

		$this->assertSame('digitalepost', $account->resolve()->getUID());

		$ranAs = null;
		$refused = null;
		$account->runOrRefuse(
			what: 'send',
			operation: function () use (&$ranAs): void {
				$ranAs = $this->worldSession->getUser()?->getUID();
			},
			refuse: function (string $message) use (&$refused): void {
				$refused = $message;
			}
		);

		$this->assertSame('digitalepost', $ranAs);
		$this->assertNull($refused);
		$this->assertSame([], $this->alerts);
		$this->assertNull($this->worldSession->getUser(), 'the previous (absent) user is back after the run');

	}//end testAUsableAccountRunsTheOperationAsItself()

	/**
	 * An unusable account refuses out loud: the operation does not run, the
	 * caller gets the message, an error is logged and an alert raised.
	 *
	 * @return void
	 */
	public function testAnUnusableAccountRefusesOutLoud(): void {
		$this->addConsumer(userId: 'verdwenen');

		$ran = false;
		$refused = null;
		$this->account()->runOrRefuse(
			what: 'status-poll',
			operation: function () use (&$ran): void {
				$ran = true;
			},
			refuse: function (string $message) use (&$refused): void {
				$refused = $message;
			}
		);

		$this->assertFalse($ran);
		$this->assertSame('Digital post account "verdwenen" does not exist.', $refused);
		$this->assertSame([[DsoConnectionUnavailableException::ACCOUNT_UNKNOWN, DsoConnectionUnavailableException::CHANNEL_DIGITAL_POST]], $this->alerts);
		$this->assertStringContainsString('digital-post.account.unavailable', $this->logger->flatten());
		$this->assertStringContainsString('status-poll', $this->logger->flatten());

	}//end testAnUnusableAccountRefusesOutLoud()

	/**
	 * The description reports configuration, uid and state for each case.
	 *
	 * @return void
	 */
	public function testTheDescriptionNamesTheState(): void {
		$this->assertSame(
			['configured' => false, 'userId' => '', 'state' => 'no_connection', 'displayName' => '', 'message' => 'No digital post account is set.'],
			$this->account()->describe()
		);

		$this->addAccount(uid: 'uitgezet', enabled: false);
		$this->addConsumer(userId: 'uitgezet');
		$this->assertSame(
			['configured' => true, 'userId' => 'uitgezet', 'state' => 'account_disabled', 'displayName' => 'uitgezet', 'message' => 'Digital post account "uitgezet" is disabled.'],
			$this->account()->describe()
		);

		$this->addConsumer(userId: 'uitgezet', uuid: 'consumer-digital-post-2');
		$described = $this->account()->describe();
		$this->assertFalse($described['configured']);
		$this->assertSame('ambiguous_connection', $described['state']);

		$this->resetWorld();
		$this->addAccount(uid: 'digitalepost');
		$this->addConsumer(userId: 'digitalepost');
		$this->assertSame(
			['configured' => true, 'userId' => 'digitalepost', 'state' => 'ok', 'displayName' => '', 'message' => ''],
			$this->account()->describe()
		);

	}//end testTheDescriptionNamesTheState()
}//end class
