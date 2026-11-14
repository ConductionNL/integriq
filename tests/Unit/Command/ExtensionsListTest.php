<?php

/**
 * Unit tests for `occ integriq:extensions`.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
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

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\ExtensionsList;
use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;
use OCA\Integriq\Service\Payment\PaymentProviderInterface;
use OCA\Integriq\Service\Payment\PaymentProviderRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * GIVEN a refused contribution WHEN `occ integriq:extensions` runs THEN it is
 * listed as refused, beside what was registered.
 *
 * @covers \OCA\Integriq\Command\ExtensionsList
 * @uses   \OCA\Integriq\Service\Extension\ConnectorExtensionCollector
 * @uses   \OCA\Integriq\Event\RegisterConnectorExtensionsEvent
 * @uses   \OCA\Integriq\Intake\IntakeChannelRegistry
 * @uses   \OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry
 * @uses   \OCA\Integriq\Service\Payment\PaymentProviderRegistry
 * @uses   \OCA\Integriq\Service\Helper\ProviderIndex
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 */
class ExtensionsListTest extends TestCase {

	/**
	 * The command over registries a stub listener contributed to.
	 *
	 * @param callable $listener Receives the RegisterConnectorExtensionsEvent.
	 *
	 * @return CommandTester
	 */
	private function tester(callable $listener): CommandTester {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback($listener);
		$logger = $this->createMock(LoggerInterface::class);
		$collector = new ConnectorExtensionCollector(dispatcher: $dispatcher, logger: $logger);

		$teams = $this->createMock(IntakeChannelAdapterInterface::class);
		$teams->method('getChannelId')->willReturn('teams');

		return new CommandTester(
			new ExtensionsList(
				collector: $collector,
				intake: new IntakeChannelRegistry(logger: $logger, adapters: [$teams], extensions: $collector),
				digitalPost: new DigitalPostProviderRegistry(providers: [], extensions: $collector),
				payments: new PaymentProviderRegistry(providers: [], extensions: $collector)
			)
		);
	}//end tester()

	/**
	 * A registered and a refused contribution are both listed, and the run fails on the refusal.
	 *
	 * @return void
	 */
	public function testARefusedContributionIsListedAsRefused(): void {
		$chat = $this->createMock(IntakeChannelAdapterInterface::class);
		$chat->method('getChannelId')->willReturn('chat');
		$impostor = $this->createMock(IntakeChannelAdapterInterface::class);
		$impostor->method('getChannelId')->willReturn('teams');
		$buckaroo = $this->createMock(PaymentProviderInterface::class);
		$buckaroo->method('getProviderId')->willReturn('buckaroo');

		$tester = $this->tester(
			static function (RegisterConnectorExtensionsEvent $event) use ($chat, $impostor, $buckaroo): void {
				$event->addIntakeChannel(adapter: $chat, appId: 'chatbridge');
				$event->addIntakeChannel(adapter: $impostor, appId: 'otherapp');
				$event->addPaymentProvider(provider: $buckaroo, appId: 'payapp');
			}
		);

		$this->assertSame(1, $tester->execute([]));
		$lines = array_values(array_filter(explode("\n", trim($tester->getDisplay()))));
		$this->assertCount(3, $lines);
		$this->assertStringStartsWith('chatbridge  intake channel  chat  registered', $lines[0]);
		$this->assertStringStartsWith('otherapp  intake channel  teams  REFUSED: the id "teams" is already registered by integriq', $lines[1]);
		$this->assertStringStartsWith('payapp  payment provider  buckaroo  registered', $lines[2]);
	}//end testARefusedContributionIsListedAsRefused()

	/**
	 * Without an extension the command says so and succeeds; --json prints the empty record.
	 *
	 * @return void
	 */
	public function testWithoutAnExtensionItSaysSo(): void {
		$tester = $this->tester(static function (): void {
		});
		$this->assertSame(0, $tester->execute([]));
		$this->assertSame('No extension app contributed anything.', trim($tester->getDisplay()));

		$tester = $this->tester(static function (): void {
		});
		$this->assertSame(0, $tester->execute(['--json' => true]));
		$this->assertSame([], json_decode($tester->getDisplay(), true));
	}//end testWithoutAnExtensionItSaysSo()
}//end class
