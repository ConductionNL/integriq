<?php

/**
 * Unit tests for the queries the opt-out mapper builds.
 *
 * The send gate decides on what these queries return, so a condition that
 * drifts (an address not lower-cased, an empty value in an IN list, a page
 * size without a cap) changes who is mailed. These tests pin each query's
 * table, conditions and bound values against a recording query builder, and
 * the insert-if-absent race handling.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Db;

use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Db\OptOutMapper;
use OCA\Integriq\Tests\Helpers\RecordingQueryBuilder;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Lookups, the opt-out list page and count, and insert-if-absent.
 *
 * @covers \OCA\Integriq\Db\OptOutMapper
 * @uses   \OCA\Integriq\Db\OptOut
 */
class OptOutMapperQueryTest extends TestCase {

	private RecordingQueryBuilder $recorder;

	protected function setUp(): void {
		parent::setUp();
		$this->recorder = new RecordingQueryBuilder();
	}//end setUp()

	/**
	 * An address is looked up trimmed and lower-cased, and its rows come back as opt-outs.
	 *
	 * @return void
	 */
	public function testAnAddressIsLookedUpNormalised(): void {
		$found = $this->mapper([['rows' => [['id' => 7, 'address' => 'jan@example.nl', 'dedupe_key' => 'k-7']]]])
			->findForAddress('  Jan@Example.NL ');

		$this->assertSame(
			[['select', '*'], ['from', 'integriq_opt_outs'], ['where', 'eq(address, :p0)']],
			$this->recorder->queries[0]
		);
		$this->assertSame('jan@example.nl', $this->recorder->parameters[0][':p0']['value']);
		$this->assertCount(1, $found);
		$this->assertInstanceOf(OptOut::class, $found[0]);
		$this->assertSame('k-7', $found[0]->getDedupeKey());
	}//end testAnAddressIsLookedUpNormalised()

	/**
	 * A list lookup drops empty and repeated values, and asks nothing for an empty list.
	 *
	 * @return void
	 */
	public function testAListLookupDropsEmptyAndRepeatedValues(): void {
		$mapper = $this->mapper([['rows' => []], ['rows' => []]]);

		$this->assertSame([], $mapper->findForAddresses(['', '']));
		$this->assertSame([], $this->recorder->queries);

		$mapper->findForAddresses(['a@x.nl', '', 'a@x.nl', 'b@x.nl']);
		$mapper->findForContactRefs(['c-1']);

		$this->assertSame(['where', 'in(address, :p0)'], $this->recorder->queries[0][2]);
		$this->assertSame(['value' => ['a@x.nl', 'b@x.nl'], 'type' => IQueryBuilder::PARAM_STR_ARRAY], $this->recorder->parameters[0][':p0']);
		$this->assertSame(['where', 'in(contact_ref, :p0)'], $this->recorder->queries[1][2]);
		$this->assertSame(['c-1'], $this->recorder->parameters[1][':p0']['value']);
	}//end testAListLookupDropsEmptyAndRepeatedValues()

	/**
	 * A legacy reference finds at most one row, and none means null.
	 *
	 * @return void
	 */
	public function testALegacyReferenceFindsAtMostOneRow(): void {
		$mapper = $this->mapper([['rows' => [['id' => 3, 'legacy_uuid' => 'old-3']]], ['rows' => []]]);

		$this->assertSame('old-3', $mapper->findByLegacyRef('old-3')?->getLegacyUuid());
		$this->assertNull($mapper->findByLegacyRef('old-4'));
		$this->assertSame(
			[['select', '*'], ['from', 'integriq_opt_outs'], ['where', 'eq(legacy_uuid, :p0)'], ['setMaxResults', 1]],
			$this->recorder->queries[0]
		);
	}//end testALegacyReferenceFindsAtMostOneRow()

	/**
	 * Purpose-bound opt-outs are those with a non-empty purpose.
	 *
	 * @return void
	 */
	public function testPurposeBoundOptOutsHaveANonEmptyPurpose(): void {
		$this->mapper([['rows' => [['id' => 1, 'purpose' => 'newsletter']]]])->findWithPurpose();

		$this->assertSame(
			[['select', '*'], ['from', 'integriq_opt_outs'], ['where', 'isNotNull(purpose)'], ['andWhere', 'neq(purpose, :p0)']],
			$this->recorder->queries[0]
		);
		$this->assertSame('', $this->recorder->parameters[0][':p0']['value']);
	}//end testPurposeBoundOptOutsHaveANonEmptyPurpose()

	/**
	 * A dedupe key finds its one row, and an unknown key is null.
	 *
	 * @return void
	 */
	public function testADedupeKeyFindsItsRowOrNull(): void {
		$mapper = $this->mapper([['rows' => [['id' => 9, 'dedupe_key' => 'k-9']]], ['rows' => []]]);

		$this->assertSame(9, $mapper->findByKey('k-9')?->getId());
		$this->assertNull($mapper->findByKey('k-x'));
		$this->assertSame(['where', 'eq(dedupe_key, :p0)'], $this->recorder->queries[1][2]);
	}//end testADedupeKeyFindsItsRowOrNull()

	/**
	 * An opt-out that already exists is returned, not inserted again.
	 *
	 * @return void
	 */
	public function testAnExistingOptOutIsNotInsertedAgain(): void {
		$result = $this->mapper([['rows' => [['id' => 4, 'dedupe_key' => 'k-4']]]])->insertIfAbsent($this->optOut('k-4'));

		$this->assertFalse($result['created']);
		$this->assertSame(4, $result['optOut']->getId());
		$this->assertCount(1, $this->recorder->queries);
	}//end testAnExistingOptOutIsNotInsertedAgain()

	/**
	 * A new opt-out is inserted and comes back with its id.
	 *
	 * @return void
	 */
	public function testANewOptOutIsInserted(): void {
		$result = $this->mapper([['rows' => []], ['affected' => 1, 'insertId' => 12]])->insertIfAbsent($this->optOut('k-new'));

		$this->assertTrue($result['created']);
		$this->assertSame(12, $result['optOut']->getId());
		$this->assertSame(['insert', 'integriq_opt_outs'], $this->recorder->queries[1][0]);
	}//end testANewOptOutIsInserted()

	/**
	 * Losing an insert race to the unique index returns the winner's row.
	 *
	 * @return void
	 */
	public function testLosingAnInsertRaceReturnsTheWinner(): void {
		$result = $this->mapper([
			['rows' => []],
			['throw' => $this->uniqueViolation()],
			['rows' => [['id' => 5, 'dedupe_key' => 'k-race']]],
		])->insertIfAbsent($this->optOut('k-race'));

		$this->assertFalse($result['created']);
		$this->assertSame(5, $result['optOut']->getId());
	}//end testLosingAnInsertRaceReturnsTheWinner()

	/**
	 * A unique violation with no winner to read, or any other failure, is raised.
	 *
	 * @return void
	 */
	public function testAnInsertFailureWithoutAWinnerIsRaised(): void {
		$violation = $this->uniqueViolation();
		try {
			$this->mapper([['rows' => []], ['throw' => $violation], ['rows' => []]])->insertIfAbsent($this->optOut('k-gone'));
			$this->fail('Expected the violation to be raised.');
		} catch (DbException $e) {
			$this->assertSame($violation, $e);
		}

		$other = new DbException('disk full');
		$this->expectExceptionObject($other);
		(new OptOutMapper((new RecordingQueryBuilder())->connection(fn (string $class) => $this->createMock($class), [['rows' => []], ['throw' => $other]])))
			->insertIfAbsent($this->optOut('k-full'));
	}//end testAnInsertFailureWithoutAWinnerIsRaised()

	/**
	 * A page is newest first, its size capped between 1 and 500, and its offset never negative.
	 *
	 * @return void
	 */
	public function testAPageIsNewestFirstAndBounded(): void {
		$mapper = $this->mapper([['rows' => []], ['rows' => []]]);

		$mapper->findPage(10000, -5);
		$mapper->findPage(0, 20);

		$this->assertSame(
			[['select', '*'], ['from', 'integriq_opt_outs'], ['orderBy', 'created_at', 'DESC'], ['addOrderBy', 'id', 'DESC'], ['setMaxResults', 500], ['setFirstResult', 0]],
			$this->recorder->queries[0]
		);
		$this->assertSame(['setMaxResults', 1], $this->recorder->queries[1][4]);
		$this->assertSame(['setFirstResult', 20], $this->recorder->queries[1][5]);
	}//end testAPageIsNewestFirstAndBounded()

	/**
	 * The count reads the total the database returns.
	 *
	 * @return void
	 */
	public function testTheCountReadsTheTotal(): void {
		$this->assertSame(42, $this->mapper([['fetchOne' => '42']])->countAll());
		$this->assertSame('integriq_opt_outs', $this->recorder->queries[0][1][1]);
	}//end testTheCountReadsTheTotal()

	/**
	 * The mapper over a recording connection.
	 *
	 * @param array<int,array<string,mixed>> $answers The scripted answers.
	 *
	 * @return OptOutMapper
	 */
	private function mapper(array $answers): OptOutMapper {
		return new OptOutMapper($this->recorder->connection(fn (string $class) => $this->createMock($class), $answers));
	}//end mapper()

	/**
	 * A new opt-out with a dedupe key.
	 *
	 * @param string $key The dedupe key.
	 *
	 * @return OptOut
	 */
	private function optOut(string $key): OptOut {
		$optOut = new OptOut();
		$optOut->setAddress('jan@example.nl');
		$optOut->setDedupeKey($key);

		return $optOut;
	}//end optOut()

	/**
	 * A unique-constraint violation as the database layer reports it.
	 *
	 * @return DbException
	 */
	private function uniqueViolation(): DbException {
		return new class('duplicate key') extends DbException {
			/**
			 * The reason.
			 *
			 * @return int|null
			 */
			public function getReason(): ?int {
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}//end uniqueViolation()
}//end class
