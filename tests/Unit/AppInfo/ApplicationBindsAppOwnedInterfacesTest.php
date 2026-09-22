<?php

/**
 * `Application::register()` actually binds the app-owned interfaces.
 *
 * The sibling {@see AppOwnedInterfaceBindingTest} reads the SOURCE of
 * Application.php and asserts a binding is written down. That is the right
 * shape for the property — it holds for interfaces that do not exist yet — but
 * it proves the text, not the wiring. This test runs `register()` against a
 * recording context and asserts the aliases are really registered.
 *
 * Both exist on purpose: the source scan catches a NEW bare interface with no
 * binding, and this catches a binding that is present but never executed —
 * behind a conditional, after an early return, or in a branch that throws.
 *
 * The finding behind both: `DnsResolverInterface` and `CallDispatcherInterface`
 * were required constructor parameters with exactly one implementation each and
 * nothing binding them, so `SenderIdentityController` and `CallLogController`
 * could not be constructed and nine routes answered 500 — including the public
 * `GET /unsubscribe/{token}` (integriq#1983 review 5278999788).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\AppInfo;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Outbound\Call\CallDispatcherInterface;
use OCA\Integriq\Outbound\Call\CallServiceDispatcher;
use OCA\Integriq\Outbound\Identity\DnsResolverInterface;
use OCA\Integriq\Outbound\Identity\SystemDnsResolver;
use OCA\Integriq\Tests\Helpers\AppContainerInjection;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ApplicationBindsAppOwnedInterfacesTest extends TestCase {

	use AppContainerInjection;

	/**
	 * Run `register()` and return every alias it declared.
	 *
	 * @return array<string,string> alias => target.
	 */
	private function recordedAliases(): array {
		$aliases = [];

		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		// newInstanceWithoutConstructor: the real App constructor builds a server
		// container, which pure-unit mode has no instance for. `register()` still
		// reaches for one to wire event listeners, so a double is injected — the
		// same shape the sibling Application tests use.
		$container = $this->createMock($this->appContainerType());
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === IEventDispatcher::class) {
					return $this->createMock(IEventDispatcher::class);
				}

				throw new \RuntimeException('unexpected container id: ' . $id);
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$this->injectAppContainer($app, $container);
		$app->register($context);

		return $aliases;

	}//end recordedAliases()

	/**
	 * The two interfaces whose absence answered 500 are aliased to their
	 * implementations.
	 *
	 * @return void
	 *
	 * @spec exclude Container-wiring invariant; no requirement states which interfaces exist.
	 */
	public function testTheBareAppOwnedInterfacesAreAliased(): void {
		$aliases = $this->recordedAliases();

		$this->assertArrayHasKey(
			DnsResolverInterface::class,
			$aliases,
			'Without this alias DomainAlignmentChecker cannot be constructed and SenderIdentityController 500s.'
		);
		$this->assertSame(SystemDnsResolver::class, $aliases[DnsResolverInterface::class]);

		$this->assertArrayHasKey(
			CallDispatcherInterface::class,
			$aliases,
			'Without this alias CallReplayService cannot be constructed and CallLogController 500s.'
		);
		$this->assertSame(CallServiceDispatcher::class, $aliases[CallDispatcherInterface::class]);

	}//end testTheBareAppOwnedInterfacesAreAliased()
}//end class
