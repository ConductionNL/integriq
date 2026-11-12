<?php

/**
 * Golden master of UserService::buildUserDataArray(), quota included.
 *
 * Every case scripts the user object (with and without the optional methods
 * some backends add), the quota, the storage row and the organisation bridge,
 * and records the user data array plus every log line. Set RECORD_GOLDEN=1
 * to rewrite the fixture.
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
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\UserService
 */
class UserServiceUserDataGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../../fixtures/golden/user-service-user-data.json';

	/**
	 * Every log line of the current case.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The service over scripted collaborators.
	 *
	 * @param array<string, mixed> $world `storage` (row array, false, or 'throw') and `organisations` (array or null).
	 *
	 * @return UserService The service.
	 */
	private function service(array $world): UserService {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('behandelaars');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn(['x' => $group]);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $user, string $app, string $key, $default = ''): string => ($app . '.' . $key === 'settings.phone' ? '0612345678' : (string)$default)
		);
		$accounts = $this->createMock(IAccountManager::class);
		$accounts->method('getAccount')->willThrowException(new RuntimeException('no account manager'));
		$l10n = $this->createMock(IFactory::class);
		$l10n->method('findLanguage')->willReturn('nl');

		$bridge = $this->createMock(OrganisationBridgeService::class);
		$bridge->method('isOrganisationServiceAvailable')->willReturn(($world['organisations'] ?? null) !== null);
		$bridge->method('getUserOrganisationStats')->willReturn(($world['organisations'] ?? []));

		return new UserService(
			$this->createMock(IUserSession::class),
			$config,
			$groups,
			$accounts,
			$logger,
			$bridge,
			$this->db(storage: ($world['storage'] ?? 'throw')),
			$l10n
		);
	}

	/**
	 * A database whose storage-size query answers the given row, or fails.
	 *
	 * @param mixed $storage The row, false for none, or 'throw'.
	 *
	 * @return IDBConnection The database.
	 */
	private function db(mixed $storage): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		if ($storage === 'throw') {
			$db->method('getQueryBuilder')->willThrowException(new \Exception('no database'));
			return $db;
		}

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('m.user_id = :user');
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($storage);
		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'join', 'where', 'setMaxResults'] as $method) {
			$query->method($method)->willReturnSelf();
		}

		$query->method('expr')->willReturn($expr);
		$query->method('createNamedParameter')->willReturn(':user');
		$query->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($query);

		return $db;
	}

	/**
	 * A user as the interface describes it.
	 *
	 * @param mixed $quota The quota getQuota answers, or 'throw'.
	 *
	 * @return IUser The user.
	 */
	private function plainUser(mixed $quota): IUser {
		$user = $this->createMock(IUser::class);
		$this->script(user: $user, quota: $quota);

		return $user;
	}

	/**
	 * A user whose backend adds the optional methods.
	 *
	 * @param mixed $usedSpace What getUsedSpace answers, or 'throw'.
	 *
	 * @return IUser The user.
	 */
	private function richUser(mixed $usedSpace): IUser {
		$user = $this->getMockBuilder(IUser::class)
			->onlyMethods(get_class_methods(IUser::class))
			->addMethods(['getEmailVerified', 'getAvatarScope', 'canChangeMailAddress', 'getUsedSpace'])
			->getMock();
		$this->script(user: $user, quota: '1073741824');
		$user->method('getEmailVerified')->willReturn(true);
		$user->method('getAvatarScope')->willReturn('public');
		$user->method('canChangeMailAddress')->willReturn(true);
		if ($usedSpace === 'throw') {
			$user->method('getUsedSpace')->willThrowException(new \Exception('backend cannot count'));
			return $user;
		}

		$user->method('getUsedSpace')->willReturn($usedSpace);

		return $user;
	}

	/**
	 * Script the interface methods every user answers.
	 *
	 * @param \PHPUnit\Framework\MockObject\MockObject $user  The user.
	 * @param mixed                                    $quota The quota, or 'throw'.
	 *
	 * @return void
	 */
	private function script($user, mixed $quota): void {
		$user->method('getUID')->willReturn('anja');
		$user->method('getDisplayName')->willReturn('Anja de Vries');
		$user->method('getEMailAddress')->willReturn('anja@example.nl');
		$user->method('isEnabled')->willReturn(true);
		$user->method('getLastLogin')->willReturn(1700000000);
		$user->method('getBackendClassName')->willReturn('Database');
		$user->method('canChangeDisplayName')->willReturn(true);
		$user->method('canChangePassword')->willReturn(false);
		$user->method('canChangeAvatar')->willReturn(true);
		if ($quota === 'throw') {
			$user->method('getQuota')->willThrowException(new \Exception('quota backend down'));
			return;
		}

		$user->method('getQuota')->willReturn($quota);
	}

	/**
	 * Every case against the recorded fixture.
	 *
	 * Run in its own process: the quota lookup refuses above 128MB of process
	 * memory, so inside a long suite the recorded log lines would differ.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testTheUserDataMatchesTheRecording(): void {
		$cases = [
			'plain-numeric-quota-db-down' => [fn () => $this->plainUser(quota: '1073741824'), []],
			'plain-numeric-quota-db-row' => [fn () => $this->plainUser(quota: '1073741824'), ['storage' => ['size' => '268435456']]],
			'plain-db-no-row' => [fn () => $this->plainUser(quota: '2048'), ['storage' => false]],
			'plain-db-non-numeric-row' => [fn () => $this->plainUser(quota: 'none'), ['storage' => ['size' => 'lots']]],
			'plain-unlimited' => [fn () => $this->plainUser(quota: 'unlimited'), ['storage' => ['size' => '10']]],
			'plain-zero-quota' => [fn () => $this->plainUser(quota: '0'), ['storage' => ['size' => '10']]],
			'plain-quota-throws' => [fn () => $this->plainUser(quota: 'throw'), ['organisations' => ['total' => 2, 'active' => ['uuid' => 'org-1']]]],
			'rich-used-space' => [fn () => $this->richUser(usedSpace: 536870912), ['organisations' => []]],
			'rich-used-space-throws' => [fn () => $this->richUser(usedSpace: 'throw'), ['storage' => ['size' => 1024]]],
		];

		$found = [];
		foreach ($cases as $name => [$user, $world]) {
			$this->logged = [];
			$data = $this->service(world: $world)->buildUserDataArray(user: $user());
			$found[$name] = ['data' => $data, 'logged' => $this->logged];
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, json_decode((string)json_encode($found[$name], JSON_PRESERVE_ZERO_FRACTION), true), 'case ' . $name);
		}
	}
}
