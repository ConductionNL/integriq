<?php

/**
 * Unit tests for IntegrationObjectLookup.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/platform-integrations-as-code/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command\Integration;

use OCA\Integriq\Command\Integration\IntegrationObjectLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An object is found by its uuid or by its one slug, never by a guess.
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
 */
class IntegrationObjectLookupTest extends TestCase {

	/**
	 * @var OrObjectService&MockObject
	 */
	private $objects;

	/**
	 * Set up the object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(OrObjectService::class);

	}//end setUp()

	/**
	 * The slug query for synchronization `woo`.
	 *
	 * @return array The findAll() config.
	 */
	private static function slugQuery(): array {
		return ['filters' => ['register' => 'integriq', 'schema' => 'synchronization', 'slug' => 'woo'], 'limit' => 2];

	}//end slugQuery()

	/**
	 * A uuid is read directly, without RBAC or tenancy, and no slug query runs.
	 *
	 * @return void
	 */
	public function testAUuidIsFoundDirectly(): void {
		$found = new ObjectEntity();
		$this->objects->expects($this->once())->method('find')
			->with('6f1c', 'integriq', 'synchronization', false, false)->willReturn($found);
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame($found, (new IntegrationObjectLookup($this->objects))->resolve('synchronization', ' 6f1c '));

	}//end testAUuidIsFoundDirectly()

	/**
	 * A slug is found when exactly one object carries it.
	 *
	 * @return void
	 */
	public function testAUniqueSlugIsFound(): void {
		$found = new ObjectEntity();
		$this->objects->method('find')->willThrowException(new DoesNotExistException('no'));
		$this->objects->expects($this->once())->method('findAll')
			->with(self::slugQuery(), false, false)->willReturn(['results' => [$found]]);

		$this->assertSame($found, (new IntegrationObjectLookup($this->objects))->resolve('synchronization', 'woo'));

	}//end testAUniqueSlugIsFound()

	/**
	 * A slug two objects share, or none, resolves to nothing.
	 *
	 * @return void
	 */
	public function testAnAmbiguousOrMissingSlugIsNotFound(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->method('findAll')->willReturnOnConsecutiveCalls(
			['results' => [new ObjectEntity(), new ObjectEntity()]],
			['results' => []]
		);
		$lookup = new IntegrationObjectLookup($this->objects);

		$this->assertNull($lookup->resolve('synchronization', 'woo'));
		$this->assertNull($lookup->resolve('synchronization', 'woo'));

	}//end testAnAmbiguousOrMissingSlugIsNotFound()

	/**
	 * An empty reference reads nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceReadsNothing(): void {
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('findAll');

		$this->assertNull((new IntegrationObjectLookup($this->objects))->resolve('job', '  '));

	}//end testAnEmptyReferenceReadsNothing()

	/**
	 * A list reads its schema up to the limit and keeps only objects.
	 *
	 * @return void
	 */
	public function testAListReadsItsSchema(): void {
		$one = new ObjectEntity();
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'integriq', 'schema' => 'source'], 'limit' => IntegrationObjectLookup::LIST_LIMIT],
			false,
			false
		)->willReturn(['results' => [$one, ['not' => 'an object']]]);

		$this->assertSame([$one], (new IntegrationObjectLookup($this->objects))->list('source'));

	}//end testAListReadsItsSchema()

}//end class
