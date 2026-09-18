<?php

/**
 * Integriq — Redmine migration adapter tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Migration\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Migration\Source;

use OCA\Integriq\Migration\Source\RedmineMigrationSource;
use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\RegistrySourceGateway;
use PHPUnit\Framework\TestCase;

/**
 * REQ-MSA-003 and REQ-MSA-004, against a mock-mode fixture rather than a live
 * Redmine, which cannot be staged on CI.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-named-incumbent-has-an-adapter-and-a-supported-path-is-rehearsable-req-msa-003
 */
class RedmineMigrationSourceTest extends TestCase {
	/**
	 * A gateway double restricted to the method the real class has.
	 *
	 * @return RegistrySourceGateway The double.
	 */
	private function gateway(): RegistrySourceGateway {
		return $this->getMockBuilder(RegistrySourceGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['read'])
			->getMock();
	}//end gateway()

	/**
	 * describe() names the kinds, and says plainly that a journal entry has no
	 * stable key.
	 *
	 * @return void
	 */
	public function testDescribeDeclaresWhichKindsHaveAStableKey(): void {
		$described = (new RedmineMigrationSource($this->gateway()))->describe();

		$kinds = array_column($described['kinds'], 'stableIdentifier', 'kind');
		$this->assertTrue($kinds['issue']);
		$this->assertTrue($kinds['project']);
		$this->assertFalse($kinds['journal'], 'A journal entry is not addressable, so the adapter says so.');
	}//end testDescribeDeclaresWhichKindsHaveAStableKey()

	/**
	 * A count is the source's own total, and it is complete.
	 *
	 * @return void
	 */
	public function testACountIsTheSourcesOwnTotal(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willReturn(['total_count' => 14000, 'issues' => []]);

		$counted = (new RedmineMigrationSource($gateway))->count('issue');

		$this->assertSame(14000, $counted['count']);
		$this->assertTrue($counted['complete']);
	}//end testACountIsTheSourcesOwnTotal()

	/**
	 * A source that does not answer is an unknown count reported as
	 * incomplete, never a count of zero that reads like an empty system.
	 *
	 * @return void
	 */
	public function testAnUnreachableSourceIsIncompleteNotZero(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willThrowException(new SourceUnreachableException('redmine', 'down'));

		$counted = (new RedmineMigrationSource($gateway))->count('issue');

		$this->assertFalse($counted['complete'], 'An unreachable source must not report a complete count.');
	}//end testAnUnreachableSourceIsIncompleteNotZero()

	/**
	 * A kind the adapter does not yield counts nothing and reads nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownKindYieldsNothing(): void {
		$gateway = $this->gateway();
		$gateway->expects($this->never())->method('read');

		$adapter = new RedmineMigrationSource($gateway);

		$this->assertSame(['count' => 0, 'complete' => false], $adapter->count('invoice'));
		$this->assertSame([], (array)$adapter->read('invoice'));
	}//end testAnUnknownKindYieldsNothing()

	/**
	 * A read pages through the source and stops when it has everything.
	 *
	 * @return void
	 */
	public function testAReadPagesThroughTheSource(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willReturnOnConsecutiveCalls(
			['total_count' => 3, 'issues' => [['id' => 1, 'subject' => 'een'], ['id' => 2, 'subject' => 'twee']]],
			['total_count' => 3, 'issues' => [['id' => 3, 'subject' => 'drie']]]
		);

		$records = (array)(new RedmineMigrationSource($gateway))->read('issue');

		$this->assertCount(3, $records);
		$this->assertSame('3', $records[2]->getForeignId());
		$this->assertSame('drie', $records[2]->getData()['subject']);
	}//end testAReadPagesThroughTheSource()

	/**
	 * Every issue carries its Redmine id as its foreign identity, in the
	 * provenance shape the property-source change already defines.
	 *
	 * @return void
	 */
	public function testEveryIssueCarriesItsRedmineIdentity(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willReturn(['total_count' => 1, 'issues' => [['id' => 42, 'subject' => 'een zaak']]]);

		$sample = (new RedmineMigrationSource($gateway))->sample('issue', [], 5);

		$this->assertCount(1, $sample);
		$shape = $sample[0]->toArray();
		$this->assertSame('redmine', $shape['provenance']['provider']);
		$this->assertSame('42', $shape['provenance']['sourceIdentifier']);
		$this->assertTrue($shape['match']['matchable']);
	}//end testEveryIssueCarriesItsRedmineIdentity()

	/**
	 * A journal entry yields a record that says it cannot be matched, rather
	 * than borrowing an identifier from somewhere else.
	 *
	 * @return void
	 */
	public function testAJournalEntryIsNotMatchable(): void {
		$gateway = $this->gateway();
		$gateway->method('read')->willReturn(['total_count' => 1, 'journals' => [['notes' => 'een notitie']]]);

		$sample = (new RedmineMigrationSource($gateway))->sample('journal');

		$this->assertCount(1, $sample);
		$this->assertNull($sample[0]->getForeignId());
		$this->assertFalse($sample[0]->isMatchable());
	}//end testAJournalEntryIsNotMatchable()

	/**
	 * The adapter reads the configured source and asks for nothing else: a
	 * rehearsal reads and writes nothing.
	 *
	 * @return void
	 */
	public function testARehearsalOnlyEverReads(): void {
		$gateway = $this->gateway();
		$gateway->expects($this->atLeastOnce())
			->method('read')
			->with('redmine', 'redmine-acceptatie', $this->anything(), $this->anything())
			->willReturn(['total_count' => 0, 'issues' => []]);

		(new RedmineMigrationSource($gateway))->count('issue', ['source' => 'redmine-acceptatie']);
	}//end testARehearsalOnlyEverReads()
}//end class
