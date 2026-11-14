<?php

/**
 * Unit tests for the edges of OptOutTableMigrator: a lookup that returns no
 * list, rows that are not objects, an unknown scope and an unreadable
 * creation time.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Db\OptOutMapperInterface;
use OCA\Integriq\Outbound\Identity\OptOutTableMigrator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for how the migrator copes with odd legacy rows.
 *
 * @covers \OCA\Integriq\Outbound\Identity\OptOutTableMigrator
 * @uses   \OCA\Integriq\Db\OptOut
 */
class OptOutTableMigratorEdgeTest extends TestCase {

	/**
	 * Object service mock.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService&MockObject $objectService;

	/**
	 * Opt-out table mock.
	 *
	 * @var OptOutMapperInterface&MockObject
	 */
	private OptOutMapperInterface&MockObject $mapper;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ORObjectService::class);
		$this->mapper = $this->createMock(OptOutMapperInterface::class);

	}//end setUp()

	/**
	 * A lookup whose results are not a list copies nothing.
	 *
	 * @return void
	 */
	public function testAResultThatIsNotAListCopiesNothing(): void {
		$this->objectService->expects($this->once())->method('findAll')->willReturn(['results' => 'unavailable']);
		$this->mapper->expects($this->never())->method('insertIfAbsent');

		$migrator = new OptOutTableMigrator($this->objectService, $this->mapper);

		$this->assertSame(['read' => 0, 'copied' => 0, 'present' => 0, 'skipped' => 0], $migrator->migrate());

	}//end testAResultThatIsNotAListCopiesNothing()

	/**
	 * Rows that are not objects are dropped; an unknown scope becomes instance-wide and a bad date becomes now.
	 *
	 * @return void
	 */
	public function testAnOddRowIsCopiedInstanceWideWithTheCurrentTime(): void {
		$row = new ObjectEntity();
		$row->setUuid('uuid-odd');
		$row->setObject(['address' => ' Odd@Example.org ', 'scope' => 'everything', 'caseRef' => 'zaak/9', 'createdAt' => 'not a date']);
		$this->objectService->method('findAll')->willReturn(['results' => ['not an entity', $row, null]]);

		$copied = [];
		$this->mapper->expects($this->once())->method('insertIfAbsent')->willReturnCallback(
			static function (OptOut $optOut) use (&$copied): array {
				$copied[] = $optOut;
				return ['created' => false, 'row' => $optOut];
			}
		);

		$before = time();
		$result = (new OptOutTableMigrator($this->objectService, $this->mapper))->migrate();
		$after = time();

		$this->assertSame(['read' => 1, 'copied' => 0, 'present' => 1, 'skipped' => 0], $result);
		$this->assertCount(1, $copied);
		$this->assertSame('odd@example.org', $copied[0]->getAddress());
		$this->assertSame('instance', $copied[0]->getScope());
		$this->assertSame('', $copied[0]->getCaseRef());
		$this->assertSame('openregister', $copied[0]->getSource());
		$this->assertSame('uuid-odd', $copied[0]->getLegacyUuid());
		$this->assertGreaterThanOrEqual($before, $copied[0]->getCreatedAt());
		$this->assertLessThanOrEqual($after, $copied[0]->getCreatedAt());

	}//end testAnOddRowIsCopiedInstanceWideWithTheCurrentTime()

}//end class
