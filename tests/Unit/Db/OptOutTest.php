<?php

/**
 * Unit tests for the opt-out row's dedupe key and the column migration.
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
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Db;

use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Migration\Version2Date20261006100000;
use PHPUnit\Framework\TestCase;

/**
 * The key widens without moving an existing row.
 */
class OptOutTest extends TestCase {

	/**
	 * A row from before channels keeps its key: the formula of #2528.
	 *
	 * @return void
	 */
	public function testAnExistingRowKeepsItsKey(): void {
		$row = new OptOut();
		$row->setAddress('jan@example.org');
		$row->setScope('case');
		$row->setCaseRef('zaak/1');
		$row->assignDedupeKey();

		$this->assertSame(hash('sha256', "jan@example.org\ncase\nzaak/1"), $row->getDedupeKey());

		$instance = new OptOut();
		$instance->setAddress('jan@example.org');
		$instance->setScope('instance');
		$instance->assignDedupeKey();
		$this->assertSame(hash('sha256', "jan@example.org\ninstance\n"), $instance->getDedupeKey());

	}//end testAnExistingRowKeepsItsKey()

	/**
	 * A channel row and a list row get their own keys.
	 *
	 * @return void
	 */
	public function testAChannelAndAListAreTheirOwnRows(): void {
		$sms = new OptOut();
		$sms->setAddress('+31612345678');
		$sms->setScope('channel');
		$sms->setChannel('sms');
		$sms->assignDedupeKey();
		$this->assertSame(hash('sha256', "+31612345678\nchannel\n\nsms"), $sms->getDedupeKey());

		$list = new OptOut();
		$list->setAddress('jan@example.org');
		$list->setScope('list');
		$list->setListRef('nieuws');
		$list->assignDedupeKey();
		$this->assertSame(hash('sha256', "jan@example.org\nlist\nnieuws"), $list->getDedupeKey());

	}//end testAChannelAndAListAreTheirOwnRows()

	/**
	 * Every new column has a default an old row reads correctly under.
	 *
	 * @return void
	 */
	public function testTheMigrationOnlyAddsColumnsWithDefaults(): void {
		$columns = Version2Date20261006100000::COLUMNS;
		$this->assertSame('opted-out', $columns['state'][1]['default']);
		$this->assertSame('', $columns['channel'][1]['default']);
		foreach ($columns as $name => [, $options]) {
			$this->assertFalse(($options['notnull'] ?? true), $name . ' must accept an existing row');
		}

	}//end testTheMigrationOnlyAddsColumnsWithDefaults()

}//end class
