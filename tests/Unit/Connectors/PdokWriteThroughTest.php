<?php

/**
 * Integriq PDOK write-through test
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Connectors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Connectors;

use OCA\Integriq\Connectors\PdokConnector;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The write-through cache stores each PDOK record once, keyed by pdokId.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PdokWriteThroughTest extends TestCase {
	/**
	 * @var ObjectService|MockObject
	 */
	private $objectService;

	/**
	 * @var LoggerInterface|MockObject
	 */
	private $logger;

	/**
	 * Saved objects by uuid, as the register would hold them.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Every saveObject() call: the uuid it named and its access flags.
	 *
	 * @var list<array{uuid: ?string, register: ?string, schema: ?string, rbac: bool, multitenancy: bool}>
	 */
	private array $saves = [];

	/**
	 * Every findAll() config the connector sent.
	 *
	 * @var list<array>
	 */
	private array $finds = [];

	/**
	 * Wire an in-memory register behind the ObjectService double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stored = [];
		$this->saves = [];
		$this->finds = [];
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->finds[] = $config;
				$pdokId = ($config['filters']['pdokId'] ?? null);
				$hits = array_values(
					array_filter(
						$this->stored,
						fn (ObjectEntity $row): bool => ($row->getObject()['pdokId'] ?? null) === $pdokId
					)
				);
				return array_slice($hits, 0, (int)($config['limit'] ?? count($hits)));
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, ...$rest): ObjectEntity {
				$this->saves[] = ['uuid' => $uuid, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$entity = ($this->stored[$uuid] ?? new ObjectEntity());
				if ($uuid === null) {
					$entity->setUuid('uuid-' . (count($this->stored) + 1));
				}

				$entity->setObject($object);
				$this->stored[$entity->getUuid()] = $entity;
				return $entity;
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * A first lookup of a pdokId creates one object in integriq/addresses.
	 *
	 * @return void
	 */
	public function testFirstLookupCreatesTheObject(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->makeConnector()->writeThrough(doc: ['pdokId' => 'adr-1', 'straat' => 'Lauriergracht']);

		$this->assertCount(1, $this->stored);
		$this->assertSame(
			[['uuid' => null, 'register' => 'integriq', 'schema' => 'addresses', 'rbac' => true, 'multitenancy' => true]],
			$this->saves
		);
		$this->assertSame(
			['limit' => 1, 'filters' => ['register' => 'integriq', 'schema' => 'addresses', 'pdokId' => 'adr-1']],
			$this->finds[0]
		);

	}//end testFirstLookupCreatesTheObject()

	/**
	 * A second lookup of the same pdokId refreshes the object under its uuid.
	 *
	 * @return void
	 */
	public function testSecondLookupUpdatesTheSameUuid(): void {
		$connector = $this->makeConnector();
		$connector->writeThrough(doc: ['pdokId' => 'adr-1', 'fetchedAt' => '2001-01-01T00:00:00Z']);
		$connector->writeThrough(doc: ['pdokId' => 'adr-1', 'fetchedAt' => '2026-11-10T12:00:00Z']);

		$this->assertCount(1, $this->stored);
		$this->assertSame('uuid-1', $this->saves[1]['uuid']);
		$this->assertSame('2026-11-10T12:00:00Z', $this->stored['uuid-1']->getObject()['fetchedAt']);

	}//end testSecondLookupUpdatesTheSameUuid()

	/**
	 * Different pdokIds stay separate objects.
	 *
	 * @return void
	 */
	public function testDifferentPdokIdsAreStoredSeparately(): void {
		$connector = $this->makeConnector();
		$connector->writeThrough(doc: ['pdokId' => 'adr-1']);
		$connector->writeThrough(doc: ['pdokId' => 'adr-2']);

		$this->assertCount(2, $this->stored);
		$this->assertNull($this->saves[1]['uuid']);

	}//end testDifferentPdokIdsAreStoredSeparately()

	/**
	 * The former call passed `uniqueOn:`, which saveObject() never declared,
	 * so every write threw before it reached the register.
	 *
	 * @return void
	 */
	public function testTheFormerUniqueOnCallIsRejected(): void {
		$this->expectException(\Error::class);
		$this->expectExceptionMessage('Unknown named parameter $uniqueOn');

		$this->objectService->saveObject(
			register: 'integriq',
			schema: 'addresses',
			object: ['pdokId' => 'adr-1'],
			uniqueOn: ['pdokId'],
		);

	}//end testTheFormerUniqueOnCallIsRejected()

	/**
	 * A register failure is logged and does not escape the lookup.
	 *
	 * @return void
	 */
	public function testARegisterFailureIsLoggedNotThrown(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willThrowException(new \RuntimeException('register refused'));
		$objectService->expects($this->never())->method('saveObject');
		$this->objectService = $objectService;
		$this->logger->expects($this->once())->method('warning')->with('PdokConnector: OR write-through failed');

		$this->makeConnector()->writeThrough(doc: ['pdokId' => 'adr-1']);

	}//end testARegisterFailureIsLoggedNotThrown()

	/**
	 * Build the connector over the ObjectService double.
	 *
	 * @return PdokConnector
	 */
	private function makeConnector(): PdokConnector {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		return new PdokConnector(
			$this->createMock(IClientService::class),
			$cacheFactory,
			$this->logger,
			$container
		);

	}//end makeConnector()
}//end class
