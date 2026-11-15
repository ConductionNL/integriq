<?php

/**
 * Unit tests for the smaller public surface of OptOutRegistry: the paged
 * decision log, the protected-category check and an erasure without a
 * contact ref.
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

use InvalidArgumentException;
use OCA\Integriq\Db\OptOutLogEntry;
use OCA\Integriq\Db\OptOutLogMapperInterface;
use OCA\Integriq\Db\OptOutMapperInterface;
use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\OptOutRowBuilder;
use OCA\Integriq\Outbound\Identity\RecipientKey;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the log page, the protected check and an incomplete erasure.
 *
 * @covers \OCA\Integriq\Outbound\Identity\OptOutRegistry
 * @uses   \OCA\Integriq\Outbound\Identity\OptOutMatcher
 * @uses   \OCA\Integriq\Db\OptOutLogEntry
 */
class OptOutRegistryEdgeTest extends TestCase {

	/**
	 * Opt-out table mock.
	 *
	 * @var OptOutMapperInterface&MockObject
	 */
	private OptOutMapperInterface&MockObject $mapper;

	/**
	 * Decision log mock.
	 *
	 * @var OptOutLogMapperInterface&MockObject
	 */
	private OptOutLogMapperInterface&MockObject $log;

	/**
	 * Categories mock.
	 *
	 * @var OptOutCategories&MockObject
	 */
	private OptOutCategories&MockObject $categories;

	/**
	 * The registry under test.
	 *
	 * @var OptOutRegistry
	 */
	private OptOutRegistry $registry;

	/**
	 * Build the registry over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(OptOutMapperInterface::class);
		$this->log = $this->createMock(OptOutLogMapperInterface::class);
		$this->categories = $this->createMock(OptOutCategories::class);
		$this->registry = new OptOutRegistry(
			$this->mapper,
			$this->createMock(IAppConfig::class),
			$this->createMock(ITimeFactory::class),
			$this->categories,
			$this->createMock(RecipientKey::class),
			$this->log,
			$this->createMock(UnsubscribeTokenService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(OptOutRowBuilder::class)
		);

	}//end setUp()

	/**
	 * The log page passes the window and correlation id through and serialises each entry.
	 *
	 * @return void
	 */
	public function testTheLogPageSerialisesTheRequestedWindow(): void {
		$entry = new OptOutLogEntry();
		$entry->setAt(1790000000);
		$entry->setKind(OptOutLogEntry::KIND_SUPPRESSED);
		$entry->setAddress('h:abc');
		$entry->setCategory('service');
		$entry->setChannel('email');
		$entry->setSourceApp('integriq');
		$entry->setCorrelationId('c-9');
		$entry->setDetail('{"scope":"instance"}');
		$this->log->expects($this->once())->method('findPage')->with(20, 40, 'c-9')->willReturn([$entry]);

		$page = $this->registry->logPage(20, 40, 'c-9');

		$this->assertSame(['results'], array_keys($page));
		$this->assertCount(1, $page['results']);
		$this->assertSame(gmdate('c', 1790000000), $page['results'][0]['at']);
		$this->assertSame(OptOutLogEntry::KIND_SUPPRESSED, $page['results'][0]['kind']);
		$this->assertSame('h:abc', $page['results'][0]['address']);
		$this->assertSame('c-9', $page['results'][0]['correlationId']);
		$this->assertSame(['scope' => 'instance'], $page['results'][0]['detail']);

	}//end testTheLogPageSerialisesTheRequestedWindow()

	/**
	 * The log page defaults to the first fifty entries of every correlation.
	 *
	 * @return void
	 */
	public function testTheLogPageDefaultsToTheFirstFifty(): void {
		$this->log->expects($this->once())->method('findPage')->with(50, 0, '')->willReturn([]);

		$this->assertSame(['results' => []], $this->registry->logPage());

	}//end testTheLogPageDefaultsToTheFirstFifty()

	/**
	 * Whether a category is protected is the categories' exemption answer.
	 *
	 * @return void
	 */
	public function testIsProtectedAsksTheCategories(): void {
		$this->categories->expects($this->exactly(2))->method('isExempt')->willReturnMap(
			[
				['besluit', true],
				['newsletter', false],
			]
		);

		$this->assertTrue($this->registry->isProtected('besluit'));
		$this->assertFalse($this->registry->isProtected('newsletter'));

	}//end testIsProtectedAsksTheCategories()

	/**
	 * An erasure without a contact ref is refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testAnErasureWithoutAContactRefIsRefused(): void {
		$this->mapper->expects($this->never())->method('findForContactRefs');
		$this->mapper->expects($this->never())->method('update');
		$this->log->expects($this->never())->method('append');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An erasure needs a contactRef.');

		$this->registry->record(['state' => OptOutRegistry::STATE_ERASE_CONTACT, 'contactRef' => '   ', 'sourceApp' => 'integriq']);

	}//end testAnErasureWithoutAContactRefIsRefused()

}//end class
