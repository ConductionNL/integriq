<?php

/**
 * Only an administrator edits the lookup allowlist, and every change is recorded.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MappingLookupController;
use OCA\Integriq\Twig\LookupAllowlist;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Controller\MappingLookupController
 * @uses   \OCA\Integriq\Twig\LookupAllowlist
 */
class MappingLookupControllerTest extends TestCase {

	/**
	 * The stored app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Recorded changes.
	 *
	 * @var list<string>
	 */
	private array $recorded = [];

	/**
	 * The controller for a user.
	 *
	 * @param string|null          $uid    The signed-in user, null when signed out.
	 * @param bool                 $admin  Whether they administer the instance.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return MappingLookupController
	 */
	private function controller(?string $uid, bool $admin, array $params = []): MappingLookupController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message): void {
			$this->recorded[] = $message;
		});

		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		return new MappingLookupController(
			request: $request,
			allowlist: new LookupAllowlist(appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class)),
			userSession: $session,
			groupManager: $groups,
			logger: $logger
		);
	}//end controller()

	/**
	 * An administrator adds a code list; it is listed with their user id and the change is recorded.
	 *
	 * @return void
	 */
	public function testAnAdministratorAllowsACodeList(): void {
		$added = $this->controller(uid: 'beheer', admin: true, params: ['schema' => 'codelijsten/gemeenten'])->add();
		self::assertSame(201, $added->getStatus());

		$list = $this->controller(uid: 'beheer', admin: true)->index()->getData()['schemas'];
		self::assertSame('codelijsten/gemeenten', $list[0]['schema']);
		self::assertSame('beheer', $list[0]['addedBy']);
		self::assertSame(['[MappingLookups] beheer added "codelijsten/gemeenten" to the lookup allowlist'], $this->recorded);

		self::assertSame(422, $this->controller(uid: 'beheer', admin: true, params: ['schema' => 'codelijsten/*'])->add()->getStatus());

		self::assertSame(200, $this->controller(uid: 'beheer', admin: true)->remove(register: 'codelijsten', schema: 'gemeenten')->getStatus());
		self::assertSame(404, $this->controller(uid: 'beheer', admin: true)->remove(register: 'codelijsten', schema: 'gemeenten')->getStatus());
		self::assertSame('[MappingLookups] beheer removed "codelijsten/gemeenten" from the lookup allowlist', $this->recorded[1]);
	}//end testAnAdministratorAllowsACodeList()

	/**
	 * A non-administrator gets 403 and the list is unchanged; signed out is 401.
	 *
	 * @return void
	 */
	public function testANonAdministratorCannotChangeTheList(): void {
		self::assertSame(403, $this->controller(uid: 'medewerker', admin: false, params: ['schema' => 'codelijsten/gemeenten'])->add()->getStatus());
		self::assertSame(403, $this->controller(uid: 'medewerker', admin: false)->index()->getStatus());
		self::assertSame(403, $this->controller(uid: 'medewerker', admin: false)->remove(register: 'a', schema: 'b')->getStatus());
		self::assertSame(401, $this->controller(uid: null, admin: false, params: ['schema' => 'codelijsten/gemeenten'])->add()->getStatus());
		self::assertSame([], $this->config);
		self::assertSame([], $this->recorded);
	}//end testANonAdministratorCannotChangeTheList()
}//end class
