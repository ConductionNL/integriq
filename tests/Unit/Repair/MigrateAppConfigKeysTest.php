<?php

/**
 * Unit tests for the MigrateAppConfigKeys repair step.
 *
 * Nextcloud namespaces `oc_appconfig` by app id, so the openconnector ->
 * integriq rename cuts this app off from every value it stored. Every reader
 * carries a default, so a lost value does not error — it reverts, silently.
 * These tests pin the carry-over and, just as importantly, the two things the
 * step must NOT do: copy Nextcloud's own reserved keys, and clobber a value the
 * admin set after the rename.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec exclude One-off openconnector->integriq app-id rename plumbing; it
 *       moves IAppConfig rows between namespaces and adds no domain behaviour.
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the appconfig carry-over across the app-id rename.
 */
class MigrateAppConfigKeysTest extends TestCase {
	/**
	 * The app id this app stored its configuration under before the rename.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'openconnector';

	/**
	 * The app id this app stores its configuration under now.
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
	 * Build an IAppConfig double backed by a simple two-namespace array store.
	 *
	 * @param array<string,string> $oldValues Values stored under the old app id.
	 * @param array<string,string> $newValues Values already stored under the new app id.
	 * @param array<string,string> $writes    Receives every setValueString call.
	 * @param array<int,string>    $deletes   Receives every delete call.
	 *
	 * @return IAppConfig
	 */
	private function makeAppConfig(
		array $oldValues,
		array $newValues,
		array &$writes,
		array &$deletes,
	): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);

		$appConfig->method('getKeys')->willReturnCallback(
			static function (string $app) use ($oldValues, $newValues): array {
				if ($app === self::OLD_APP_ID) {
					return array_keys($oldValues);
				}

				return array_keys($newValues);
			}
		);

		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($oldValues, $newValues): string {
				if ($app === self::OLD_APP_ID) {
					return ($oldValues[$key] ?? $default);
				}

				return ($newValues[$key] ?? $default);
			}
		);

		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$writes): bool {
				$writes[$app . '/' . $key] = $value;
				return true;
			}
		);

		$appConfig->method('deleteKey')->willReturnCallback(
			static function (string $app, string $key) use (&$deletes): void {
				$deletes[] = $app . '/' . $key;
			}
		);

		return $appConfig;
	}//end makeAppConfig()

	/**
	 * Every stored value is copied from the old namespace to the new one, and
	 * the old rows are left in place so a rollback still finds them.
	 *
	 * @return void
	 */
	public function testCopiesStoredValuesAndKeepsTheOldRows(): void {
		$writes = [];
		$deletes = [];
		$appConfig = $this->makeAppConfig(
			['actions' => '{"sources":"admin"}', 'retention_days' => '30'],
			[],
			$writes,
			$deletes
		);

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->makeOutput());

		$this->assertSame(
			[
				self::NEW_APP_ID . '/actions' => '{"sources":"admin"}',
				self::NEW_APP_ID . '/retention_days' => '30',
			],
			$writes,
			'every stored value must be carried over to the new app id'
		);
		$this->assertSame([], $deletes, 'the old rows must never be deleted — a rollback still needs them');
	}//end testCopiesStoredValuesAndKeepsTheOldRows()

	/**
	 * Nextcloud's own per-app bookkeeping keys must NOT be copied.
	 *
	 * `enabled` is the dangerous one: AppManager::enableApp() writes it through
	 * the deprecated setValue() as type MIXED, so copying it as a STRING makes
	 * the next `app:enable` fail with AppConfigTypeConflictException —
	 * permanently, because the conflict is hit before the app can run anything
	 * that would repair it.
	 *
	 * @return void
	 */
	public function testReservedNextcloudKeysAreNeverCopied(): void {
		$writes = [];
		$deletes = [];
		$appConfig = $this->makeAppConfig(
			[
				'enabled' => 'yes',
				'installed_version' => '0.2.9',
				'types' => 'filesystem',
				'retention_days' => '30',
			],
			[],
			$writes,
			$deletes
		);

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->makeOutput());

		$this->assertSame(
			[self::NEW_APP_ID . '/retention_days' => '30'],
			$writes,
			'only the app-owned key may be copied; enabled/installed_version/types are Nextcloud-owned'
		);
	}//end testReservedNextcloudKeysAreNeverCopied()

	/**
	 * A value already set under the new app id is never overwritten, so a
	 * setting changed after the rename survives, and a second run is a no-op.
	 *
	 * @return void
	 */
	public function testDoesNotClobberAValueAlreadySetUnderTheNewAppId(): void {
		$writes = [];
		$deletes = [];
		$appConfig = $this->makeAppConfig(
			['retention_days' => '30'],
			['retention_days' => '90'],
			$writes,
			$deletes
		);

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->makeOutput());

		$this->assertSame([], $writes, 'an existing newer value must win over the pre-rename one');
	}//end testDoesNotClobberAValueAlreadySetUnderTheNewAppId()

	/**
	 * An install that never ran under the old app id writes nothing at all.
	 *
	 * @return void
	 */
	public function testNothingStoredMeansNothingWritten(): void {
		$writes = [];
		$deletes = [];
		$appConfig = $this->makeAppConfig([], [], $writes, $deletes);

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->makeOutput());

		$this->assertSame([], $writes);
	}//end testNothingStoredMeansNothingWritten()

	/**
	 * One unreadable key is logged and skipped rather than thrown. This step
	 * also runs under <install>, where a throwing repair step means the app
	 * never enables and every route goes with it.
	 *
	 * @return void
	 */
	public function testOneUnreadableKeyDoesNotAbortTheRest(): void {
		$writes = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['broken', 'retention_days']);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'broken' && $app === self::OLD_APP_ID) {
					throw new \RuntimeException('unreadable');
				}

				if ($app === self::OLD_APP_ID && $key === 'retention_days') {
					return '30';
				}

				return $default;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$writes): bool {
				$writes[$app . '/' . $key] = $value;
				return true;
			}
		);

		(new MigrateAppConfigKeys($appConfig, new NullLogger()))->run($this->makeOutput());

		$this->assertSame(
			[self::NEW_APP_ID . '/retention_days' => '30'],
			$writes,
			'the key after the unreadable one must still be migrated'
		);
	}//end testOneUnreadableKeyDoesNotAbortTheRest()
}//end class
