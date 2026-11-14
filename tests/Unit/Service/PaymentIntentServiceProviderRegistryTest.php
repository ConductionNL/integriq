<?php

/**
 * Unit tests for how PaymentIntentService picks its provider through the registry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;
use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\Payment\PaymentProviderInterface;
use OCA\Integriq\Service\Payment\PaymentProviderRegistry;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GIVEN provider `mollie` the Mollie binding runs; GIVEN a contributed
 * `buckaroo` provider a source that names it runs it; an unknown id still
 * falls back to the log binding.
 *
 * @covers \OCA\Integriq\Service\PaymentIntentService
 * @uses   \OCA\Integriq\Service\Payment\PaymentProviderRegistry
 * @uses   \OCA\Integriq\Service\Extension\ConnectorExtensionCollector
 * @uses   \OCA\Integriq\Event\RegisterConnectorExtensionsEvent
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */
class PaymentIntentServiceProviderRegistryTest extends TestCase {

	/**
	 * Create a payment with a source naming `$providerId`, and return which binding ran.
	 *
	 * @param string $providerId The provider the source names.
	 *
	 * @return string The id of the binding whose createPayment() ran.
	 */
	private function providerThatRuns(string $providerId): string {
		$ran = [];
		$binding = function (string $id) use (&$ran): PaymentProviderInterface {
			$provider = $this->createMock(PaymentProviderInterface::class);
			$provider->method('getProviderId')->willReturn($id);
			$provider->method('createPayment')->willReturnCallback(
				static function () use ($id, &$ran): array {
					$ran[] = $id;
					return ['providerPaymentId' => 'P-1', 'paymentStatus' => 'open', 'checkoutUrl' => 'https://pay.example/1', 'extras' => []];
				}
			);
			return $provider;
		};

		$log = $this->createMock(LogPaymentProvider::class);
		$log->method('createPayment')->willReturnCallback(
			static function () use (&$ran): array {
				$ran[] = 'log';
				return ['providerPaymentId' => 'P-1', 'paymentStatus' => 'open', 'checkoutUrl' => 'https://pay.example/1', 'extras' => []];
			}
		);

		$buckaroo = $binding('buckaroo');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (RegisterConnectorExtensionsEvent $event) use ($buckaroo): void {
				$event->addPaymentProvider(provider: $buckaroo, appId: 'payapp');
			}
		);
		$registry = new PaymentProviderRegistry(
			providers: [$binding('mollie')],
			extensions: new ConnectorExtensionCollector(dispatcher: $dispatcher, logger: $this->createMock(LoggerInterface::class))
		);

		$source = new ObjectEntity();
		$source->setObject(['slug' => 'payments', 'type' => 'payment', 'configuration' => ['provider' => $providerId]]);
		$source->setUuid('source-1');
		$saved = new ObjectEntity();
		$saved->setObject(['providerPaymentId' => 'P-1']);
		$saved->setUuid('pi-1');

		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);
		$objects->method('saveObject')->willReturn($saved);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$service = new PaymentIntentService(
			objectService: $objects,
			logProvider: $log,
			mollieProvider: $this->createMock(MolliePaymentProvider::class),
			eventService: $this->createMock(EventService::class),
			l: $l,
			logger: $this->createMock(LoggerInterface::class),
			providers: $registry
		);
		$service->createPayment(
			payload: [
				'amount' => ['value' => '10.00', 'currency' => 'EUR'],
				'description' => 'Invoice INV-1',
				'redirectUrl' => 'https://example.com/return',
				'webhookUrl' => 'https://example.com/webhook',
			]
		);

		$this->assertCount(1, $ran);
		return $ran[0];
	}//end providerThatRuns()

	/**
	 * Mollie runs through the registry.
	 *
	 * @return void
	 */
	public function testMollieRunsThroughTheRegistry(): void {
		$this->assertSame('mollie', $this->providerThatRuns(providerId: 'mollie'));
	}//end testMollieRunsThroughTheRegistry()

	/**
	 * A contributed provider runs when a source names it.
	 *
	 * @return void
	 */
	public function testAContributedProviderRunsWhenASourceNamesIt(): void {
		$this->assertSame('buckaroo', $this->providerThatRuns(providerId: 'buckaroo'));
	}//end testAContributedProviderRunsWhenASourceNamesIt()

	/**
	 * An id nothing answers to falls back to the log binding, as before.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderFallsBackToTheLogBinding(): void {
		$this->assertSame('log', $this->providerThatRuns(providerId: 'adyen'));
	}//end testAnUnknownProviderFallsBackToTheLogBinding()
}//end class
