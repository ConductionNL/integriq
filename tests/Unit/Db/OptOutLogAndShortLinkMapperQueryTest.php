<?php

/**
 * Unit tests for the queries the opt-out log and short-link mappers build.
 *
 * The opt-out log is the evidence for every send and refusal; its retention
 * purge must delete by age only, its page must be bounded, and a redaction
 * must update the row in place. A short link must resolve by its id only.
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

use OCA\Integriq\Db\OptOutLogEntry;
use OCA\Integriq\Db\OptOutLogMapper;
use OCA\Integriq\Db\UnsubscribeShortLink;
use OCA\Integriq\Db\UnsubscribeShortLinkMapper;
use OCA\Integriq\Tests\Helpers\RecordingQueryBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Log page, purge, address lookup and redaction; short link store and lookup.
 *
 * @covers \OCA\Integriq\Db\OptOutLogMapper
 * @covers \OCA\Integriq\Db\UnsubscribeShortLinkMapper
 * @uses   \OCA\Integriq\Db\OptOutLogEntry
 * @uses   \OCA\Integriq\Db\UnsubscribeShortLink
 */
class OptOutLogAndShortLinkMapperQueryTest extends TestCase {

	private RecordingQueryBuilder $recorder;

	protected function setUp(): void {
		parent::setUp();
		$this->recorder = new RecordingQueryBuilder();
	}//end setUp()

	/**
	 * A log page is newest first and bounded, and narrows to a correlation id only when one is given.
	 *
	 * @return void
	 */
	public function testALogPageIsBoundedAndNarrowsOnlyWhenAsked(): void {
		$mapper = $this->logMapper([['rows' => [['id' => 1, 'correlation_id' => 'c-1']]], ['rows' => []]]);

		$page = $mapper->findPage(9999, -1, 'c-1');
		$mapper->findPage(25, 50);

		$this->assertSame(
			[
				['select', '*'],
				['from', 'integriq_opt_out_log'],
				['orderBy', 'at', 'DESC'],
				['addOrderBy', 'id', 'DESC'],
				['setMaxResults', 500],
				['setFirstResult', 0],
				['where', 'eq(correlation_id, :p0)'],
			],
			$this->recorder->queries[0]
		);
		$this->assertSame('c-1', $this->recorder->parameters[0][':p0']['value']);
		$this->assertSame('c-1', $page[0]->getCorrelationId());
		$this->assertSame(['setMaxResults', 25], $this->recorder->queries[1][4]);
		$this->assertCount(6, $this->recorder->queries[1], 'no correlation filter without a correlation id');
	}//end testALogPageIsBoundedAndNarrowsOnlyWhenAsked()

	/**
	 * The retention purge deletes by age alone and reports how many rows went.
	 *
	 * @return void
	 */
	public function testThePurgeDeletesByAgeAndReportsTheCount(): void {
		$deleted = $this->logMapper([['affected' => 17]])->deleteOlderThan(1700000000);

		$this->assertSame(17, $deleted);
		$this->assertSame([['delete', 'integriq_opt_out_log'], ['where', 'lt(at, :p0)']], $this->recorder->queries[0]);
		$this->assertSame(['value' => 1700000000, 'type' => IQueryBuilder::PARAM_INT], $this->recorder->parameters[0][':p0']);
	}//end testThePurgeDeletesByAgeAndReportsTheCount()

	/**
	 * An address lookup drops empty and repeated addresses, orders by id, and asks nothing for none.
	 *
	 * @return void
	 */
	public function testAnAddressLookupDropsEmptyAndRepeatedAddresses(): void {
		$mapper = $this->logMapper([['rows' => []]]);

		$this->assertSame([], $mapper->findForAddresses(['']));
		$this->assertSame([], $this->recorder->queries);

		$mapper->findForAddresses(['a@x.nl', 'a@x.nl', '', 'b@x.nl']);

		$this->assertSame(['where', 'in(address, :p0)'], $this->recorder->queries[0][2]);
		$this->assertSame(['orderBy', 'id', 'ASC'], $this->recorder->queries[0][3]);
		$this->assertSame(['a@x.nl', 'b@x.nl'], $this->recorder->parameters[0][':p0']['value']);
	}//end testAnAddressLookupDropsEmptyAndRepeatedAddresses()

	/**
	 * A new log entry is inserted; a redaction updates the existing row by id.
	 *
	 * @return void
	 */
	public function testAppendInsertsAndRedactUpdatesInPlace(): void {
		$mapper = $this->logMapper([['insertId' => 31], ['affected' => 1]]);
		$entry = new OptOutLogEntry();
		$entry->setAddress('jan@example.nl');
		$entry->setKind('send');

		$stored = $mapper->append($entry);
		$stored->resetUpdatedFields();
		$stored->setAddress('');
		$mapper->redact($stored);

		$this->assertSame(31, $stored->getId());
		$this->assertSame(['insert', 'integriq_opt_out_log'], $this->recorder->queries[0][0]);
		$this->assertSame(['update', 'integriq_opt_out_log'], $this->recorder->queries[1][0]);
		$this->assertSame(['set', 'address', ':p0'], $this->recorder->queries[1][1]);
		$this->assertSame('', $this->recorder->parameters[1][':p0']['value']);
		$this->assertSame(['where', 'eq(id, :p1)'], $this->recorder->queries[1][2]);
		$this->assertSame(31, $this->recorder->parameters[1][':p1']['value']);
	}//end testAppendInsertsAndRedactUpdatesInPlace()

	/**
	 * A short link is stored, found by its id, and an unknown id is null.
	 *
	 * @return void
	 */
	public function testAShortLinkIsStoredAndFoundByItsIdOnly(): void {
		$mapper = new UnsubscribeShortLinkMapper($this->recorder->connection(
			fn (string $class) => $this->createMock($class),
			[['insertId' => 8], ['rows' => [['id' => 8, 'short_id' => 'Ab3x', 'token' => 'tok']]], ['rows' => []]]
		));
		$link = new UnsubscribeShortLink();
		$link->setShortId('Ab3x');
		$link->setToken('tok');

		$this->assertSame(8, $mapper->store($link)->getId());
		$this->assertSame('tok', $mapper->findByShortId('Ab3x')?->getToken());
		$this->assertNull($mapper->findByShortId('Zz9y'));
		$this->assertSame(
			[['select', '*'], ['from', 'integriq_unsubscribe_short'], ['where', 'eq(short_id, :p0)']],
			$this->recorder->queries[1]
		);
		$this->assertSame('Zz9y', $this->recorder->parameters[2][':p0']['value']);
	}//end testAShortLinkIsStoredAndFoundByItsIdOnly()

	/**
	 * The log mapper over a recording connection.
	 *
	 * @param array<int,array<string,mixed>> $answers The scripted answers.
	 *
	 * @return OptOutLogMapper
	 */
	private function logMapper(array $answers): OptOutLogMapper {
		return new OptOutLogMapper($this->recorder->connection(fn (string $class) => $this->createMock($class), $answers));
	}//end logMapper()
}//end class
