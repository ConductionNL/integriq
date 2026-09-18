<?php

/**
 * Integriq — Digikoppeling broker selection tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Gateway;

use OCA\Integriq\Gateway\BrokerConfigurationException;
use OCA\Integriq\Gateway\DigikoppelingBrokerResolver;
use OCA\Integriq\Gateway\ZgwRegistryBinding;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-SG-002 and REQ-SG-006.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-the-digikoppeling-broker-is-chosen-per-instance-req-sg-002
 */
class DigikoppelingBrokerResolverTest extends TestCase {
	/**
	 * In-memory app-config store.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * An app-config double reading and writing the in-memory store.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Seed two configured brokers.
	 *
	 * @return void
	 */
	private function seedTwoBrokers(): void {
		$this->config[DigikoppelingBrokerResolver::BROKERS_KEY] = json_encode(
			[
				'logius' => ['endpoint' => 'https://broker-a.test/wus', 'label' => 'Broker A'],
				'gemma' => ['endpoint' => 'https://broker-b.test/wus', 'label' => 'Broker B'],
			]
		);
	}//end seedTwoBrokers()

	/**
	 * An instance moves from one broker to another with a save, and the
	 * previous choice stays in the audit trail.
	 *
	 * @return void
	 */
	public function testAnInstanceMovesFromOneBrokerToAnother(): void {
		$this->seedTwoBrokers();
		$resolver = new DigikoppelingBrokerResolver($this->appConfig(), $this->createMock(LoggerInterface::class));

		$resolver->select('logius', 'beheerder');
		$this->assertSame('https://broker-a.test/wus', $resolver->resolve()['endpoint']);

		$audit = $resolver->select('gemma', 'beheerder');

		$this->assertSame('https://broker-b.test/wus', $resolver->resolve()['endpoint']);
		$this->assertCount(2, $audit);
		$this->assertSame('logius', $audit[1]['from'], 'The previous choice has to survive in the audit trail.');
		$this->assertSame('gemma', $audit[1]['to']);
		$this->assertSame('beheerder', $audit[1]['by']);
	}//end testAnInstanceMovesFromOneBrokerToAnother()

	/**
	 * No broker fails before the call, naming what is missing.
	 *
	 * @return void
	 */
	public function testNoBrokerFailsBeforeTheCall(): void {
		$resolver = new DigikoppelingBrokerResolver($this->appConfig(), $this->createMock(LoggerInterface::class));

		try {
			$resolver->resolve();
			$this->fail('An unconfigured broker must fail before anything is sent.');
		} catch (BrokerConfigurationException $e) {
			$this->assertStringContainsString(DigikoppelingBrokerResolver::SELECTED_KEY, $e->getMessage());
			$this->assertStringContainsString('Nothing was sent', $e->getMessage());
		}
	}//end testNoBrokerFailsBeforeTheCall()

	/**
	 * A selected broker that is not configured fails naming the ones that are.
	 *
	 * @return void
	 */
	public function testASelectedButUnconfiguredBrokerFails(): void {
		$this->seedTwoBrokers();
		$this->config[DigikoppelingBrokerResolver::SELECTED_KEY] = 'derde-partij';
		$resolver = new DigikoppelingBrokerResolver($this->appConfig(), $this->createMock(LoggerInterface::class));

		$this->expectException(BrokerConfigurationException::class);
		$this->expectExceptionMessageMatches('/logius, gemma/');

		$resolver->resolve();
	}//end testASelectedButUnconfiguredBrokerFails()

	/**
	 * Selecting a broker nobody configured is refused, so the instance cannot
	 * end up pointing at nothing.
	 *
	 * @return void
	 */
	public function testSelectingAnUnconfiguredBrokerIsRefused(): void {
		$this->seedTwoBrokers();
		$resolver = new DigikoppelingBrokerResolver($this->appConfig(), $this->createMock(LoggerInterface::class));

		$this->expectException(BrokerConfigurationException::class);

		$resolver->select('derde-partij');
	}//end testSelectingAnUnconfiguredBrokerIsRefused()

	/**
	 * A ZGW binding that resolves to OpenRegister is usable without a call.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterBindingIsUsable(): void {
		$binding = new ZgwRegistryBinding(
			$this->appConfig(),
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class)
		);

		$verdict = $binding->test();

		$this->assertTrue($verdict['ok']);
		$this->assertTrue($binding->isUsable());
	}//end testAnOpenRegisterBindingIsUsable()

	/**
	 * An unreachable external binding fails at test time, names the endpoint,
	 * and is not marked usable.
	 *
	 * @return void
	 */
	public function testAnUnreachableBindingFailsAtTestTimeAndIsNotUsable(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('Could not resolve host'));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$binding = new ZgwRegistryBinding($this->appConfig(), $clientService, $this->createMock(LoggerInterface::class));

		$verdict = $binding->test(['kind' => 'external', 'baseUrl' => 'https://zaken.invalid/api/v1']);

		$this->assertFalse($verdict['ok']);
		$this->assertStringContainsString('zaken.invalid', $verdict['message']);
		$this->assertFalse($binding->isUsable(), 'A binding that failed its test must not be marked usable.');
	}//end testAnUnreachableBindingFailsAtTestTimeAndIsNotUsable()

	/**
	 * An external registry that answers is usable, and a 401 counts as an
	 * answer: needing credentials is a different problem from not being there.
	 *
	 * @return void
	 */
	public function testAReachableExternalRegistryIsUsableEvenWhenItAsksForCredentials(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(401);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$binding = new ZgwRegistryBinding($this->appConfig(), $clientService, $this->createMock(LoggerInterface::class));

		$this->assertTrue($binding->test(['kind' => 'external', 'baseUrl' => 'https://zaken.test/api/v1'])['ok']);
	}//end testAReachableExternalRegistryIsUsableEvenWhenItAsksForCredentials()

	/**
	 * An external binding with no base URL fails without a call.
	 *
	 * @return void
	 */
	public function testAnExternalBindingWithNoBaseUrlFails(): void {
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->never())->method('newClient');

		$binding = new ZgwRegistryBinding($this->appConfig(), $clientService, $this->createMock(LoggerInterface::class));

		$this->assertFalse($binding->test(['kind' => 'external', 'baseUrl' => ''])['ok']);
	}//end testAnExternalBindingWithNoBaseUrlFails()
}//end class
