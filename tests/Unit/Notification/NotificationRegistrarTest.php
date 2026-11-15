<?php

/**
 * Unit tests for NotificationRegistrar.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Notification
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

namespace OCA\Integriq\Tests\Unit\Notification;

use OCA\Integriq\Notification\AlertRecipientResolverInterface;
use OCA\Integriq\Notification\ApprovalNotifier;
use OCA\Integriq\Notification\ConnectionAlertRecipientResolver;
use OCA\Integriq\Notification\DsoConnectionNotifier;
use OCA\Integriq\Notification\NotificationRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * NotificationRegistrar binds the alert-recipient resolver and registers
 * both notifiers; without a registered notifier Nextcloud drops the
 * app's notifications when preparing them.
 *
 * @covers \OCA\Integriq\Notification\NotificationRegistrar
 */
class NotificationRegistrarTest extends TestCase {
	/**
	 * The resolver interface is aliased to the connection resolver.
	 *
	 * @return void
	 */
	public function testAliasesTheAlertRecipientResolver(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())
			->method('registerServiceAlias')
			->with(AlertRecipientResolverInterface::class, ConnectionAlertRecipientResolver::class);

		(new NotificationRegistrar())->register($context);
	}//end testAliasesTheAlertRecipientResolver()

	/**
	 * Both notifiers are registered, approval first, each exactly once.
	 *
	 * @return void
	 */
	public function testRegistersBothNotifiersInOrder(): void {
		$context    = $this->createMock(IRegistrationContext::class);
		$registered = [];
		$context->expects($this->exactly(2))
			->method('registerNotifierService')
			->willReturnCallback(static function (string $class) use (&$registered): void {
				$registered[] = $class;
			});

		(new NotificationRegistrar())->register($context);

		$this->assertSame([ApprovalNotifier::class, DsoConnectionNotifier::class], $registered);
	}//end testRegistersBothNotifiersInOrder()
}//end class
