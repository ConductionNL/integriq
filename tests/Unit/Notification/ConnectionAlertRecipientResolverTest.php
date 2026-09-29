<?php

/**
 * Unit tests for ConnectionAlertRecipientResolver: who hears about an opened alert.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Notification;

use OCA\Integriq\Notification\ConnectionAlertRecipientResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\RecipientResolverInterface;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The members of the group named in the app setting, and nobody when none is named.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */
final class ConnectionAlertRecipientResolverTest extends TestCase {

	/**
	 * Build the resolver with an app setting and a group manager holding one group.
	 *
	 * @param string $setting The value of connection_alert_group.
	 *
	 * @return ConnectionAlertRecipientResolver
	 */
	private function makeResolver(string $setting): ConnectionAlertRecipientResolver {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'integriq' && $key === ConnectionAlertRecipientResolver::CONFIG_KEY) ? $setting : $default
		);

		$users = [];
		foreach (['beheer-1', 'beheer-2'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			static fn (string $gid): ?IGroup => ($gid === 'koppelbeheer') ? $group : null
		);

		return new ConnectionAlertRecipientResolver($appConfig, $groups, $this->createMock(LoggerInterface::class));
	}//end makeResolver()

	/**
	 * It is an expression resolver OpenRegister accepts.
	 *
	 * @return void
	 */
	public function testItImplementsTheResolverContract(): void {
		$this->assertInstanceOf(RecipientResolverInterface::class, $this->makeResolver(''));
	}//end testItImplementsTheResolverContract()

	/**
	 * No group named: nobody is notified.
	 *
	 * @return void
	 */
	public function testNoGroupNamedNotifiesNobody(): void {
		$this->assertSame([], $this->makeResolver('')->resolve(new ObjectEntity(), []));
	}//end testNoGroupNamedNotifiesNobody()

	/**
	 * A named group: its members.
	 *
	 * @return void
	 */
	public function testANamedGroupNotifiesItsMembers(): void {
		$this->assertSame(['beheer-1', 'beheer-2'], $this->makeResolver('koppelbeheer')->resolve(new ObjectEntity(), []));
	}//end testANamedGroupNotifiesItsMembers()

	/**
	 * A group that does not exist: nobody, not an error.
	 *
	 * @return void
	 */
	public function testAGroupThatDoesNotExistNotifiesNobody(): void {
		$this->assertSame([], $this->makeResolver('openconnector-ops')->resolve(new ObjectEntity(), []));
	}//end testAGroupThatDoesNotExistNotifiesNobody()

	/**
	 * The register's rule names this class, so OpenRegister can find it.
	 *
	 * @return void
	 */
	public function testTheRegisterRuleNamesThisResolver(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/observability-connection-run-summary.json'),
			true
		);
		$rule = $fragment['components']['schemas']['connection_alert']['x-openregister-notifications']['threshold-passed'];

		$this->assertSame(['type' => 'created'], $rule['trigger']);
		$this->assertTrue($rule['enabled']);
		$this->assertSame(
			[['kind' => 'expression', 'resolver' => ConnectionAlertRecipientResolver::class]],
			$rule['recipients']
		);
	}//end testTheRegisterRuleNamesThisResolver()
}//end class
