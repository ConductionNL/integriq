<?php

/**
 * The API inventory's row per integriq API product: written on save, no
 * longer seen once retired or deleted, written over rather than added twice,
 * and backfilled for every product by the repair step.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ExternalApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ExternalApi;

use OCA\Integriq\EventListener\ApiProductInventoryListener;
use OCA\Integriq\Service\ExternalApi\ApiProductInventory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
 */
#[CoversClass(ApiProductInventory::class)]
#[CoversClass(ApiProductInventoryListener::class)]
class ApiProductInventoryTest extends TestCase {

	/**
	 * The inventory rows the fake object service holds, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every save, as [object, uuid option].
	 *
	 * @var list<array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $saves = [];

	/**
	 * An API product object.
	 *
	 * @param string               $uuid The product's uuid.
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntity
	 */
	private static function product(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(array_merge(['name' => 'Orders', 'productSlug' => 'orders', 'version' => '2.0.0'], $data));
		$entity->setRegister('1');
		$entity->setSchema('7');

		return $entity;
	}

	/**
	 * The service over a fake object service and URL generator.
	 *
	 * @return ApiProductInventory
	 */
	private function inventory(): ApiProductInventory {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$found = [];
				foreach ($this->rows as $uuid => $row) {
					if (($row['gatewayApiId'] ?? null) === ($filters['gatewayApiId'] ?? null)) {
						$entity = new ObjectEntity();
						$entity->setUuid($uuid);
						$entity->setObject($row);
						$found[] = $entity;
					}
				}

				return ['results' => $found];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$this->saves[] = [$object, $options->uuid];
				$uuid = ($options->uuid ?? 'row-'.count($this->rows));
				$this->rows[$uuid] = $object;

				return new ObjectEntity();
			}
		);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://nc.example'.$path);

		return new ApiProductInventory(objectService: $objects, urlGenerator: $urls);
	}

	/**
	 * A saved product is listed on the gateway integriq, with its description address.
	 *
	 * @return void
	 */
	public function testASavedProductIsListedOnTheIntegriqGateway(): void {
		$this->inventory()->mirror(product: self::product(uuid: 'p-1', data: []));

		$this->assertCount(expectedCount: 1, haystack: $this->rows);
		$row = array_values($this->rows)[0];
		$this->assertSame(expected: 'integriq', actual: $row['gateway']);
		$this->assertSame(expected: 'integriq', actual: $row['vendor']);
		$this->assertSame(expected: 'Orders', actual: $row['name']);
		$this->assertSame(expected: '2.0.0', actual: $row['version']);
		$this->assertSame(expected: 'p-1', actual: $row['gatewayApiId']);
		$this->assertSame(expected: 'https://nc.example/index.php/apps/integriq/api/endpoint', actual: $row['baseUrl']);
		$this->assertSame(expected: 'https://nc.example/index.php/apps/integriq/api/products/p-1/openapi.json', actual: $row['gatewayUrl']);
		$this->assertNull(actual: $row['sourceEndedAt']);
		$this->assertNotSame(expected: '', actual: $row['lastSeen']);
	}

	/**
	 * A second save writes over the product's row, and a rename shows.
	 *
	 * @return void
	 */
	public function testASecondSaveWritesOverTheSameRow(): void {
		$inventory = $this->inventory();
		$inventory->mirror(product: self::product(uuid: 'p-1', data: []));
		$inventory->mirror(product: self::product(uuid: 'p-1', data: ['name' => 'Orders API']));

		$this->assertCount(expectedCount: 1, haystack: $this->rows);
		$this->assertSame(expected: 'Orders API', actual: array_values($this->rows)[0]['name']);
		$this->assertSame(expected: 'row-0', actual: $this->saves[1][1]);
	}

	/**
	 * A retired product stays listed as no longer seen, and keeps the moment it ended.
	 *
	 * @return void
	 */
	public function testARetiredProductIsNoLongerSeenAndKeepsWhenItEnded(): void {
		$inventory = $this->inventory();
		$inventory->mirror(product: self::product(uuid: 'p-1', data: []));
		$inventory->mirror(product: self::product(uuid: 'p-1', data: ['status' => 'retired']));
		$ended = $this->rows['row-0']['sourceEndedAt'];
		$this->assertNotNull(actual: $ended);

		$this->rows['row-0']['sourceEndedAt'] = '2026-01-01T00:00:00+00:00';
		$inventory->mirror(product: self::product(uuid: 'p-1', data: ['status' => 'retired']));
		$this->assertSame(expected: '2026-01-01T00:00:00+00:00', actual: $this->rows['row-0']['sourceEndedAt']);

		$inventory->mirror(product: self::product(uuid: 'p-1', data: ['status' => 'active']));
		$this->assertNull(actual: $this->rows['row-0']['sourceEndedAt']);
	}

	/**
	 * A deleted product's row is marked no longer seen; a product without a row writes none.
	 *
	 * @return void
	 */
	public function testADeletedProductIsMarkedNoLongerSeen(): void {
		$inventory = $this->inventory();
		$inventory->retire(product: self::product(uuid: 'p-9', data: []));
		$this->assertSame(expected: [], actual: $this->saves);

		$inventory->mirror(product: self::product(uuid: 'p-1', data: []));
		$inventory->retire(product: self::product(uuid: 'p-1', data: []));
		$this->assertNotNull(actual: $this->rows['row-0']['sourceEndedAt']);
	}

	/**
	 * Something with a slug, as a register or schema the mappers answer.
	 *
	 * @param string $slug The slug.
	 *
	 * @return object
	 */
	private static function slugged(string $slug): object {
		return new class ($slug) {
			/**
			 * Constructor.
			 *
			 * @param string $slug The slug.
			 */
			public function __construct(private readonly string $slug) {
			}

			/**
			 * The slug.
			 *
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}

	/**
	 * The listener for objects in the integriq register with one schema slug.
	 *
	 * @param string               $schemaSlug The schema slug every object resolves to.
	 * @param LoggerInterface      $logger     The logger.
	 * @param ApiProductInventory|null $inventory The inventory, or the one over the fakes.
	 *
	 * @return ApiProductInventoryListener
	 */
	private function listener(string $schemaSlug, LoggerInterface $logger, ?ApiProductInventory $inventory = null): ApiProductInventoryListener {
		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->method('find')->willReturn(self::slugged(slug: 'integriq'));
		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturn(self::slugged(slug: $schemaSlug));

		return new ApiProductInventoryListener(
			inventory: ($inventory ?? $this->inventory()),
			registerMapper: $registers,
			schemaMapper: $schemas,
			logger: $logger
		);
	}

	/**
	 * Created, updated and deleted products reach the inventory; other objects and events do not.
	 *
	 * @return void
	 */
	public function testTheListenerMirrorsProductEventsOnly(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$listener = $this->listener(schemaSlug: 'api_product', logger: $logger);

		$listener->handle(event: new ObjectCreatedEvent(self::product(uuid: 'p-1', data: [])));
		$listener->handle(event: new ObjectUpdatedEvent(self::product(uuid: 'p-1', data: ['name' => 'Orders API']), self::product(uuid: 'p-1', data: [])));
		$this->assertSame(expected: 'Orders API', actual: $this->rows['row-0']['name']);
		$this->assertNull(actual: $this->rows['row-0']['sourceEndedAt']);

		$listener->handle(event: new ObjectDeletedEvent(self::product(uuid: 'p-1', data: [])));
		$this->assertNotNull(actual: $this->rows['row-0']['sourceEndedAt']);

		$listener->handle(event: new Event());
		$this->assertCount(expectedCount: 3, haystack: $this->saves);

		$other = $this->listener(schemaSlug: 'endpoint', logger: $logger);
		$other->handle(event: new ObjectCreatedEvent(self::product(uuid: 'p-2', data: [])));
		$this->assertCount(expectedCount: 3, haystack: $this->saves);
	}

	/**
	 * A register that cannot be resolved and a failing write are logged, never thrown.
	 *
	 * @return void
	 */
	public function testTheListenerNeverBlocksTheProductSave(): void {
		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->method('find')->willThrowException(new RuntimeException('gone'));
		$quiet = new ApiProductInventoryListener(
			inventory: $this->inventory(),
			registerMapper: $registers,
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$quiet->handle(event: new ObjectCreatedEvent(self::product(uuid: 'p-1', data: [])));
		$this->assertSame(expected: [], actual: $this->saves);

		$failing = $this->createMock(originalClassName: ApiProductInventory::class);
		$failing->method('mirror')->willThrowException(new RuntimeException('write refused'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$listener = $this->listener(schemaSlug: 'api_product', logger: $logger, inventory: $failing);

		$listener->handle(event: new ObjectCreatedEvent(self::product(uuid: 'p-1', data: [])));
	}

}
