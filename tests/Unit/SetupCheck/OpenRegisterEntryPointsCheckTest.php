<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\SetupCheck;

use OCA\Integriq\SetupCheck\OpenRegisterEntryPointsCheck;
use OCA\OpenRegister\Service\AuthorizationService;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The setup check reports an OpenRegister without the public credential checks as an error.
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */
final class OpenRegisterEntryPointsCheckTest extends TestCase {


	/**
	 * The check over a container that answers the given service (or throws).
	 *
	 * @param object|RuntimeException $service What the container returns for AuthorizationService.
	 *
	 * @return OpenRegisterEntryPointsCheck
	 */
	private function check(object $service): OpenRegisterEntryPointsCheck {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof RuntimeException) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')->with(OpenRegisterEntryPointsCheck::AUTHORIZATION_SERVICE)->willReturn($service);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new OpenRegisterEntryPointsCheck(container: $container, l10n: $l10n);
	}//end check()


	/**
	 * A current OpenRegister (every entry point public) passes.
	 *
	 * @return void
	 */
	public function testACurrentOpenRegisterPasses(): void {
		$result = $this->check($this->createMock(AuthorizationService::class))->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}//end testACurrentOpenRegisterPasses()


	/**
	 * An OpenRegister whose checks are protected or absent is an error that names the minimum version.
	 *
	 * @return void
	 */
	public function testAnOldOpenRegisterIsAnError(): void {
		$old = new class {
			/**
			 * The old shape: the check exists but is not public.
			 *
			 * @return void
			 */
			protected function authorizeJwt(): void {
			}//end authorizeJwt()
		};

		$result = $this->check($old)->run();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString(OpenRegisterEntryPointsCheck::MINIMUM_OPENREGISTER, (string)$result->getDescription());
		$this->assertStringContainsString('401', (string)$result->getDescription());
	}//end testAnOldOpenRegisterIsAnError()


	/**
	 * A service that cannot be resolved is a warning, not a crash of the admin overview.
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceIsAWarning(): void {
		$result = $this->check(new RuntimeException('no openregister'))->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
	}//end testAnUnresolvableServiceIsAWarning()
}//end class
