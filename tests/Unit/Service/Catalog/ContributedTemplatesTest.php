<?php

/**
 * Unit tests for ContributedTemplates: an extension app's template directory
 * becomes Store cards marked with that app, a slug already taken is refused
 * and recorded, and the Store and `occ integriq:extensions` both see it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Catalog
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Catalog;

use OCA\Integriq\Command\ExtensionsList;
use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Service\Catalog\ContributedTemplates;
use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;
use OCA\Integriq\Service\Payment\PaymentProviderRegistry;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Integriq\Service\Catalog\ContributedTemplates
 * @uses   \OCA\Integriq\Service\Catalog\ConnectorTemplateLibrary
 * @uses   \OCA\Integriq\Service\Extension\ConnectorExtensionCollector
 * @uses   \OCA\Integriq\Event\RegisterConnectorExtensionsEvent
 * @uses   \OCA\Integriq\Service\CatalogRegistryService
 * @uses   \OCA\Integriq\Command\ExtensionsList
 */
final class ContributedTemplatesTest extends TestCase {

	/**
	 * The built-in fixture library and the extension's directory.
	 */
	private const BUILT_IN = __DIR__ . '/../../../fixtures/connector-templates';
	private const CONTRIBUTED = __DIR__ . '/../../../fixtures/extension-templates';

	/**
	 * A collector whose event receives the extension's template directory.
	 *
	 * @param LoggerInterface|null $logger The collector's logger.
	 *
	 * @return ConnectorExtensionCollector
	 */
	private function collector(?LoggerInterface $logger = null): ConnectorExtensionCollector {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (RegisterConnectorExtensionsEvent $event): void {
				$event->addConnectorTemplates(directory: self::CONTRIBUTED, appId: 'myextension');
			}
		);

		return new ConnectorExtensionCollector($dispatcher, ($logger ?? new NullLogger()));
	}//end collector()

	/**
	 * A free slug becomes a card provided by the app; a taken one is refused, logged and recorded.
	 *
	 * @return void
	 */
	public function testAContributedTemplateIsProvidedByItsAppAndACollisionIsRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Integriq extensions: a contributed {seam} was refused: {reason}.',
			$this->callback(static fn (array $context): bool => $context['app'] === 'myextension' && $context['seam'] === 'connector templates')
		);
		$collector = $this->collector(logger: $logger);
		$templates = new ContributedTemplates($collector, self::BUILT_IN);

		$cards = $templates->cards();
		$templates->cards();

		$this->assertSame(['example-extension-crm'], array_column($cards, 'sourceTemplateSlug'));
		$this->assertSame('myextension', $cards[0]['providedBy']);
		$this->assertSame('Example extension CRM', $cards[0]['name']);
		$this->assertSame(
			[
				['connector templates', 'example-extension-crm', 'myextension', false, ''],
				['connector templates', 'example-zaaksysteem', 'myextension', true, 'the id "example-zaaksysteem" is already registered by integriq'],
			],
			array_map(static fn (array $entry): array => [$entry['seam'], $entry['id'], $entry['appId'], $entry['refused'], $entry['reason']], $this->sorted(record: $collector->record()))
		);
	}//end testAContributedTemplateIsProvidedByItsAppAndACollisionIsRefused()

	/**
	 * The payload of an accepted contributed template is found; a refused or unknown one is not.
	 *
	 * @return void
	 */
	public function testPayloads(): void {
		$templates = new ContributedTemplates($this->collector(), self::BUILT_IN);

		$this->assertSame('Example extension CRM', $templates->payload(slug: 'example-extension-crm')['name'] ?? null);
		$this->assertNull($templates->payload(slug: 'example-zaaksysteem'));
		$this->assertNull($templates->payload(slug: 'nope'));
	}//end testPayloads()

	/**
	 * The Store lists the contributed card beside integriq's own, and instantiating it finds its payload.
	 *
	 * @return void
	 */
	public function testTheStoreShowsTheContributedCard(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$service = new CatalogRegistryService(
			new IntegrationRegistry(new NullLogger()),
			$this->createMock(ObjectService::class),
			$appConfig,
			new NullLogger(),
			self::BUILT_IN,
			new ContributedTemplates($this->collector(), self::BUILT_IN)
		);

		$bySlug = array_column($service->collect(), null, 'slug');

		$this->assertSame('myextension', $bySlug['template:example-extension-crm']['providedBy']);
		$this->assertArrayNotHasKey('providedBy', $bySlug['template:example-zaaksysteem']);
		$this->assertArrayNotHasKey('sourceType', $bySlug['template:example-extension-crm']);
		$this->assertNotSame('', $bySlug['template:example-extension-crm']['icon']);
		$this->assertSame('Example extension CRM', $service->findSeedSourcePayload(slug: 'example-extension-crm')['name'] ?? null);
	}//end testTheStoreShowsTheContributedCard()

	/**
	 * `occ integriq:extensions` lists the contributed templates, and the refusal makes it exit 1.
	 *
	 * @return void
	 */
	public function testTheCommandListsTheTemplates(): void {
		$collector = $this->collector();
		$command = new ExtensionsList(
			$collector,
			$this->createMock(IntakeChannelRegistry::class),
			$this->createMock(DigitalPostProviderRegistry::class),
			$this->createMock(PaymentProviderRegistry::class),
			new ContributedTemplates($collector, self::BUILT_IN)
		);
		$tester = new CommandTester($command);

		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('myextension  connector templates  example-extension-crm  registered', $tester->getDisplay());
		$this->assertStringContainsString('myextension  connector templates  example-zaaksysteem  REFUSED: the id "example-zaaksysteem" is already registered by integriq', $tester->getDisplay());
	}//end testTheCommandListsTheTemplates()

	/**
	 * The record ordered by id, so the order the directory is read in does not matter.
	 *
	 * @param array<int, array<string, mixed>> $record The record.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sorted(array $record): array {
		usort($record, static fn (array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));

		return $record;
	}//end sorted()
}//end class
