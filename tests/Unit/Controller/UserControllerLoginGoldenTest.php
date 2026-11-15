<?php

/**
 * Golden characterization of UserController::login().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/user-management-and-login/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\UserController;
use OCA\Integriq\Service\SecurityService;
use OCA\Integriq\Service\UserServiceInterface;
use OCP\AppFramework\Http\Response;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Every branch of login(), recorded against a golden file: the response
 * (status, data, headers), the rate-limit cache after the call and every
 * user manager, session, user service and logger call.
 *
 * The cache is an in-memory one, so the real SecurityService runs. The
 * session id and name depend on the PHP setup and are recorded only as
 * present or not.
 *
 * Regenerate the golden file on the code it must describe with
 * LOGIN_GOLDEN=1.
 */
class UserControllerLoginGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/controller/user-login.json';

	private const FAR_FUTURE = 4102444800;

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The rate-limit cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = [];

	/**
	 * The current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * The controller over recording collaborators.
	 *
	 * @return UserController
	 */
	private function controller(): UserController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($this->case['params']);
		$request->method('getRemoteAddress')->willReturn('203.0.113.7');
		$request->method('getHeader')->willReturnCallback(fn (string $name) => ($this->case['headers'][$name] ?? ''));

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->cache[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, $value, int $ttl = 0) {
				$this->cache[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key) {
				unset($this->cache[$key]);
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$user->method('isEnabled')->willReturn(($this->case['enabled'] ?? true));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('checkPassword')->willReturnCallback(
			function (string $loginName, string $password) use ($user) {
				$this->log[] = ['userManager.checkPassword', $loginName, $password];
				if (($this->case['check'] ?? 'user') === 'throws') {
					throw new RuntimeException('backend down');
				}

				return (($this->case['check'] ?? 'user') === 'user' ? $user : false);
			}
		);

		$sessionUser = $this->createMock(IUser::class);
		$sessionUid = (array_key_exists('sessionUid', $this->case) === true ? $this->case['sessionUid'] : 'jan');
		$sessionUser->method('getUID')->willReturn((string)$sessionUid);
		$session = $this->getMockBuilder(IUserSession::class)->addMethods(['createSessionToken'])->getMockForAbstractClass();
		$session->method('setUser')->willReturnCallback(
			function ($sessionUserArg) {
				$this->log[] = ['session.setUser', $sessionUserArg?->getUID()];
			}
		);
		$session->method('createSessionToken')->willReturnCallback(
			function ($request, $uid, $loginName, $password) {
				$this->log[] = ['session.createSessionToken', $uid, $loginName, $password];
				return true;
			}
		);
		$session->method('getUser')->willReturnCallback(
			function () use ($sessionUser, $sessionUid) {
				$this->log[] = ['session.getUser'];
				return ($sessionUid === null ? null : $sessionUser);
			}
		);

		$userService = $this->createMock(UserServiceInterface::class);
		$userService->method('buildUserDataArray')->willReturnCallback(
			function (IUser $builtUser) {
				$this->log[] = ['userService.buildUserDataArray', $builtUser->getUID()];
				if (($this->case['build'] ?? '') === 'throws') {
					throw new RuntimeException('profile failed');
				}

				return ['uid' => $builtUser->getUID(), 'displayName' => 'Jan'];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function ($message, array $context = []) {
				$this->log[] = ['logger.error', $message, array_keys($context), ($context['exception'] ?? null)];
			}
		);
		$logger->method('warning')->willReturnCallback(
			function ($message, array $context = []) {
				$this->log[] = ['logger.warning', $message, array_keys($context)];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new UserController('integriq', $request, $userManager, $session, new SecurityService($cacheFactory, $logger), $logger, $userService, $l10n);
	}//end controller()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$valid = ['username' => 'jan', 'password' => 'geheim'];

		return [
			'success' => ['params' => $valid],
			'success-with-origin' => ['params' => $valid, 'headers' => ['Origin' => 'https://app.example']],
			'missing-password' => ['params' => ['username' => 'jan']],
			'short-username' => ['params' => ['username' => 'j', 'password' => 'x']],
			'bad-username' => ['params' => ['username' => 'jan<', 'password' => 'x']],
			'user-locked-out' => ['params' => $valid, 'cache' => ['integriq_user_lockout_jan' => self::FAR_FUTURE]],
			'ip-locked-out' => ['params' => $valid, 'cache' => ['integriq_ip_lockout_203.0.113.7' => self::FAR_FUTURE]],
			'lockout-expired' => ['params' => $valid, 'cache' => ['integriq_user_lockout_jan' => 1]],
			'too-many-attempts' => ['params' => $valid, 'cache' => ['integriq_login_attempts_jan' => 5, 'integriq_progressive_delay_jan_203.0.113.7' => 0]],
			'wrong-password' => ['params' => $valid, 'check' => 'false'],
			'wrong-password-fourth' => ['params' => $valid, 'check' => 'false', 'cache' => ['integriq_login_attempts_jan' => 3]],
			'disabled' => ['params' => $valid, 'enabled' => false],
			'no-session-user' => ['params' => $valid, 'sessionUid' => null],
			'other-session-user' => ['params' => $valid, 'sessionUid' => 'piet'],
			'check-throws' => ['params' => $valid, 'check' => 'throws'],
			'build-throws' => ['params' => $valid, 'build' => 'throws'],
			'memory-pressure' => ['params' => $valid, 'memory' => true],
			'success-clears-attempts' => ['params' => $valid, 'cache' => ['integriq_login_attempts_jan' => 2, 'integriq_ip_attempts_203.0.113.7' => 2]],
		];
	}//end cases()

	/**
	 * Describe a response: status, data and headers.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(Response $response): array {
		$data = $response->getData();
		if (isset($data['session']) === true) {
			$data['session']['id'] = ($data['session']['id'] !== '' ? 'present' : 'empty');
			$data['session']['name'] = ($data['session']['name'] !== '' ? 'present' : 'empty');
		}

		return [
			'status' => $response->getStatus(),
			'data' => $data,
			'headers' => (new ReflectionProperty(Response::class, 'headers'))->getValue($response),
		];
	}//end describe()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->case = $case;
			$this->cache = ($case['cache'] ?? []);
			$this->log = [];
			$controller = $this->controller();
			$memoryLimit = (string)ini_get('memory_limit');
			if (($case['memory'] ?? false) === true) {
				ini_set('memory_limit', (string)(int)(memory_get_usage(true) * 1.1));
			}

			try {
				$outcome = $this->describe($controller->login());
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			} finally {
				ini_set('memory_limit', $memoryLimit);
			}

			$cache = $this->cache;
			ksort($cache);
			$actual[$name] = ['outcome' => $outcome, 'cache' => $cache, 'log' => $this->log];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		$encoded = (string)preg_replace('/"(lockout_until|integriq_(user|ip)_lockout_[^"]*)": (\d{9,})/', '"$1": "TIME"', $encoded);
		if (getenv('LOGIN_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
