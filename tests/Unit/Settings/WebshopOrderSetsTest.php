<?php

/**
 * The web shop order sets, run through the real MappingService and checked
 * against shillinq's WebshopOrder contract.
 *
 * Each shop's mapping runs over a recorded orders page and every object it
 * produces is validated against `tests/fixtures/webshop/webshop-order.schema.json`,
 * the WebshopOrder fields shillinq `sales-webshop-orders` defines. The
 * synchronizations are checked for what makes a refund land on the same order:
 * incremental with a cursor the orders carry, keyed on the shop's order id, and
 * held back by the run guard until the channel and administration are filled.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use Adbar\Dot;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Synchronization\RunPrerequisiteGuard;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Loader\ArrayLoader;

/**
 * Covers the WooCommerce, Shopify and Lightspeed shop fragments in register.d.
 */
final class WebshopOrderSetsTest extends TestCase {

	/**
	 * The shops whose orders are mapped, with their recorded orders page.
	 */
	private const MAPPED = [
		'woocommerce-shop' => 'woocommerce/orders-paid.json',
		'shopify-shop' => 'shopify/orders.json',
		'lightspeed-shop' => 'lightspeed/orders.json',
	];

	/**
	 * Every shop template.
	 */
	private const TEMPLATES = ['woocommerce-shop', 'shopify-shop', 'lightspeed-shop'];

	/**
	 * shillinq's own fields, which integriq never writes.
	 */
	private const SHILLINQ_FIELDS = ['intakeState', 'arInvoiceId', 'creditNoteId', 'refusalReason'];

	/**
	 * One shop's fragment objects, by schema and slug.
	 *
	 * @param string $shop The shop slug.
	 *
	 * @return array<string, array<string, array<string, mixed>>> The objects.
	 */
	private function seeds(string $shop): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $shop . '-source.json';
		$fragment = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$seeds = [];
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$seeds[$self['schema']][$self['slug']] = $object;
		}

		return $seeds;
	}//end seeds()

	/**
	 * A recorded orders page, as the synchronization reads its results.
	 *
	 * @param string $shop The shop slug.
	 * @param string $file The fixture file under tests/fixtures/webshop.
	 *
	 * @return array<int, array<string, mixed>> The orders.
	 */
	private function orders(string $shop, string $file): array {
		$page = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/webshop/' . $file), true, 512, JSON_THROW_ON_ERROR);
		$position = $this->seeds(shop: $shop)['synchronization'][$shop . '-orders']['sourceConfig']['resultsPosition'];
		if ($position === '_root') {
			return $page;
		}

		$orders = (new Dot($page))->get($position);

		return array_map(fn (array $order): array => $this->withExtraData(shop: $shop, order: $order, dir: dirname($file)), $orders);
	}//end orders()

	/**
	 * An order with the extra data its synchronization fetches per order merged in.
	 *
	 * Each `extraDataConfigs` entry's static endpoint, with the order's origin id
	 * filled in as the engine does, names a recorded response under the shop's
	 * fixture directory; with `mergeExtraData` the response is merged into the
	 * order, as SynchronizationService::fetchMultipleExtraData() does.
	 *
	 * @param string               $shop  The shop slug.
	 * @param array<string, mixed> $order The order from the list call.
	 * @param string               $dir   The shop's fixture directory under tests/fixtures/webshop.
	 *
	 * @return array<string, mixed> The order as the mapping receives it.
	 */
	private function withExtraData(string $shop, array $order, string $dir): array {
		$sourceConfig = $this->seeds(shop: $shop)['synchronization'][$shop . '-orders']['sourceConfig'];
		foreach (($sourceConfig['extraDataConfigs'] ?? []) as $config) {
			$endpoint = str_replace('{{ originId }}', (string)(new Dot($order))->get($sourceConfig['idPosition']), $config['staticEndpoint']);
			$extra = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/webshop/' . $dir . $endpoint), true, 512, JSON_THROW_ON_ERROR);
			$this->assertTrue($config['mergeExtraData'], $shop . ' would replace the order with its extra data.');
			$order = array_merge($order, $extra);
		}

		return $order;
	}//end withExtraData()

	/**
	 * The shop's mapping as an administrator leaves it: channel and administration filled.
	 *
	 * @param string $shop The shop slug.
	 *
	 * @return array<string, mixed> The mapping row.
	 */
	private function filledMapping(string $shop): array {
		$mapping = $this->seeds(shop: $shop)['mapping'][$shop . '-to-webshop-order'];
		$mapping['mapping']['channelId'] = 'channel-theehandel';
		$mapping['mapping']['administrationId'] = 'administration-1';

		return $mapping;
	}//end filledMapping()

	/**
	 * Map one order with the real MappingService.
	 *
	 * @param string               $shop  The shop slug.
	 * @param array<string, mixed> $order The shop's order.
	 *
	 * @return array<string, mixed> The WebshopOrder.
	 */
	private function map(string $shop, array $order): array {
		$service = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);

		return $service->executeMapping(mapping: $this->filledMapping(shop: $shop), input: $order);
	}//end map()

	/**
	 * The contract shillinq defines.
	 *
	 * @return array<string, mixed> The JSON schema.
	 */
	private function contract(): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/webshop/webshop-order.schema.json'), true, 512, JSON_THROW_ON_ERROR);
	}//end contract()

	/**
	 * Every recorded order becomes a WebshopOrder the contract accepts, without shillinq's own fields.
	 *
	 * @return void
	 */
	public function testEveryRecordedOrderBecomesAWebshopOrderTheContractAccepts(): void {
		$checked = 0;
		foreach (self::MAPPED as $shop => $file) {
			foreach ($this->orders(shop: $shop, file: $file) as $order) {
				$mapped = $this->map(shop: $shop, order: $order);
				$this->assertSame([], RegisterSchemaValidator::errorsAgainst(schema: $this->contract(), object: $mapped), $shop . ' order ' . ($order['id'] ?? '?'));
				$this->assertSame([], array_intersect(self::SHILLINQ_FIELDS, array_keys($mapped)), $shop . ' writes a shillinq field.');
				$checked++;
			}
		}

		$this->assertSame(5, $checked);
	}//end testEveryRecordedOrderBecomesAWebshopOrderTheContractAccepts()

	/**
	 * WooCommerce order 100231 arrives as the scenario describes it, prices including VAT.
	 *
	 * @return void
	 */
	public function testThePaidWooCommerceOrderIsMappedWithPricesIncludingVat(): void {
		$mapped = $this->map(shop: 'woocommerce-shop', order: $this->orders(shop: 'woocommerce-shop', file: 'woocommerce/orders-paid.json')[0]);

		$this->assertSame('channel-theehandel', $mapped['channelId']);
		$this->assertSame('administration-1', $mapped['administrationId']);
		$this->assertSame('100231', $mapped['shopOrderId']);
		$this->assertSame('2026-09-14T09:12:04Z', $mapped['orderedAt']);
		$this->assertSame(
			['type' => 'consumer', 'name' => 'J. Bakker', 'email' => 'j.bakker@example.nl', 'companyName' => '', 'vatId' => '', 'kvkNumber' => '', 'address' => 'Oudegracht 120, 3511 AW Utrecht', 'countryCode' => 'NL'],
			$mapped['buyer']
		);
		$this->assertSame('EUR', $mapped['currency']);
		$this->assertTrue($mapped['pricesIncludeVat']);
		$this->assertSame([['sku' => 'EG-250', 'description' => 'Earl Grey los 250 g', 'quantity' => 2, 'unitPrice' => 8.95, 'vatRate' => 9]], $mapped['lines']);
		$this->assertSame(4.95, $mapped['shipping']);
		$this->assertSame(0.0, $mapped['discountTotal']);
		$this->assertSame(['net' => 20.51, 'vat' => 2.34, 'gross' => 22.85], $mapped['totals']);
		$this->assertSame(['status' => 'paid', 'method' => 'iDEAL', 'providerPaymentId' => 'tr_example0001'], $mapped['payment']);
	}//end testThePaidWooCommerceOrderIsMappedWithPricesIncludingVat()

	/**
	 * A Shopify business order with prices excluding VAT keeps its net prices and its company.
	 *
	 * @return void
	 */
	public function testAShopifyBusinessOrderKeepsNetPrices(): void {
		$mapped = $this->map(shop: 'shopify-shop', order: $this->orders(shop: 'shopify-shop', file: 'shopify/orders.json')[0]);

		$this->assertSame('1001', $mapped['shopOrderId']);
		$this->assertSame('2026-09-15T08:20:00Z', $mapped['orderedAt']);
		$this->assertSame('business', $mapped['buyer']['type']);
		$this->assertSame('Kantoor Koffie B.V.', $mapped['buyer']['companyName']);
		$this->assertSame('inkoop@kantoorkoffie.example', $mapped['buyer']['email']);
		$this->assertFalse($mapped['pricesIncludeVat']);
		$this->assertSame([['sku' => 'EG-250', 'description' => 'Earl Grey los 250 g', 'quantity' => 3, 'unitPrice' => 8.21, 'vatRate' => 9]], $mapped['lines']);
		$this->assertSame(4.09, $mapped['shipping']);
		$this->assertSame(['net' => 28.72, 'vat' => 3.08, 'gross' => 31.8], $mapped['totals']);
		$this->assertSame(['status' => 'paid', 'method' => 'ideal', 'providerPaymentId' => ''], $mapped['payment']);
	}//end testAShopifyBusinessOrderKeepsNetPrices()

	/**
	 * A cancelled Shopify order is cancelled, whatever its financial status says.
	 *
	 * @return void
	 */
	public function testACancelledShopifyOrderIsCancelled(): void {
		$mapped = $this->map(shop: 'shopify-shop', order: $this->orders(shop: 'shopify-shop', file: 'shopify/orders.json')[1]);

		$this->assertSame('cancelled', $mapped['payment']['status']);
		$this->assertTrue($mapped['pricesIncludeVat']);
		$this->assertSame(1.0, $mapped['discountTotal']);
		$this->assertSame(4.95, $mapped['shipping']);
	}//end testACancelledShopifyOrderIsCancelled()

	/**
	 * A Lightspeed consumer order arrives with the lines fetched from its products resource, prices including VAT.
	 *
	 * @return void
	 */
	public function testALightspeedOrderIsMappedWithItsFetchedLines(): void {
		$mapped = $this->map(shop: 'lightspeed-shop', order: $this->orders(shop: 'lightspeed-shop', file: 'lightspeed/orders.json')[0]);

		$this->assertSame('channel-theehandel', $mapped['channelId']);
		$this->assertSame('administration-1', $mapped['administrationId']);
		$this->assertSame('ORD00042', $mapped['shopOrderId']);
		$this->assertSame('2026-09-16T08:05:00Z', $mapped['orderedAt']);
		$this->assertSame(
			['type' => 'consumer', 'name' => 'J. Bakker', 'email' => 'j.bakker@example.nl', 'companyName' => '', 'vatId' => '', 'kvkNumber' => '', 'address' => 'Oudegracht 120, 3511 AW Utrecht', 'countryCode' => 'NL'],
			$mapped['buyer']
		);
		$this->assertSame('EUR', $mapped['currency']);
		$this->assertTrue($mapped['pricesIncludeVat']);
		$this->assertSame([['sku' => 'EG-250', 'description' => 'Earl Grey los - 250 g', 'quantity' => 2, 'unitPrice' => 8.95, 'vatRate' => 9]], $mapped['lines']);
		$this->assertSame(4.95, $mapped['shipping']);
		$this->assertSame(0.0, $mapped['discountTotal']);
		$this->assertSame(['net' => 20.51, 'vat' => 2.34, 'gross' => 22.85], $mapped['totals']);
		$this->assertSame(['status' => 'paid', 'method' => 'iDEAL', 'providerPaymentId' => ''], $mapped['payment']);
	}//end testALightspeedOrderIsMappedWithItsFetchedLines()

	/**
	 * A Lightspeed business order passes on its VAT and KvK numbers, every line, its discount, and is pending while unpaid.
	 *
	 * @return void
	 */
	public function testALightspeedBusinessOrderKeepsItsCompanyNumbersAndEveryLine(): void {
		$mapped = $this->map(shop: 'lightspeed-shop', order: $this->orders(shop: 'lightspeed-shop', file: 'lightspeed/orders.json')[1]);

		$this->assertSame('business', $mapped['buyer']['type']);
		$this->assertSame('Kantoor Koffie B.V.', $mapped['buyer']['companyName']);
		$this->assertSame('NL001234567B01', $mapped['buyer']['vatId']);
		$this->assertSame('12345678', $mapped['buyer']['kvkNumber']);
		$this->assertSame('Stationsplein 5A, 3818 LE Amersfoort', $mapped['buyer']['address']);
		$this->assertSame(
			[
				['sku' => 'EG-250', 'description' => 'Earl Grey los - 250 g', 'quantity' => 3, 'unitPrice' => 8.95, 'vatRate' => 9],
				['sku' => 'TP-1', 'description' => 'Theepot glas', 'quantity' => 1, 'unitPrice' => 17.06, 'vatRate' => 21],
			],
			$mapped['lines']
		);
		$this->assertSame(2.42, $mapped['discountTotal']);
		$this->assertSame(0.0, $mapped['shipping']);
		$this->assertSame(['net' => 36.51, 'vat' => 4.98, 'gross' => 41.49], $mapped['totals']);
		$this->assertSame(['status' => 'pending', 'method' => 'Bankoverschrijving', 'providerPaymentId' => ''], $mapped['payment']);
	}//end testALightspeedBusinessOrderKeepsItsCompanyNumbersAndEveryLine()

	/**
	 * A cancelled Lightspeed order is cancelled, whatever its payment status says.
	 *
	 * @return void
	 */
	public function testACancelledLightspeedOrderIsCancelled(): void {
		$order = $this->orders(shop: 'lightspeed-shop', file: 'lightspeed/orders.json')[0];
		$order['status'] = 'cancelled';

		$this->assertSame('cancelled', $this->map(shop: 'lightspeed-shop', order: $order)['payment']['status']);
	}//end testACancelledLightspeedOrderIsCancelled()

	/**
	 * The Lightspeed refund fetch of order 9001 keeps its origin id and order number and turns the payment refunded.
	 *
	 * @return void
	 */
	public function testALightspeedRefundFetchOfTheSameOrderKeepsItsIdentityAndIsRefunded(): void {
		$sync = $this->seeds(shop: 'lightspeed-shop')['synchronization']['lightspeed-shop-orders'];
		$paid = $this->orders(shop: 'lightspeed-shop', file: 'lightspeed/orders.json')[0];
		$refunded = $this->orders(shop: 'lightspeed-shop', file: 'lightspeed/orders-refunded.json')[0];

		$idPosition = $sync['sourceConfig']['idPosition'];
		$this->assertSame((new Dot($paid))->get($idPosition), (new Dot($refunded))->get($idPosition), 'The second fetch would make a second contract.');

		$first = $this->map(shop: 'lightspeed-shop', order: $paid);
		$second = $this->map(shop: 'lightspeed-shop', order: $refunded);
		$this->assertSame($first['shopOrderId'], $second['shopOrderId']);
		$this->assertSame('paid', $first['payment']['status']);
		$this->assertSame('refunded', $second['payment']['status']);

		$cursor = $sync['sourceConfig']['cursorField'];
		$this->assertGreaterThan(strtotime((new Dot($paid))->get($cursor)), strtotime((new Dot($refunded))->get($cursor)), 'The refund must move the order past the cursor.');
	}//end testALightspeedRefundFetchOfTheSameOrderKeepsItsIdentityAndIsRefunded()

	/**
	 * The Lightspeed lines are fetched per order from the products resource the order links to, without the list query.
	 *
	 * @return void
	 */
	public function testTheLightspeedLinesAreFetchedFromTheResourceTheOrderLinksTo(): void {
		$sourceConfig = $this->seeds(shop: 'lightspeed-shop')['synchronization']['lightspeed-shop-orders']['sourceConfig'];
		$this->assertCount(1, $sourceConfig['extraDataConfigs']);
		$config = $sourceConfig['extraDataConfigs'][0];

		// The changed-since query of the list call must not ride along on the products call.
		$this->assertSame('query', $config['unsetConfigKey']);
		$this->assertTrue($config['mergeExtraData']);
		$this->assertArrayNotHasKey('keyToSetExtraData', $config, 'The products response already names its lines orderProducts.');

		$page = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/webshop/lightspeed/orders.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($page['orders'] as $order) {
			$endpoint = str_replace('{{ originId }}', (string)$order[$sourceConfig['idPosition']], $config['staticEndpoint']);
			$this->assertSame($order['products']['resource']['url'] . '.json', ltrim($endpoint, '/'), 'order ' . $order['id']);
		}
	}//end testTheLightspeedLinesAreFetchedFromTheResourceTheOrderLinksTo()

	/**
	 * The refund fetch of order 100231 keeps its origin id and order number and turns the payment refunded.
	 *
	 * @return void
	 */
	public function testARefundFetchOfTheSameOrderKeepsItsIdentityAndIsRefunded(): void {
		$sync = $this->seeds(shop: 'woocommerce-shop')['synchronization']['woocommerce-shop-orders'];
		$paid = $this->orders(shop: 'woocommerce-shop', file: 'woocommerce/orders-paid.json')[0];
		$refunded = $this->orders(shop: 'woocommerce-shop', file: 'woocommerce/orders-refunded.json')[0];

		$idPosition = $sync['sourceConfig']['idPosition'];
		$this->assertSame((new Dot($paid))->get($idPosition), (new Dot($refunded))->get($idPosition), 'The second fetch would make a second contract.');

		$first = $this->map(shop: 'woocommerce-shop', order: $paid);
		$second = $this->map(shop: 'woocommerce-shop', order: $refunded);
		$this->assertSame($first['shopOrderId'], $second['shopOrderId']);
		$this->assertSame('paid', $first['payment']['status']);
		$this->assertSame('refunded', $second['payment']['status']);

		$cursor = $sync['sourceConfig']['cursorField'];
		$this->assertGreaterThan((new Dot($paid))->get($cursor), (new Dot($refunded))->get($cursor), 'The refund must move the order past the cursor.');
	}//end testARefundFetchOfTheSameOrderKeepsItsIdentityAndIsRefunded()

	/**
	 * Each order synchronization is incremental, asks for changes since its cursor, deletes nothing and writes WebshopOrder.
	 *
	 * @return void
	 */
	public function testEachOrderSynchronizationIsIncrementalAndWritesWebshopOrder(): void {
		foreach (array_keys(self::MAPPED) as $shop) {
			$sync = $this->seeds(shop: $shop)['synchronization'][$shop . '-orders'];

			$this->assertSame('incremental', $sync['syncMode'], $shop);
			$this->assertContains('{{ cursor }}', $sync['sourceConfig']['query'], $shop . ' asks for every order on every run.');
			$this->assertNotEmpty($sync['sourceConfig']['cursorField'], $shop);
			$this->assertArrayNotHasKey('disappearancePolicy', $sync['sourceConfig'], $shop);
			$this->assertSame('shillinq/WebshopOrder', $sync['targetId'], $shop);
			$this->assertSame('register/schema', $sync['targetType'], $shop);
			$this->assertSame($shop, $sync['sourceId'], $shop);
			$this->assertSame($shop . '-to-webshop-order', $sync['sourceTargetMapping'], $shop);
			$this->assertFalse($sync['isEnabled'], $shop);

			foreach ($this->orders(shop: $shop, file: self::MAPPED[$shop]) as $order) {
				$this->assertNotNull((new Dot($order))->get($sync['sourceConfig']['cursorField']), $shop . ' orders carry no cursor value.');
			}
		}
	}//end testEachOrderSynchronizationIsIncrementalAndWritesWebshopOrder()

	/**
	 * Until the channel and administration are filled, the run guard stops the synchronization and names what is missing.
	 *
	 * @return void
	 */
	public function testTheRunGuardWaitsForTheChannelAndAdministration(): void {
		foreach (array_keys(self::MAPPED) as $shop) {
			$seeds = $this->seeds(shop: $shop);
			$sourceConfig = $seeds['synchronization'][$shop . '-orders']['sourceConfig'];

			$missing = $this->guardOver(mapping: $seeds['mapping'][$shop . '-to-webshop-order'], shop: $shop)->missing(sourceConfig: $sourceConfig);
			$this->assertStringContainsString('WebshopChannel id', (string)$missing, $shop);
			$this->assertStringContainsString($shop . '-to-webshop-order', (string)$missing, $shop);

			$this->assertNull($this->guardOver(mapping: $this->filledMapping(shop: $shop), shop: $shop)->missing(sourceConfig: $sourceConfig), $shop);
		}
	}//end testTheRunGuardWaitsForTheChannelAndAdministration()

	/**
	 * The real run guard over one mapping row.
	 *
	 * @param array<string, mixed> $mapping The mapping row.
	 * @param string               $shop    The shop slug.
	 *
	 * @return RunPrerequisiteGuard The guard.
	 */
	private function guardOver(array $mapping, string $shop): RunPrerequisiteGuard {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) use ($mapping, $shop) {
				if ($schema !== 'mapping' || $id !== $shop . '-to-webshop-order') {
					throw new \RuntimeException('Object not found');
				}

				return ObjectServiceMockBuilder::objectEntity($this, $mapping, 'uuid-' . $id);
			}
		);

		return new RunPrerequisiteGuard(objectService: $objects);
	}//end guardOver()

	/**
	 * Every shop template is dormant, brokers its secret and is listed under Commerce.
	 *
	 * @return void
	 */
	public function testEveryShopTemplateIsDormantBrokeredAndListedUnderCommerce(): void {
		$entries = [];
		$catalogue = new CatalogRegistryService(new IntegrationRegistry(new NullLogger()), ObjectServiceMockBuilder::make($this), $this->createMock(IAppConfig::class), new NullLogger());
		foreach ($catalogue->collect() as $entry) {
			$entries[($entry['sourceTemplateSlug'] ?? '')] = $entry;
		}

		foreach (self::TEMPLATES as $shop) {
			$source = $this->seeds(shop: $shop)['source'][$shop];

			$this->assertFalse($source['isEnabled'], $shop);
			$this->assertSame('', $source['location'], $shop . ' ships an address.');
			$this->assertStringContainsString('"credentialRef"', (string)json_encode($source['configuration']['authentication']), $shop . ' keeps its secret outside the broker.');
			$this->assertArrayHasKey($shop, $entries, $shop . ' is not in the catalogue.');
			$this->assertSame('Commerce', $entries[$shop]['category'], $shop);
		}
	}//end testEveryShopTemplateIsDormantBrokeredAndListedUnderCommerce()
}//end class
