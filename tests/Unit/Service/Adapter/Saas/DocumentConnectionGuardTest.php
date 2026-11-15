<?php

/**
 * Whether a sibling app has a linked connection to search and fetch documents through.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connectors-graph-document-search/design.md#d3-typed-commands
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\Saas;

use OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuard;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuard
 */
class DocumentConnectionGuardTest extends TestCase {

	/**
	 * A connection store answering the given rows, with only source s-1 existing.
	 *
	 * @param array<int,array<string,mixed>> $rows The rows of the asking app.
	 *
	 * @return ConnectionStore&MockObject
	 */
	private function store(array $rows): ConnectionStore {
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findRows')->willReturn($rows);
		$store->method('findSource')->willReturnCallback(
			fn (string $uuid) => ($uuid === 's-1') ? ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'any'], 's-1') : null
		);
		return $store;
	}//end store()

	/**
	 * A row declared by the app under a key, pointing at a source.
	 *
	 * @param string $key    The connection key.
	 * @param string $source The source uuid, '' for none.
	 *
	 * @return array<string,mixed>
	 */
	private function row(string $key, string $source): array {
		return ['uuid' => 'r-' . $key, 'data' => ['app' => 'dossiq', 'key' => $key, 'source' => $source]];
	}//end row()

	public function testAnAppWithoutAnIdIsRefusedWithoutReadingTheStore(): void {
		$store = $this->createMock(ConnectionStore::class);
		$store->expects($this->never())->method('findRows');

		self::assertFalse((new DocumentConnectionGuard($store))->isLinked('', 'documents'));
	}//end testAnAppWithoutAnIdIsRefusedWithoutReadingTheStore()

	public function testTheRowsAreReadForTheAskingApp(): void {
		$store = $this->createMock(ConnectionStore::class);
		$store->expects($this->once())->method('findRows')->with('dossiq')->willReturn([]);

		self::assertFalse((new DocumentConnectionGuard($store))->isLinked('dossiq', 'documents'));
	}//end testTheRowsAreReadForTheAskingApp()

	public function testAKeyedRowLinkedToAnExistingSourceIsLinked(): void {
		$guard = new DocumentConnectionGuard($this->store([$this->row('mail', 's-x'), $this->row('documents', 's-1')]));

		self::assertTrue($guard->isLinked('dossiq', 'documents'));
		self::assertFalse($guard->isLinked('dossiq', 'mail'), 'a source that no longer exists is no link');
		self::assertFalse($guard->isLinked('dossiq', 'calendar'), 'an undeclared key is no link');
	}//end testAKeyedRowLinkedToAnExistingSourceIsLinked()

	public function testAnEmptyKeyNamesTheOnlyConnection(): void {
		self::assertTrue((new DocumentConnectionGuard($this->store([$this->row('documents', 's-1')])))->isLinked('dossiq', ''));
		self::assertFalse(
			(new DocumentConnectionGuard($this->store([$this->row('a', 's-1'), $this->row('b', 's-1')])))->isLinked('dossiq', ''),
			'an empty key is ambiguous with two connections'
		);
	}//end testAnEmptyKeyNamesTheOnlyConnection()

	public function testARowWithoutASourceIsNotLookedUp(): void {
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findRows')->willReturn([$this->row('documents', '')]);
		$store->expects($this->never())->method('findSource');

		self::assertFalse((new DocumentConnectionGuard($store))->isLinked('dossiq', 'documents'));
	}//end testARowWithoutASourceIsNotLookedUp()

	public function testAFailingSourceLookupIsARefusal(): void {
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findRows')->willReturn([$this->row('documents', 's-1')]);
		$store->method('findSource')->willThrowException(new RuntimeException('register down'));

		self::assertFalse((new DocumentConnectionGuard($store))->isLinked('dossiq', 'documents'));
	}//end testAFailingSourceLookupIsARefusal()
}//end class
