<?php

/**
 * Characterisation tests for UserService's profile-property update and its
 * language/locale resolution.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\Integriq\Service\UserService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A user that has a language and a locale.
 */
abstract class ProfileLocaleUser implements IUser {
	/**
	 * The language.
	 *
	 * @return string|null
	 */
	abstract public function getLanguage();

	/**
	 * The locale.
	 *
	 * @return string|null
	 */
	abstract public function getLocale();
}//end class

/**
 * Pins the writes of updateProfileProperties() (account properties, the
 * single account save, the logged failure, the custom name fields) and
 * every fallback of getLanguageAndLocale().
 */
class UserServiceProfileTest extends TestCase {

	/**
	 * Every recorded call.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The account manager double.
	 *
	 * @var IAccountManager
	 */
	private $accounts;

	/**
	 * The l10n factory double.
	 *
	 * @var IFactory
	 */
	private $l10n;

	/**
	 * Build the service over recording doubles.
	 *
	 * @return UserService
	 */
	private function service(): UserService {
		$config = $this->createMock(IConfig::class);
		$config->method('setUserValue')->willReturnCallback(
			function (string $user, string $app, string $key, $value): void {
				$this->calls[] = 'config:' . $user . ':' . $app . ':' . $key . '=' . $value;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'warning:' . $message . ':' . json_encode($context);
			}
		);

		return new UserService(
			$this->createMock(IUserSession::class),
			$config,
			$this->createMock(IGroupManager::class),
			$this->accounts,
			$logger,
			$this->createMock(OrganisationBridgeService::class),
			$this->createMock(IDBConnection::class),
			$this->l10n
		);

	}//end service()

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calls = [];
		$this->accounts = $this->createMock(IAccountManager::class);
		$this->l10n = $this->createMock(IFactory::class);
		$this->l10n->method('findLanguage')->willReturn('de');

	}//end setUp()

	/**
	 * An account whose properties hold the given values.
	 *
	 * @param array<string,string> $values Property name to current value.
	 *
	 * @return IAccount
	 */
	private function account(array $values): IAccount {
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->willReturnCallback(
			function (string $name) use ($values): IAccountProperty {
				$property = $this->createMock(IAccountProperty::class);
				$property->method('getValue')->willReturn($values[$name] ?? '');
				$property->method('setValue')->willReturnCallback(
					function (string $value) use ($name, $property): IAccountProperty {
						$this->calls[] = 'set:' . $name . '=' . $value;
						return $property;
					}
				);
				return $property;
			}
		);

		return $account;

	}//end account()

	/**
	 * Invoke a private service method.
	 *
	 * @param string $name The method.
	 * @param array  $args The arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args): mixed {
		$method = new \ReflectionMethod(UserService::class, $name);
		$method->setAccessible(true);

		return $method->invokeArgs($this->service(), $args);

	}//end call()

	/**
	 * A user with an id.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');

		return $user;

	}//end user()

	/**
	 * Changed properties are set and the account saved once; unchanged and
	 * absent ones are left; name fields go to the core user config.
	 *
	 * @return void
	 */
	public function testChangedPropertiesAreSavedOnceAndNameFieldsWritten(): void {
		$account = $this->account([IAccountManager::PROPERTY_PHONE => '0612', IAccountManager::PROPERTY_ROLE => 'old']);
		$this->accounts->method('getAccount')->willReturn($account);
		$this->accounts->expects($this->once())->method('updateAccount')->with($account)->willReturnCallback(
			function (): void {
				$this->calls[] = 'updateAccount';
			}
		);

		$this->call(
			'updateProfileProperties',
			[$this->user(), ['biography' => 7, 'role' => 'new', 'phone' => '0612', 'website' => null, 'lastName' => 'Jansen', 'firstName' => 'Jan', 'middleName' => null, 'other' => 'x']]
		);

		$this->assertSame(
			['set:' . IAccountManager::PROPERTY_ROLE . '=new', 'set:' . IAccountManager::PROPERTY_BIOGRAPHY . '=7', 'updateAccount', 'config:jan:core:firstName=Jan', 'config:jan:core:lastName=Jansen'],
			$this->calls
		);

	}//end testChangedPropertiesAreSavedOnceAndNameFieldsWritten()

	/**
	 * Nothing changed saves nothing; a failing account manager is logged and
	 * the name fields are still written.
	 *
	 * @return void
	 */
	public function testAnUnchangedOrFailingAccountSavesNothing(): void {
		$this->accounts->method('getAccount')->willReturnOnConsecutiveCalls(
			$this->account([IAccountManager::PROPERTY_PHONE => '0612']),
			$this->throwException(new RuntimeException('no account'))
		);
		$this->accounts->expects($this->never())->method('updateAccount');

		$this->call('updateProfileProperties', [$this->user(), ['phone' => '0612']]);
		$this->assertSame([], $this->calls);

		$this->call('updateProfileProperties', [$this->user(), ['phone' => '1', 'middleName' => 'van']]);
		$this->assertSame(
			['warning:Failed to update AccountManager properties for user: jan:{"exception":"no account"}', 'config:jan:core:middleName=van'],
			$this->calls
		);

	}//end testAnUnchangedOrFailingAccountSavesNothing()

	/**
	 * Language falls back to the factory; locale to en_US for English, to
	 * xx_XX otherwise, and stays empty without a language; a user without
	 * the methods gets two empty strings.
	 *
	 * @return void
	 */
	public function testLanguageAndLocaleFallBack(): void {
		$cases = [
			[['nl', 'nl_BE'], ['nl', 'nl_BE']],
			[['', ''], ['de', 'de_DE']],
			[['en', ''], ['en', 'en_US']],
			[['fr', null], ['fr', 'fr_FR']],
			[[null, 'x_Y'], ['de', 'x_Y']],
		];
		foreach ($cases as [[$language, $locale], $expected]) {
			$user = $this->createMock(ProfileLocaleUser::class);
			$user->method('getLanguage')->willReturn($language);
			$user->method('getLocale')->willReturn($locale);
			$this->assertSame($expected, $this->call('getLanguageAndLocale', [$user]), json_encode([$language, $locale]));
		}

		$this->assertSame(['', ''], $this->call('getLanguageAndLocale', [$this->createMock(IUser::class)]));

	}//end testLanguageAndLocaleFallBack()

}//end class
