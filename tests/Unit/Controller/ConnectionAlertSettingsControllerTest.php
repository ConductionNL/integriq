<?php

/**
 * Unit tests for ConnectionAlertSettingsController: the group that hears about alerts.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ConnectionAlertSettingsController;
use OCA\Integriq\Notification\ConnectionAlertRecipientResolver;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Reads and sets connection_alert_group; refuses a group that does not exist.
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */
final class ConnectionAlertSettingsControllerTest extends TestCase {

	/**
	 * The stored app setting.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * Build the controller for one request.
	 *
	 * @param string $group The posted group id.
	 *
	 * @return ConnectionAlertSettingsController
	 */
	private function makeController(string $group = ''): ConnectionAlertSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'group') ? $group : $default
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('integriq', $app);
				$this->assertSame(ConnectionAlertRecipientResolver::CONFIG_KEY, $key);
				$this->stored = $value;
				return true;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => ($gid === 'koppelbeheer') ? $this->createMock(IGroup::class) : null
		);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return new ConnectionAlertSettingsController($request, $appConfig, $groups, $l);
	}//end makeController()

	/**
	 * Nothing is named on a fresh install.
	 *
	 * @return void
	 */
	public function testNoGroupIsNamedByDefault(): void {
		$this->assertSame(['group' => ''], $this->makeController()->getConfig()->getData());
	}//end testNoGroupIsNamedByDefault()

	/**
	 * An existing group is stored.
	 *
	 * @return void
	 */
	public function testAnExistingGroupIsStored(): void {
		$response = $this->makeController('koppelbeheer')->setConfig();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('koppelbeheer', $this->stored);
	}//end testAnExistingGroupIsStored()

	/**
	 * A group that does not exist is refused with 400 naming it, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAGroupThatDoesNotExistIsRefused(): void {
		$response = $this->makeController('openconnector-ops')->setConfig();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('openconnector-ops', $response->getData()['error']);
		$this->assertSame('', $this->stored);
	}//end testAGroupThatDoesNotExistIsRefused()

	/**
	 * An empty group clears the setting, so nobody is notified.
	 *
	 * @return void
	 */
	public function testAnEmptyGroupClearsTheSetting(): void {
		$this->stored = 'koppelbeheer';
		$response = $this->makeController('')->setConfig();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('', $this->stored);
	}//end testAnEmptyGroupClearsTheSetting()
}//end class
