<?php

/**
 * Unit tests for ExtensionInventory.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Extension
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Extension;

use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;
use OCA\Integriq\Service\Extension\ExtensionInventory;
use OCA\Integriq\Service\Payment\PaymentProviderInterface;
use OCA\Integriq\Service\Payment\PaymentProviderRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GIVEN extension apps that contribute a digital post and a payment provider
 * WHEN the inventory is read THEN both registries have asked for them and the
 * record lists each, a refused one included.
 *
 * @covers \OCA\Integriq\Service\Extension\ExtensionInventory
 * @uses   \OCA\Integriq\Service\Extension\ConnectorExtensionCollector
 * @uses   \OCA\Integriq\Event\RegisterConnectorExtensionsEvent
 * @uses   \OCA\Integriq\Service\Extension\ConnectorExtensionsCollectEvent
 * @uses   \OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry
 * @uses   \OCA\Integriq\Service\Payment\PaymentProviderRegistry
 * @uses   \OCA\Integriq\Service\Helper\ProviderIndex
 */
class ExtensionInventoryTest extends TestCase {

	/**
	 * The collector, answering the collect event with the given listener.
	 *
	 * @param callable $listener The extension apps' listener.
	 *
	 * @return ConnectorExtensionCollector
	 */
	private function collector(callable $listener): ConnectorExtensionCollector {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback($listener);

		return new ConnectorExtensionCollector(dispatcher: $dispatcher, logger: $this->createMock(LoggerInterface::class));
	}//end collector()

	/**
	 * Reading the inventory builds both registries, so each contribution is recorded in order.
	 *
	 * @return void
	 */
	public function testTheRecordHoldsWhatEachRegistryTookAndRefused(): void {
		$post = $this->createMock(DigitalPostProviderInterface::class);
		$post->method('getProviderId')->willReturn('postbox');
		$builtIn = $this->createMock(PaymentProviderInterface::class);
		$builtIn->method('getProviderId')->willReturn('mollie');
		$impostor = $this->createMock(PaymentProviderInterface::class);
		$impostor->method('getProviderId')->willReturn('mollie');

		$collector = $this->collector(
			static function (RegisterConnectorExtensionsEvent $event) use ($post, $impostor): void {
				$event->addDigitalPostProvider(provider: $post, appId: 'postapp');
				$event->addPaymentProvider(provider: $impostor, appId: 'payapp');
			}
		);
		$inventory = new ExtensionInventory(
			collector: $collector,
			digitalPost: new DigitalPostProviderRegistry(providers: [], extensions: $collector),
			payments: new PaymentProviderRegistry(providers: [$builtIn], extensions: $collector)
		);

		$record = $inventory->record();

		$this->assertCount(2, $record);
		$this->assertSame(['digital post provider', 'postbox', 'postapp', false], [$record[0]['seam'], $record[0]['id'], $record[0]['appId'], $record[0]['refused']]);
		$this->assertSame(['payment provider', 'mollie', 'payapp', true], [$record[1]['seam'], $record[1]['id'], $record[1]['appId'], $record[1]['refused']]);
		$this->assertSame($record, $collector->record());
	}//end testTheRecordHoldsWhatEachRegistryTookAndRefused()

	/**
	 * Without a contribution the record is empty, and the event is still asked once.
	 *
	 * @return void
	 */
	public function testWithoutAContributionTheRecordIsEmpty(): void {
		$collector = $this->collector(static function (): void {
		});
		$inventory = new ExtensionInventory(
			collector: $collector,
			digitalPost: new DigitalPostProviderRegistry(providers: [], extensions: $collector),
			payments: new PaymentProviderRegistry(providers: [], extensions: $collector)
		);

		$this->assertSame([], $inventory->record());
	}//end testWithoutAContributionTheRecordIsEmpty()
}//end class
