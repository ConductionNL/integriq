<?php

/**
 * Unit tests for the connector extension collect event and the registries it fills.
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
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Extension;

use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;
use OCA\Integriq\Service\Payment\PaymentProviderInterface;
use OCA\Integriq\Service\Payment\PaymentProviderRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An extension app answers the collect event with its adapters; each registry
 * keeps integriq's built-ins, adds the contributions with their app id, and
 * refuses and logs one that claims a built-in's id.
 *
 * @covers \OCA\Integriq\Event\RegisterConnectorExtensionsEvent
 * @covers \OCA\Integriq\Service\Extension\ConnectorExtensionCollector
 * @covers \OCA\Integriq\Intake\IntakeChannelRegistry
 * @covers \OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry
 * @covers \OCA\Integriq\Service\Payment\PaymentProviderRegistry
 * @uses   \OCA\Integriq\Service\Helper\ProviderIndex
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */
class ConnectorExtensionKitTest extends TestCase {

	/**
	 * The logger the collector reports a refusal to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * How many times the collect event was dispatched.
	 *
	 * @var integer
	 */
	private int $dispatched = 0;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A collector whose dispatcher runs one stub listener on the real event.
	 *
	 * @param callable $listener Receives the RegisterConnectorExtensionsEvent.
	 *
	 * @return ConnectorExtensionCollector
	 */
	private function collector(callable $listener): ConnectorExtensionCollector {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($listener): void {
				$this->dispatched++;
				$this->assertInstanceOf(RegisterConnectorExtensionsEvent::class, $event);
				$listener($event);
			}
		);

		return new ConnectorExtensionCollector(dispatcher: $dispatcher, logger: $this->logger);
	}//end collector()

	/**
	 * An intake channel adapter answering to an id.
	 *
	 * @param string $id The channel id.
	 *
	 * @return IntakeChannelAdapterInterface
	 */
	private function channel(string $id): IntakeChannelAdapterInterface {
		$adapter = $this->createMock(IntakeChannelAdapterInterface::class);
		$adapter->method('getChannelId')->willReturn($id);
		return $adapter;
	}//end channel()

	/**
	 * A digital post provider answering to an id.
	 *
	 * @param string $id The provider id.
	 *
	 * @return DigitalPostProviderInterface
	 */
	private function post(string $id): DigitalPostProviderInterface {
		$provider = $this->createMock(DigitalPostProviderInterface::class);
		$provider->method('getProviderId')->willReturn($id);
		return $provider;
	}//end post()

	/**
	 * A payment provider answering to an id.
	 *
	 * @param string $id The provider id.
	 *
	 * @return PaymentProviderInterface
	 */
	private function payment(string $id): PaymentProviderInterface {
		$provider = $this->createMock(PaymentProviderInterface::class);
		$provider->method('getProviderId')->willReturn($id);
		return $provider;
	}//end payment()

	/**
	 * With no extension answering, each registry holds exactly its built-ins.
	 *
	 * @return void
	 */
	public function testWithoutAnExtensionEachRegistryHoldsItsBuiltIns(): void {
		$collector = $this->collector(static function (): void {
		});

		$intake = new IntakeChannelRegistry(logger: $this->logger, adapters: [$this->channel('forms'), $this->channel('teams')], extensions: $collector);
		$post = new DigitalPostProviderRegistry(providers: [$this->post('berichtenbox'), $this->post('log')], extensions: $collector);
		$payments = new PaymentProviderRegistry(providers: [$this->payment('mollie'), $this->payment('log')], extensions: $collector);

		$this->assertSame(['forms', 'teams'], $intake->getChannelIds());
		$this->assertSame(['berichtenbox', 'log'], $post->ids());
		$this->assertSame(['mollie', 'log'], $payments->ids());
		$this->assertSame('integriq', $intake->appIdOf('teams'));
		$this->assertSame([], $collector->record());
		$this->assertSame(1, $this->dispatched, 'The event is dispatched once for every registry together.');
	}//end testWithoutAnExtensionEachRegistryHoldsItsBuiltIns()

	/**
	 * A contributed channel is registered with its app id, on first use.
	 *
	 * @return void
	 */
	public function testAContributedChannelIsRegisteredWithItsAppId(): void {
		$chat = $this->channel('chat');
		$collector = $this->collector(static function (RegisterConnectorExtensionsEvent $event) use ($chat): void {
			$event->addIntakeChannel(adapter: $chat, appId: 'chatbridge');
		});

		$intake = new IntakeChannelRegistry(logger: $this->logger, adapters: [$this->channel('teams')], extensions: $collector);
		$this->assertSame(0, $this->dispatched, 'Nothing is asked before the registry is used.');

		$this->assertTrue($intake->has('chat'));
		$this->assertSame($chat, $intake->get('chat'));
		$this->assertSame('chatbridge', $intake->appIdOf('chat'));
		$this->assertSame(['chat', 'teams'], $intake->getChannelIds());
		$this->assertSame(
			[['seam' => 'intake channel', 'id' => 'chat', 'appId' => 'chatbridge', 'class' => $chat::class, 'refused' => false, 'reason' => '']],
			$collector->record()
		);
	}//end testAContributedChannelIsRegisteredWithItsAppId()

	/**
	 * A contribution claiming a built-in's channel id is refused, the built-in
	 * keeps it, and the log names the app and the class.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-adds-and-never-replaces-a-built-in-req-cxk-002
	 */
	public function testAChannelClaimingABuiltInIdIsRefusedAndLogged(): void {
		$builtIn = $this->channel('teams');
		$impostor = $this->channel('teams');
		$collector = $this->collector(static function (RegisterConnectorExtensionsEvent $event) use ($impostor): void {
			$event->addIntakeChannel(adapter: $impostor, appId: 'otherapp');
		});

		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('refused'),
			$this->callback(
				static fn (array $context): bool => $context['app'] === 'otherapp'
					&& $context['class'] === $impostor::class
					&& $context['seam'] === 'intake channel'
			)
		);

		$intake = new IntakeChannelRegistry(logger: $this->logger, adapters: [$builtIn], extensions: $collector);

		$this->assertSame($builtIn, $intake->get('teams'));
		$this->assertSame('integriq', $intake->appIdOf('teams'));
		$record = $collector->record();
		$this->assertCount(1, $record);
		$this->assertTrue($record[0]['refused']);
		$this->assertSame('otherapp', $record[0]['appId']);
		$this->assertSame('the id "teams" is already registered by integriq', $record[0]['reason']);
	}//end testAChannelClaimingABuiltInIdIsRefusedAndLogged()

	/**
	 * A contributed digital post provider is added; one claiming `log` is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-adds-and-never-replaces-a-built-in-req-cxk-002
	 */
	public function testDigitalPostContributionsAreAddedOrRefused(): void {
		$builtIn = $this->post('log');
		$mijnpost = $this->post('mijnpost');
		$impostor = $this->post('log');
		$collector = $this->collector(static function (RegisterConnectorExtensionsEvent $event) use ($mijnpost, $impostor): void {
			$event->addDigitalPostProvider(provider: $mijnpost, appId: 'postapp');
			$event->addDigitalPostProvider(provider: $impostor, appId: 'postapp');
		});
		$this->logger->expects($this->once())->method('warning');

		$post = new DigitalPostProviderRegistry(providers: [$builtIn], extensions: $collector);

		$this->assertSame(['log', 'mijnpost'], $post->ids());
		$this->assertSame($builtIn, $post->get('log'));
		$this->assertSame($mijnpost, $post->get('mijnpost'));
		$this->assertSame('postapp', $post->appIdOf('mijnpost'));
		$this->assertSame([false, true], array_column($collector->record(), 'refused'));
	}//end testDigitalPostContributionsAreAddedOrRefused()

	/**
	 * A contributed `buckaroo` payment provider is found by id; one claiming `mollie` is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-adds-and-never-replaces-a-built-in-req-cxk-002
	 */
	public function testPaymentContributionsAreAddedOrRefused(): void {
		$mollie = $this->payment('mollie');
		$buckaroo = $this->payment('buckaroo');
		$impostor = $this->payment('mollie');
		$collector = $this->collector(static function (RegisterConnectorExtensionsEvent $event) use ($buckaroo, $impostor): void {
			$event->addPaymentProvider(provider: $buckaroo, appId: 'payapp');
			$event->addPaymentProvider(provider: $impostor, appId: 'payapp');
		});
		$this->logger->expects($this->once())->method('warning');

		$payments = new PaymentProviderRegistry(providers: [$mollie], extensions: $collector);

		$this->assertSame($mollie, $payments->find('mollie'));
		$this->assertSame($buckaroo, $payments->find('buckaroo'));
		$this->assertNull($payments->find('adyen'));
		$this->assertSame('payapp', $payments->appIdOf('buckaroo'));
		$this->assertSame(['mollie', 'buckaroo'], $payments->ids());
	}//end testPaymentContributionsAreAddedOrRefused()

	/**
	 * Template directories are collected with their app id, in order.
	 *
	 * @return void
	 */
	public function testTemplateDirectoriesAreCollected(): void {
		$collector = $this->collector(static function (RegisterConnectorExtensionsEvent $event): void {
			$event->addConnectorTemplates(directory: '/apps/chatbridge/templates', appId: 'chatbridge');
		});

		$this->assertSame(
			[['directory' => '/apps/chatbridge/templates', 'appId' => 'chatbridge']],
			$collector->collect()->getConnectorTemplates()
		);
		$collector->collect();
		$this->assertSame(1, $this->dispatched);
	}//end testTemplateDirectoriesAreCollected()
}//end class
