<?php

/**
 * Unit tests for how an opt-out log row reads back: its column types, its
 * detail and its JSON shape.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Db;

use OCA\Integriq\Db\OptOutLogEntry;
use PHPUnit\Framework\TestCase;

/**
 * A logged suppression reads back as the auditor's view shows it.
 *
 * @covers \OCA\Integriq\Db\OptOutLogEntry
 */
class OptOutLogEntryTest extends TestCase {

	/**
	 * A suppression row as the registry writes it.
	 *
	 * @param string|null $detail The detail column.
	 *
	 * @return OptOutLogEntry
	 */
	private function entry(?string $detail): OptOutLogEntry {
		$entry = new OptOutLogEntry();
		$entry->setId(42);
		$entry->setAt(1763200800);
		$entry->setKind('suppressed');
		$entry->setAddress('k:4f2a');
		$entry->setCategory('marketing');
		$entry->setChannel('email');
		$entry->setSourceApp('pipelinq');
		$entry->setCorrelationId('corr-7');
		$entry->setDetail($detail);
		return $entry;
	}//end entry()

	/**
	 * The JSON shape names every column, the time as ISO 8601 in UTC and the detail decoded.
	 *
	 * @return void
	 */
	public function testTheJsonShapeIsTheAuditorsView(): void {
		$json = $this->entry(detail: '{"wish":"instance","rows":3}')->jsonSerialize();

		$this->assertSame(
			[
				'id' => 42,
				'at' => '2025-11-15T10:00:00+00:00',
				'kind' => 'suppressed',
				'address' => 'k:4f2a',
				'category' => 'marketing',
				'channel' => 'email',
				'sourceApp' => 'pipelinq',
				'correlationId' => 'corr-7',
				'detail' => ['wish' => 'instance', 'rows' => 3],
			],
			$json
		);
	}//end testTheJsonShapeIsTheAuditorsView()

	/**
	 * A missing, malformed or scalar detail reads as an empty detail, never as an error.
	 *
	 * @return void
	 */
	public function testADetailThatIsNotAnObjectReadsAsEmpty(): void {
		foreach ([null, '', 'not json', '"a string"', '7'] as $detail) {
			$this->assertSame([], $this->entry(detail: $detail)->detailArray(), var_export($detail, true));
			$this->assertSame([], $this->entry(detail: $detail)->jsonSerialize()['detail'], var_export($detail, true));
		}

		$this->assertSame(['a', 'b'], $this->entry(detail: '["a","b"]')->detailArray());
	}//end testADetailThatIsNotAnObjectReadsAsEmpty()

	/**
	 * The columns are typed, so a time read from the database comes back as an integer.
	 *
	 * @return void
	 */
	public function testTheColumnTypesAreDeclared(): void {
		$types = (new OptOutLogEntry())->getFieldTypes();

		$this->assertSame('integer', $types['at']);
		foreach (['kind', 'address', 'category', 'channel', 'sourceApp', 'correlationId', 'detail'] as $column) {
			$this->assertSame('string', $types[$column], $column);
		}

		$row = OptOutLogEntry::fromRow(['id' => '5', 'at' => '1763200800', 'kind' => 'override', 'detail' => null]);
		$this->assertSame(1763200800, $row->getAt());
		$this->assertSame('2025-11-15T10:00:00+00:00', $row->jsonSerialize()['at']);
	}//end testTheColumnTypesAreDeclared()
}//end class
