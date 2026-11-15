<?php

/**
 * Golden test for UserService::updateUserProperties(): which standard user
 * properties are written, in which order, and which the user may not change.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/user-management-and-login/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\Integriq\Service\UserService;
use OCP\Accounts\IAccountManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A user whose backend also lets it change its mail address, language and locale.
 */
abstract class GoldenRichUser implements IUser {

	/**
	 * Whether the backend lets the mail address change.
	 *
	 * @return bool
	 */
	abstract public function canChangeMailAddress(): bool;

	/**
	 * Set the language.
	 *
	 * @param string $language The language.
	 *
	 * @return void
	 */
	abstract public function setLanguage($language);

	/**
	 * Set the locale.
	 *
	 * @param string $locale The locale.
	 *
	 * @return void
	 */
	abstract public function setLocale($locale);
}//end class

/**
 * Pins the writes per permission.
 */
class UserServiceUpdatePropertiesGoldenTest extends TestCase {

	/**
	 * The service with the AccountManager failing, so only standard and name properties are written.
	 *
	 * @return UserService
	 */
	private function service(): UserService {
		$accounts = $this->createMock(IAccountManager::class);
		$accounts->method('getAccount')->willThrowException(new RuntimeException('no account'));
		$config = $this->createMock(IConfig::class);

		return new UserService(
			$this->createMock(IUserSession::class),
			$config,
			$this->createMock(IGroupManager::class),
			$accounts,
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationBridgeService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(IFactory::class)
		);

	}//end service()

	/**
	 * A user that records every setter call.
	 *
	 * @param string             $class   The user class to double.
	 * @param array<string,bool> $may     What the user may change.
	 * @param list<string>       $written The calls, by reference.
	 *
	 * @return IUser
	 */
	private function user(string $class, array $may, array &$written): IUser {
		$user = $this->createMock($class);
		$user->method('getUID')->willReturn('jan');
		foreach ($may as $permission => $answer) {
			if (method_exists($user, $permission) === true) {
				$user->method($permission)->willReturnCallback(
					function () use ($permission, $answer, &$written): bool {
						$written[] = $permission;
						return $answer;
					}
				);
			}
		}

		foreach (['setDisplayName', 'setEMailAddress', 'setPassword', 'setLanguage', 'setLocale'] as $setter) {
			if (method_exists($user, $setter) === true) {
				$user->method($setter)->willReturnCallback(
					function ($value) use ($setter, &$written) {
						$written[] = $setter . '=' . $value;
						return true;
					}
				);
			}
		}

		return $user;

	}//end user()

	/**
	 * Each user class, permission set, request and the calls it causes.
	 *
	 * @return array<string,array{0:string,1:array<string,bool>,2:array<string,mixed>,3:list<string>}>
	 */
	public static function updates(): array {
		$all = ['displayName' => 'Jan', 'email' => 'jan@x.nl', 'password' => 'pw', 'language' => 'nl', 'locale' => 'nl_NL'];
		$yes = ['canChangeDisplayName' => true, 'canChangeMailAddress' => true, 'canChangePassword' => true];
		$no = ['canChangeDisplayName' => false, 'canChangeMailAddress' => false, 'canChangePassword' => false];
		return [
			'rich user may change all' => [
				GoldenRichUser::class,
				$yes,
				$all,
				['canChangeDisplayName', 'setDisplayName=Jan', 'canChangeMailAddress', 'setEMailAddress=jan@x.nl', 'canChangePassword', 'setPassword=pw', 'setLanguage=nl', 'setLocale=nl_NL'],
			],
			'rich user may change nothing' => [
				GoldenRichUser::class,
				$no,
				$all,
				['canChangeDisplayName', 'canChangeMailAddress', 'canChangePassword', 'setLanguage=nl', 'setLocale=nl_NL'],
			],
			'plain user may change all' => [
				IUser::class,
				$yes,
				$all,
				['canChangeDisplayName', 'setDisplayName=Jan', 'canChangePassword', 'setPassword=pw'],
			],
			'nothing asked' => [GoldenRichUser::class, $yes, ['other' => 'x'], []],
			'null values are not set' => [GoldenRichUser::class, $yes, ['displayName' => null, 'email' => null, 'language' => null], []],
			'only password, refused' => [GoldenRichUser::class, $no, ['password' => 'pw'], ['canChangePassword']],
		];

	}//end updates()

	/**
	 * The update makes the same permission checks and writes, in the same order.
	 *
	 * @param string              $class    The user class.
	 * @param array<string,bool>  $may      What the user may change.
	 * @param array<string,mixed> $data     The update.
	 * @param list<string>        $expected The calls.
	 *
	 * @return void
	 */
	#[DataProvider('updates')]
	public function testUpdateWrites(string $class, array $may, array $data, array $expected): void {
		$written = [];
		$user = $this->user($class, $may, $written);

		$result = $this->service()->updateUserProperties($user, $data);

		$this->assertSame($expected, $written);
		$this->assertSame(['success' => true, 'message' => 'User properties updated successfully', 'organisation_updated' => false], $result);

	}//end testUpdateWrites()
}//end class
