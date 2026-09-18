<?php

/**
 * Integriq — subscription request handling tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registry
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

namespace OCA\Integriq\Tests\Unit\Service\Registry;

use OCA\Integriq\Service\Registry\LogSubscriptionProvider;
use OCA\Integriq\Service\Registry\RegistryUpdateClient;
use OCA\Integriq\Service\Registry\SubscriptionRegistry;
use OCA\Integriq\Service\Registry\SubscriptionRequestHandler;
use OCA\Integriq\Service\Registry\SubscriptionRoster;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-RSC-002: a request becomes a live subscription, and the state is
 * reported back.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-request-is-turned-into-a-live-subscription-req-rsc-002
 */
class SubscriptionRequestHandlerTest extends TestCase {
	/**
	 * In-memory app-config store behind the roster.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * A roster backed by the in-memory store.
	 *
	 * @return SubscriptionRoster The roster.
	 */
	private function roster(): SubscriptionRoster {
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

		return new SubscriptionRoster($appConfig);
	}//end roster()

	/**
	 * An update-client double restricted to the method the real class has.
	 *
	 * @return RegistryUpdateClient The double.
	 */
	private function updateClient(): RegistryUpdateClient {
		return $this->getMockBuilder(RegistryUpdateClient::class)
			->disableOriginalConstructor()
			->onlyMethods(['postUpdate'])
			->getMock();
	}//end updateClient()

	/**
	 * A request handled by the log binding subscribes that identity and
	 * reports `active`.
	 *
	 * @return void
	 */
	public function testARequestIsHandledByTheBoundProvider(): void {
		$updateClient = $this->updateClient();
		$updateClient->expects($this->once())
			->method('postUpdate')
			->with(
				'log',
				$this->callback(
					static function (array $payload): bool {
						return $payload['identity'] === '999993653'
							&& $payload['properties'] === []
							&& $payload['subscriptionState'] === 'active';
					}
				)
			)
			->willReturn(200);

		$roster = $this->roster();
		$handler = new SubscriptionRequestHandler(
			new SubscriptionRegistry([new LogSubscriptionProvider($this->createMock(LoggerInterface::class))]),
			$roster,
			$updateClient,
			$this->createMock(LoggerInterface::class)
		);

		$result = $handler->handle(['registry' => 'log', 'identity' => '999993653']);

		$this->assertNotNull($result);
		$this->assertTrue($result->isActive());
		$this->assertArrayHasKey('999993653', $roster->identities('log'));
	}//end testARequestIsHandledByTheBoundProvider()

	/**
	 * A request naming a registry nothing answers to is refused, and nothing
	 * is reported back as though it had worked.
	 *
	 * @return void
	 */
	public function testARequestForAnUnknownRegistryIsRefused(): void {
		$updateClient = $this->updateClient();
		$updateClient->expects($this->never())->method('postUpdate');

		$handler = new SubscriptionRequestHandler(
			new SubscriptionRegistry([]),
			$this->roster(),
			$updateClient,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNull($handler->handle(['registry' => 'kadaster', 'identity' => '999993653']));
	}//end testARequestForAnUnknownRegistryIsRefused()

	/**
	 * A request without an identity is refused before any binding is called.
	 *
	 * @return void
	 */
	public function testARequestWithoutAnIdentityIsRefused(): void {
		$updateClient = $this->updateClient();
		$updateClient->expects($this->never())->method('postUpdate');

		$handler = new SubscriptionRequestHandler(
			new SubscriptionRegistry([new LogSubscriptionProvider($this->createMock(LoggerInterface::class))]),
			$this->roster(),
			$updateClient,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNull($handler->handle(['registry' => 'log']));
	}//end testARequestWithoutAnIdentityIsRefused()

	/**
	 * A roster holds identities and references, and nothing about the person.
	 *
	 * @return void
	 */
	public function testTheRosterHoldsIdentitiesOnly(): void {
		$roster = $this->roster();
		$roster->add('brp', '999993653', 'vi-42');

		$this->assertSame(['999993653' => 'vi-42'], $roster->identities('brp'));

		$roster->remove('brp', '999993653');

		$this->assertSame([], $roster->identities('brp'));
	}//end testTheRosterHoldsIdentitiesOnly()
}//end class
