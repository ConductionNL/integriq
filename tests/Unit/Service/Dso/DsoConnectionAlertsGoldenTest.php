<?php

/**
 * Characterisation test for DsoConnectionAlerts::notify.
 *
 * Pins every notification field, the throttle key, the missing admin group and
 * the warning when one administrator cannot be notified.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use DateTimeInterface;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden record of the notify outcomes.
 */
class DsoConnectionAlertsGoldenTest extends TestCase {

	/**
	 * Everything the service did, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * App config values.
	 *
	 * @var array<string, int>
	 */
	private array $config = [];

	private int $now = 1_800_000_000;

	/**
	 * Build the service.
	 *
	 * @param bool $withGroup Whether the admin group exists.
	 *
	 * @return DsoConnectionAlerts
	 */
	private function alerts(bool $withGroup): DsoConnectionAlerts {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->log[] = ['setValueInt' => [$app, $key, $value]];
				$this->config[$key] = $value;
				return true;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$users = [];
		foreach (['broken', 'admin', 'ops'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('admin')->willReturn($withGroup === true ? $group : null);

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->log[] = ['notify' => true];
			}
		);

		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<array<string, mixed>> $log The shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};

		return new DsoConnectionAlerts($manager, $groupManager, $appConfig, $time, $logger);
	}//end alerts()

	/**
	 * A notification double that records each setter.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnCallback(
			function (string $app) use ($notification): INotification {
				$this->log[] = ['setApp' => $app];
				return $notification;
			}
		);
		$notification->method('setUser')->willReturnCallback(
			function (string $uid) use ($notification): INotification {
				$this->log[] = ['setUser' => $uid];
				if ($uid === 'broken') {
					throw new RuntimeException('no inbox');
				}

				return $notification;
			}
		);
		$notification->method('setDateTime')->willReturnCallback(
			function (DateTimeInterface $at) use ($notification): INotification {
				$this->log[] = ['setDateTime' => $at->getTimestamp()];
				return $notification;
			}
		);
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use ($notification): INotification {
				$this->log[] = ['setObject' => [$type, $id]];
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use ($notification): INotification {
				$this->log[] = ['setSubject' => [$subject, $parameters]];
				return $notification;
			}
		);

		return $notification;
	}//end notification()

	/**
	 * The recorded outcomes of a sequence of calls.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$alerts = $this->alerts(true);
		$this->log[] = ['return' => $alerts->notify('Token-Expired!', 'DSO 1')];
		$this->now += 10;
		$this->log[] = ['return' => $alerts->notify('Token-Expired!', 'DSO 1')];
		$this->now += DsoConnectionAlerts::THROTTLE_SECONDS;
		$this->log[] = ['return' => $alerts->notify('Token-Expired!', 'DSO 1')];

		$this->config = [];
		$this->log[] = ['return' => $this->alerts(false)->notify('down', 'openformulieren')];

		$path = __DIR__ . '/../../../fixtures/dso/dso-connection-alerts.json';
		$actual = json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
