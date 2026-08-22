<?php

/**
 * Unit tests for the MigrateUserPreferences repair step.
 *
 * `oc_preferences` is namespaced by app id, so the openconnector -> integriq
 * rename cuts every per-user preference loose. Every reader carries a default,
 * so a lost preference does not error — a dismissed dialog comes back, an
 * opted-out user is opted back in.
 *
 * The behaviour these tests pin hardest is the ENUMERATION STRATEGY. The step
 * must walk users and ask what each one stored (`callForSeenUsers()` +
 * `getUserKeys()`), never `getUsersForUserValue(app, key, value)`, which needs
 * the key and value up front and would migrate NOTHING WHILE REPORTING SUCCESS
 * against this app's open-ended `pref_*` key set.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec exclude One-off openconnector->integriq app-id rename plumbing; it
 *       moves oc_preferences rows between namespaces and adds no domain
 *       behaviour.
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateUserPreferences;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the per-user preference carry-over across the app-id rename.
 */
class MigrateUserPreferencesTest extends TestCase {
	/**
	 * The app id this app stored preferences under before the rename.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'openconnector';

	/**
	 * The app id this app stores preferences under now.
	 *
	 * @var string
	 */
	private const NEW_APP_ID = 'integriq';

	/**
	 * Build a quiet IOutput double.
	 *
	 * @return IOutput
	 */
	private function makeOutput(): IOutput {
		return $this->createMock(IOutput::class);
	}//end makeOutput()

	/**
	 * Build an IUserManager whose callForSeenUsers walks the given user ids.
	 *
	 * @param array<int,string> $userIds The seen user ids to walk.
	 *
	 * @return IUserManager
	 */
	private function makeUserManager(array $userIds): IUserManager {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback) use ($userIds): void {
				foreach ($userIds as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$callback($user);
				}
			}
		);

		return $userManager;
	}//end makeUserManager()

	/**
	 * Build an IConfig double backed by a per-user, per-app array store.
	 *
	 * @param array<string,array<string,array<string,string>>> $store [uid][app][key] => value.
	 * @param array<string,string> $writes Receives every setUserValue call.
	 *
	 * @return IConfig
	 */
	private function makeConfig(array $store, array &$writes): IConfig {
		$config = $this->createMock(IConfig::class);

		$config->method('getUserKeys')->willReturnCallback(
			static function (string $uid, string $app) use ($store): array {
				return array_keys($store[$uid][$app] ?? []);
			}
		);

		$config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, $default = '') use ($store) {
				return ($store[$uid][$app][$key] ?? $default);
			}
		);

		$config->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, $value) use (&$writes): void {
				$writes[$uid . '/' . $app . '/' . $key] = $value;
			}
		);

		return $config;
	}//end makeConfig()

	/**
	 * Every user's stored preferences are carried over, for every user, and the
	 * enumeration asks the DATA what is stored rather than guessing key names.
	 *
	 * @return void
	 */
	public function testCopiesEveryStoredPreferenceForEveryUser(): void {
		$writes = [];
		$store = [
			'alice' => [self::OLD_APP_ID => ['pref_sources_columns' => '["name"]', 'pref_dialog_dismissed' => '1']],
			'bob' => [self::OLD_APP_ID => ['pref_dialog_dismissed' => '1']],
		];

		$step = new MigrateUserPreferences(
			$this->makeConfig($store, $writes),
			$this->makeUserManager(['alice', 'bob']),
			new NullLogger()
		);
		$step->run($this->makeOutput());

		$this->assertSame(
			[
				'alice/' . self::NEW_APP_ID . '/pref_sources_columns' => '["name"]',
				'alice/' . self::NEW_APP_ID . '/pref_dialog_dismissed' => '1',
				'bob/' . self::NEW_APP_ID . '/pref_dialog_dismissed' => '1',
			],
			$writes,
			'every seen user\'s stored preferences must be carried over'
		);
	}//end testCopiesEveryStoredPreferenceForEveryUser()

	/**
	 * The step must enumerate BY USER. `getUsersForUserValue()` needs the key
	 * and value up front and is exhaustive only for a closed set — using it
	 * here would migrate nothing while reporting success.
	 *
	 * @return void
	 */
	public function testEnumeratesByUserAndNeverByValue(): void {
		$writes = [];
		$store = ['alice' => [self::OLD_APP_ID => ['pref_anything' => 'x']]];

		$config = $this->makeConfig($store, $writes);
		$config->expects($this->never())->method('getUsersForUserValue');

		$userManager = $this->makeUserManager(['alice']);

		(new MigrateUserPreferences($config, $userManager, new NullLogger()))->run($this->makeOutput());

		$this->assertSame(['alice/' . self::NEW_APP_ID . '/pref_anything' => 'x'], $writes);
	}//end testEnumeratesByUserAndNeverByValue()

	/**
	 * A preference the user changed after the rename is never clobbered, so a
	 * second run is a no-op.
	 *
	 * @return void
	 */
	public function testDoesNotClobberAPreferenceAlreadySetUnderTheNewAppId(): void {
		$writes = [];
		$store = [
			'alice' => [
				self::OLD_APP_ID => ['pref_dialog_dismissed' => '1'],
				self::NEW_APP_ID => ['pref_dialog_dismissed' => '0'],
			],
		];

		$step = new MigrateUserPreferences(
			$this->makeConfig($store, $writes),
			$this->makeUserManager(['alice']),
			new NullLogger()
		);
		$step->run($this->makeOutput());

		$this->assertSame([], $writes, 'a preference set after the rename must win');
	}//end testDoesNotClobberAPreferenceAlreadySetUnderTheNewAppId()

	/**
	 * A user whose keys cannot be read is skipped without aborting the walk —
	 * this step runs under <install>, where a throw stops the app enabling.
	 *
	 * @return void
	 */
	public function testOneUnreadableUserDoesNotAbortTheWalk(): void {
		$writes = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserKeys')->willReturnCallback(
			static function (string $uid, string $app): array {
				if ($uid === 'broken') {
					throw new \RuntimeException('backend down');
				}

				return ($uid === 'alice' && $app === self::OLD_APP_ID) ? ['pref_x'] : [];
			}
		);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, $default = '') {
				return ($uid === 'alice' && $app === self::OLD_APP_ID) ? 'kept' : $default;
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, $value) use (&$writes): void {
				$writes[$uid . '/' . $app . '/' . $key] = $value;
			}
		);

		$step = new MigrateUserPreferences(
			$config,
			$this->makeUserManager(['broken', 'alice']),
			new NullLogger()
		);
		$step->run($this->makeOutput());

		$this->assertSame(
			['alice/' . self::NEW_APP_ID . '/pref_x' => 'kept'],
			$writes,
			'the user after the unreadable one must still be migrated'
		);
	}//end testOneUnreadableUserDoesNotAbortTheWalk()

	/**
	 * A failure to enumerate users at all is warned, not thrown.
	 *
	 * @return void
	 */
	public function testUserEnumerationFailureIsWarnedNotThrown(): void {
		$writes = [];
		$config = $this->makeConfig([], $writes);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willThrowException(new \RuntimeException('no backend'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		(new MigrateUserPreferences($config, $userManager, new NullLogger()))->run($output);

		$this->assertSame([], $writes);
	}//end testUserEnumerationFailureIsWarnedNotThrown()
}//end class
