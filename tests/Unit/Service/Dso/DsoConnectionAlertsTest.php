<?php

/**
 * Tests for DsoConnectionAlerts: one admin notification per reason per hour.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The throttle and the recipients.
 */
class DsoConnectionAlertsTest extends TestCase {

	/**
	 * App config values.
	 *
	 * @var array<string, int>
	 */
	private array $config = [];

	private int $now = 1_800_000_000;

	/**
	 * Recipients of each sent notification, with the subject parameters.
	 *
	 * @var list<array{user: string, reason: string}>
	 */
	private array $sent = [];

	private DsoConnectionAlerts $alerts;

	/**
	 * Build the alerts service over two administrators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = [];
		$this->sent = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$admins = [];
		foreach (['admin', 'ops'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$admins[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($admins);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('admin')->willReturn($group);

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$state = ['user' => '', 'reason' => ''];
				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setUser')->willReturnCallback(
					function (string $uid) use (&$state, $notification): INotification {
						$state['user'] = $uid;
						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					function (string $subject, array $parameters) use (&$state, $notification): INotification {
						$state['reason'] = (string)$parameters['reason'];
						return $notification;
					}
				);
				$notification->method('getUser')->willReturnCallback(static function () use (&$state): string {
					return $state['user'];
				});
				$notification->method('getSubjectParameters')->willReturnCallback(static function () use (&$state): array {
					return ['reason' => $state['reason']];
				});

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = ['user' => $notification->getUser(), 'reason' => $notification->getSubjectParameters()['reason']];
			}
		);

		$this->alerts = new DsoConnectionAlerts(
			notificationManager: $manager,
			groupManager: $groupManager,
			appConfig: $appConfig,
			timeFactory: $time,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * The first alert reaches every administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function testTheFirstAlertReachesEveryAdministrator(): void {
		$this->assertTrue($this->alerts->notify(reason: 'no_account'));

		$this->assertSame(
			[['user' => 'admin', 'reason' => 'no_account'], ['user' => 'ops', 'reason' => 'no_account']],
			$this->sent
		);

	}//end testTheFirstAlertReachesEveryAdministrator()

	/**
	 * The same reason within the hour sends nothing; after the hour it sends again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function testTheSameReasonIsThrottledForAnHour(): void {
		$this->alerts->notify(reason: 'no_account');
		$this->now += 3599;
		$this->assertFalse($this->alerts->notify(reason: 'no_account'));
		$this->assertCount(2, $this->sent);

		$this->now += 1;
		$this->assertTrue($this->alerts->notify(reason: 'no_account'));
		$this->assertCount(4, $this->sent);

	}//end testTheSameReasonIsThrottledForAnHour()

	/**
	 * Another reason is not held back by the first one's throttle.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function testAnotherReasonIsNotThrottled(): void {
		$this->alerts->notify(reason: 'no_account');
		$this->assertTrue($this->alerts->notify(reason: 'account_lacks_rights'));
		$this->assertCount(4, $this->sent);

	}//end testAnotherReasonIsNotThrottled()
}//end class
