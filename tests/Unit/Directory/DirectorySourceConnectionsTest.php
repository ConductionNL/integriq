<?php

/**
 * Unit tests for finding the directory connections among the stored sources.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\DirectorySource;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only stored sources of type directory are connections, whatever shape the
 * register answers in; the policy lookup reads them without RBAC or
 * multitenancy, the user-facing lookup with them.
 */
#[CoversClass(DirectorySource::class)]
class DirectorySourceConnectionsTest extends TestCase {
	private const QUERY = [
		'filters' => ['register' => 'integriq', 'schema' => 'source', 'type' => 'directory'],
		'limit' => 100,
	];

	/**
	 * A stored source of this type.
	 *
	 * @param string $uuid The uuid.
	 * @param string $type The source type.
	 */
	private static function source(string $uuid, string $type): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['type' => $type]);
		return $entity;
	}//end source()

	/**
	 * A directory source over this object service.
	 *
	 * @param OrObjectService $objects The object service.
	 */
	private function directorySource(OrObjectService $objects): DirectorySource {
		return new DirectorySource(
			orObjectService: $objects,
			callService: $this->createMock(CallService::class),
			l10n: $this->createMock(IL10N::class),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end directorySource()

	/**
	 * The uuids of these connections.
	 *
	 * @param array<int, ObjectEntity> $connections The connections.
	 */
	private static function uuids(array $connections): array {
		return array_map(static fn (ObjectEntity $connection): ?string => $connection->getUuid(), $connections);
	}//end uuids()

	public function testOnlyDirectorySourcesAreConnections(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->expects($this->once())->method('findAll')->with(self::QUERY)->willReturn(
			[
				self::source(uuid: 'dir-1', type: 'directory'),
				self::source(uuid: 'api-1', type: 'api'),
				['type' => 'directory', 'uuid' => 'array-row'],
				self::source(uuid: 'dir-2', type: 'directory'),
				new ObjectEntity(),
			]
		);

		$this->assertSame(['dir-1', 'dir-2'], self::uuids($this->directorySource(objects: $objects)->findConnections()));
	}//end testOnlyDirectorySourcesAreConnections()

	public function testAPagedAnswerIsReadFromItsResults(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(
			['results' => [self::source(uuid: 'dir-1', type: 'directory'), self::source(uuid: 'db-1', type: 'database')], 'total' => 2]
		);

		$this->assertSame(['dir-1'], self::uuids($this->directorySource(objects: $objects)->findConnections()));
	}//end testAPagedAnswerIsReadFromItsResults()

	public function testThePolicyLookupReadsWithoutRbacOrMultitenancy(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->expects($this->once())->method('findAll')->with(self::QUERY, false, false)->willReturn(
			[self::source(uuid: 'dir-1', type: 'directory'), self::source(uuid: 'api-1', type: 'api')]
		);

		$this->assertSame(['dir-1'], self::uuids($this->directorySource(objects: $objects)->findConnectionsForPolicy()));
	}//end testThePolicyLookupReadsWithoutRbacOrMultitenancy()

	public function testTheUserLookupKeepsTheDefaultScoping(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->expects($this->once())->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->assertSame(self::QUERY, $config);
				$this->assertSame([true, true], [$_rbac, $_multitenancy]);
				return [];
			}
		);

		$this->assertSame([], $this->directorySource(objects: $objects)->findConnections());
	}//end testTheUserLookupKeepsTheDefaultScoping()
}//end class
