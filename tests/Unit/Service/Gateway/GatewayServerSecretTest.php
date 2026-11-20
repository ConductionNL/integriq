<?php

/**
 * Unit tests for the gateway server secret check.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Gateway;

use OCA\Integriq\Service\Gateway\GatewayServerSecret;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(GatewayServerSecret::class)]
class GatewayServerSecretTest extends TestCase {

	/**
	 * A secret check over app config and a broker answering $resolve.
	 *
	 * @param array<string, string> $config  App config values by key.
	 * @param callable|null         $resolve What the broker's resolveInjectable does.
	 * @param array                 $asked   Filled with the broker's arguments.
	 *
	 * @return GatewayServerSecret
	 */
	private function secret(array $config, ?callable $resolve, array &$asked=[]): GatewayServerSecret {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default='') => ($app === 'integriq' ? ($config[$key] ?? $default) : $default)
		);

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('resolveInjectable')->willReturnCallback(
			function (...$args) use ($resolve, &$asked) {
				$asked[] = $args;
				return $resolve(...$args);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(GatewayServerSecret::BROKER_CLASS)->willReturn($broker);

		return new GatewayServerSecret(appConfig: $appConfig, container: $container, logger: $this->createMock(LoggerInterface::class));
	}

	public function testTheConfiguredSecretIsAccepted(): void {
		$asked = [];
		$secret = $this->secret(
			config: [GatewayServerSecret::CREDENTIAL_KEY => 'cred-1', GatewayServerSecret::ORGANISATION_KEY => 'org-1'],
			resolve: fn () => 's3cret-value',
			asked: $asked
		);

		$this->assertTrue($secret->verify(presented: 's3cret-value'));
		$this->assertSame([['cred-1', 'integriq', null, 'org-1']], $asked);
	}

	public function testAnotherSecretIsRefused(): void {
		$secret = $this->secret(config: [GatewayServerSecret::CREDENTIAL_KEY => 'cred-1'], resolve: fn () => 's3cret-value');

		$this->assertFalse($secret->verify(presented: 's3cret-valuf'));
		$this->assertFalse($secret->verify(presented: ''));
	}

	public function testNothingConfiguredRefusesEverySecret(): void {
		$asked = [];
		$secret = $this->secret(config: [], resolve: fn () => '', asked: $asked);

		$this->assertFalse($secret->verify(presented: 'anything'));
		$this->assertSame([], $asked, 'without a credential id the broker is not asked');
	}

	public function testABrokerRefusalOrAnEmptySecretRefuses(): void {
		$refusing = $this->secret(
			config: [GatewayServerSecret::CREDENTIAL_KEY => 'cred-1'],
			resolve: function () {
				throw new RuntimeException('denied');
			}
		);
		$empty = $this->secret(config: [GatewayServerSecret::CREDENTIAL_KEY => 'cred-1'], resolve: fn () => null);

		$this->assertFalse($refusing->verify(presented: 'x'));
		$this->assertFalse($empty->verify(presented: 'x'));
	}
}
