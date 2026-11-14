<?php

/**
 * Golden tests for SecurityService's login rate limiting: lockouts, the
 * progressive delay, the counters a failed attempt writes, and the log lines.
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

use OCA\Integriq\Service\SecurityService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused login outcomes over an in-memory cache.
 */
class SecurityServiceRateLimitGoldenTest extends TestCase {

	/**
	 * Cache contents, key => [value, ttl].
	 *
	 * @var array<string,array{0:mixed,1:int}>
	 */
	private array $store = [];

	/**
	 * Log lines, as [level, message, context without the timestamp].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * The service over the in-memory cache.
	 *
	 * @return SecurityService
	 */
	private function service(): SecurityService {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->store[$key][0] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, $value, int $ttl = 0): bool {
				$this->store[$key] = [$value, $ttl];
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->store[$key]);
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $context['timestamp']);
					unset($context['timestamp']);
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new SecurityService($factory, $logger);

	}//end service()

	/**
	 * A fresh attempt is allowed and logs nothing.
	 *
	 * @return void
	 */
	public function testFreshAttemptIsAllowed(): void {
		$this->assertSame(['allowed' => true], $this->service()->checkLoginRateLimit('jan', '10.0.0.1'));
		$this->assertSame([], $this->logs);

	}//end testFreshAttemptIsAllowed()

	/**
	 * An active user lockout refuses first; an expired one does not.
	 *
	 * @return void
	 */
	public function testUserLockout(): void {
		$until = time() + 500;
		$this->store['integriq_user_lockout_jan_x'] = [$until, 0];
		$this->store['integriq_ip_lockout_1.2.3.4'] = [$until + 1, 0];

		$this->assertSame(
			['allowed' => false, 'lockout_until' => $until, 'reason' => 'Account temporarily locked due to too many failed login attempts'],
			$this->service()->checkLoginRateLimit('jan x', '1.2.3.4')
		);
		$this->assertSame(
			[['warning', 'Security event: login_attempt_during_lockout', ['username' => 'jan_x', 'ip_address' => '1.2.3.4', 'lockout_until' => $until, 'event' => 'login_attempt_during_lockout']]],
			$this->logs
		);

		$this->store['integriq_user_lockout_jan_x'] = [time() - 1, 0];
		$this->logs = [];
		$this->assertSame(
			['allowed' => false, 'lockout_until' => $until + 1, 'reason' => 'IP address temporarily blocked due to suspicious activity'],
			$this->service()->checkLoginRateLimit('jan x', '1.2.3.4')
		);
		$this->assertSame(
			[['info', 'Security event: login_attempt_from_blocked_ip', ['username' => 'jan_x', 'ip_address' => '1.2.3.4', 'lockout_until' => $until + 1, 'event' => 'login_attempt_from_blocked_ip']]],
			$this->logs
		);

	}//end testUserLockout()

	/**
	 * Too many attempts by user or IP refuse with a delay that doubles up to the cap.
	 *
	 * @return void
	 */
	public function testProgressiveDelay(): void {
		$service = $this->service();
		$this->store['integriq_login_attempts_jan'] = [5, 0];
		$delays = [];
		for ($i = 0; $i < 7; $i++) {
			$verdict = $service->checkLoginRateLimit('jan', 'ip');
			$delays[] = $verdict['delay'];
			$this->assertSame(['allowed' => false, 'delay' => $verdict['delay'], 'reason' => 'Too many login attempts. Please wait before trying again.'], $verdict);
		}

		$this->assertSame([2, 4, 8, 16, 32, 60, 60], $delays);
		$this->assertSame([60, 900], $this->store['integriq_progressive_delay_jan_ip']);
		$this->assertSame(
			['info', 'Security event: rate_limit_exceeded', ['username' => 'jan', 'ip_address' => 'ip', 'user_attempts' => 5, 'ip_attempts' => 0, 'delay' => 2, 'event' => 'rate_limit_exceeded']],
			$this->logs[0]
		);

		$this->store = ['integriq_ip_attempts_ip' => [9, 0], 'integriq_login_attempts_jan' => [4, 0]];
		$this->assertSame(2, $service->checkLoginRateLimit('jan', 'ip')['delay']);
		$this->store = ['integriq_ip_attempts_ip' => [4, 0], 'integriq_login_attempts_jan' => [4, 0]];
		$this->assertSame(['allowed' => true], $service->checkLoginRateLimit('jan', 'ip'));

	}//end testProgressiveDelay()

	/**
	 * Failed attempts count per user and IP, lock both out at the fifth, and log each.
	 *
	 * @return void
	 */
	public function testFailedAttemptsLockOut(): void {
		$service = $this->service();
		$name = str_repeat('a', 70) . '!';
		for ($i = 1; $i <= 4; $i++) {
			$service->recordFailedLoginAttempt($name, '::1', 'bad');
		}

		$key = substr(str_repeat('a', 70), 0, 64);
		$this->assertSame([4, 900], $this->store['integriq_login_attempts_' . $key]);
		$this->assertSame([4, 900], $this->store['integriq_ip_attempts___1']);
		$this->assertCount(4, $this->logs);
		$this->assertSame(
			['info', 'Security event: failed_login_attempt', ['username' => $key, 'ip_address' => '__1', 'reason' => 'bad', 'user_attempts' => 4, 'ip_attempts' => 4, 'event' => 'failed_login_attempt']],
			$this->logs[3]
		);

		$this->logs = [];
		$before = time();
		$service->recordFailedLoginAttempt($name, '::1');
		$after = time();

		[$userUntil, $userTtl] = $this->store['integriq_user_lockout_' . $key];
		[$ipUntil, $ipTtl] = $this->store['integriq_ip_lockout___1'];
		$this->assertTrue($userUntil >= $before + 3600 && $userUntil <= $after + 3600);
		$this->assertSame([3600, 3600], [$userTtl, $ipTtl]);
		$this->assertSame(
			[
				['warning', 'Security event: user_locked_out', ['username' => $key, 'ip_address' => '__1', 'attempts' => 5, 'lockout_until' => $userUntil, 'event' => 'user_locked_out']],
				['info', 'Security event: ip_locked_out', ['username' => $key, 'ip_address' => '__1', 'attempts' => 5, 'lockout_until' => $ipUntil, 'event' => 'ip_locked_out']],
				['info', 'Security event: failed_login_attempt', ['username' => $key, 'ip_address' => '__1', 'reason' => 'invalid_credentials', 'user_attempts' => 5, 'ip_attempts' => 5, 'event' => 'failed_login_attempt']],
			],
			$this->logs
		);

	}//end testFailedAttemptsLockOut()

	/**
	 * Only the IP locks out when other users share it.
	 *
	 * @return void
	 */
	public function testSharedIpLocksOutAlone(): void {
		$service = $this->service();
		foreach (['a', 'b', 'c', 'd', 'e'] as $user) {
			$service->recordFailedLoginAttempt($user, 'shared');
		}

		$this->assertArrayHasKey('integriq_ip_lockout_shared', $this->store);
		$this->assertSame([], array_filter(array_keys($this->store), static fn (string $key): bool => str_starts_with($key, 'integriq_user_lockout_')));
		$this->assertSame('ip_locked_out', $this->logs[4][2]['event']);

	}//end testSharedIpLocksOutAlone()
}//end class
