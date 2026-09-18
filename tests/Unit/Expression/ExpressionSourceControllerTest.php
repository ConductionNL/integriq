<?php

/**
 * Who may change the allowlist, and what the surface prints.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Expression
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Expression;

use OCA\Integriq\Controller\ExpressionSourceController;
use OCA\Integriq\Expression\EnvironmentAllowlist;
use OCA\Integriq\Expression\ExpressionValueSourceRegistry;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies REQ-EVS-003.
 */
class ExpressionSourceControllerTest extends TestCase {

	/**
	 * What app config holds.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The allowlist over an in-memory config.
	 *
	 * @return EnvironmentAllowlist The allowlist.
	 */
	private function allowlist(): EnvironmentAllowlist {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new EnvironmentAllowlist(appConfig: $config, logger: $this->createMock(LoggerInterface::class));
	}//end allowlist()

	/**
	 * A controller for one caller.
	 *
	 * @param string|null          $uid       The caller, null for anonymous.
	 * @param bool                 $isAdmin   Whether they administer the instance.
	 * @param EnvironmentAllowlist $allowlist The allowlist.
	 * @param string               $key       The `key` request parameter.
	 *
	 * @return ExpressionSourceController The controller.
	 */
	private function controller(
		?string $uid,
		bool $isAdmin,
		EnvironmentAllowlist $allowlist,
		string $key = ''
	): ExpressionSourceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($key);

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		}

		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new ExpressionSourceController(
			request: $request,
			allowlist: $allowlist,
			registry: new ExpressionValueSourceRegistry(logger: $this->createMock(LoggerInterface::class)),
			userSession: $session,
			groupManager: $groups,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * 🔴 The least privileged principal that should be refused: an ordinary
	 * signed-in user adding a key.
	 *
	 * @return void
	 */
	public function testAnOrdinaryUserCannotAddAKey(): void {
		$allowlist = $this->allowlist();

		$response = $this->controller(uid: 'anja', isAdmin: false, allowlist: $allowlist, key: 'DATABASE_PASSWORD')->add();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $allowlist->entries(), 'and the allowlist is unchanged');
	}//end testAnOrdinaryUserCannotAddAKey()

	/**
	 * An anonymous caller gets 401, not 403.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsUnauthorised(): void {
		$response = $this->controller(uid: null, isAdmin: false, allowlist: $this->allowlist())->index();

		$this->assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerIsUnauthorised()

	/**
	 * The control: an administrator adds a key and it is recorded with them.
	 *
	 * @return void
	 */
	public function testAnAdministratorAddsAKeyAndItIsRecorded(): void {
		$allowlist = $this->allowlist();

		$response = $this->controller(uid: 'beheerder', isAdmin: true, allowlist: $allowlist, key: 'BRP_BASE_URL')->add();

		$this->assertSame(201, $response->getStatus(), 'the control: an administrator may');
		$this->assertSame('beheerder', $allowlist->entries()['BRP_BASE_URL']['addedBy']);
	}//end testAnAdministratorAddsAKeyAndItIsRecorded()

	/**
	 * 🔴 A wildcard is refused at the endpoint, with the reason.
	 *
	 * @return void
	 */
	public function testAWildcardIsRefusedAtTheEndpoint(): void {
		$allowlist = $this->allowlist();

		$response = $this->controller(uid: 'beheerder', isAdmin: true, allowlist: $allowlist, key: '*')->add();

		$this->assertSame(422, $response->getStatus());
		$this->assertStringContainsString('exact name', (string)$response->getData()['error']);
		$this->assertSame([], $allowlist->entries());
	}//end testAWildcardIsRefusedAtTheEndpoint()

	/**
	 * 🔴 The rendered list shows keys and never a value.
	 *
	 * @return void
	 */
	public function testTheRenderedListShowsKeysAndNeverAValue(): void {
		$allowlist = $this->allowlist();
		$allowlist->add(key: 'SMTP_PASSWORD', principal: 'beheerder');

		$data = $this->controller(uid: 'beheerder', isAdmin: true, allowlist: $allowlist)->index()->getData();

		$this->assertSame('SMTP_PASSWORD', $data['keys'][0]['key']);
		$this->assertSame(
			['key', 'addedBy', 'addedAt'],
			array_keys($data['keys'][0]),
			'a value here would publish the secret to everyone who can open the page'
		);
	}//end testTheRenderedListShowsKeysAndNeverAValue()

	/**
	 * 🔴 Both guards are present: the attribute AND the in-body check.
	 *
	 * A guard that lives only in an attribute disappears the moment somebody
	 * adds a route by hand or calls the method from another service.
	 *
	 * @return void
	 */
	public function testEveryEndpointCarriesBothGuards(): void {
		$source = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/ExpressionSourceController.php'
		);

		$this->assertSame(
			3,
			substr_count($source, '#[AuthorizedAdminSetting('),
			'every endpoint must carry the attribute'
		);
		$this->assertSame(
			3,
			substr_count($source, '$refusal = $this->requireAdmin();'),
			'and ask again in its body'
		);
	}//end testEveryEndpointCarriesBothGuards()
}//end class
