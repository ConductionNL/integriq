<?php

/**
 * Characterisation tests for CompoundFileReader's directory reading.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail;

use OCA\Integriq\Service\Mail\CompoundFileReader;
use OCA\Integriq\Service\Mail\MessageParseException;
use OCA\Integriq\Tests\Helpers\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins every directory entry the reader builds: names, types, sizes,
 * starts and tree links of used entries, the blank shape (and key order)
 * of unused or unknown entries, and the refusal without a root entry.
 */
class CompoundFileReaderDirectoryTest extends TestCase {

	/**
	 * The blank entry the reader records for an unused or unknown slot.
	 *
	 * @var array
	 */
	private const BLANK = [
		'name'  => '',
		'type'  => 0,
		'size'  => 0,
		'start' => 4294967294,
		'child' => -1,
		'left'  => -1,
		'right' => -1,
	];

	/**
	 * Build a small compound file.
	 *
	 * @return string The bytes.
	 */
	private function compoundFile(): string {
		return (new CompoundFileBuilder())
			->addStream('__substg1.0_0037001F', (string)iconv('UTF-8', 'UTF-16LE', 'Onderwerp'))
			->addStorage('__attach_version1.0_#00000000', ['__substg1.0_37010102' => str_repeat('S', 5000)])
			->addStream('x', '')
			->build();

	}//end compoundFile()

	/**
	 * Set the type byte of the directory entry with the given name.
	 *
	 * @param string $raw  The compound file bytes.
	 * @param string $name The entry name.
	 * @param int    $type The new type.
	 *
	 * @return string The patched bytes.
	 */
	private function withType(string $raw, string $name, int $type): string {
		$base = strpos($raw, (string)iconv('UTF-8', 'UTF-16LE', $name . "\0"));
		$this->assertIsInt($base);
		$raw[($base + 66)] = chr($type);

		return $raw;

	}//end withType()

	/**
	 * Every entry of a small file, used and unused, in directory order.
	 *
	 * @return void
	 */
	public function testEveryDirectoryEntryIsRead(): void {
		$entries = (new CompoundFileReader($this->compoundFile()))->getEntries();

		$this->assertSame(
			[
				0 => ['name' => 'Root Entry', 'type' => 5, 'size' => 64, 'start' => 10, 'left' => -1, 'right' => -1, 'child' => 1],
				1 => ['name' => '__substg1.0_0037001F', 'type' => 2, 'size' => 18, 'start' => 0, 'left' => 2, 'right' => -1, 'child' => -1],
				2 => ['name' => 'x', 'type' => 2, 'size' => 0, 'start' => 4294967294, 'left' => 3, 'right' => -1, 'child' => -1],
				3 => ['name' => '__attach_version1.0_#00000000', 'type' => 1, 'size' => 0, 'start' => 4294967294, 'left' => -1, 'right' => -1, 'child' => 4],
				4 => ['name' => '__substg1.0_37010102', 'type' => 2, 'size' => 5000, 'start' => 0, 'left' => -1, 'right' => -1, 'child' => -1],
				5 => self::BLANK,
				6 => self::BLANK,
				7 => self::BLANK,
			],
			$entries
		);

	}//end testEveryDirectoryEntryIsRead()

	/**
	 * An entry of an unknown type is recorded blank, whatever it holds.
	 *
	 * @return void
	 */
	public function testAnUnknownEntryTypeIsRecordedBlank(): void {
		$raw = $this->withType(raw: $this->compoundFile(), name: 'x', type: 3);

		$entries = (new CompoundFileReader($raw))->getEntries();

		$this->assertSame(self::BLANK, $entries[2]);
		$this->assertSame('__attach_version1.0_#00000000', $entries[3]['name']);

	}//end testAnUnknownEntryTypeIsRecordedBlank()

	/**
	 * A used entry whose name length holds no character beyond the
	 * terminator reads with an empty name.
	 *
	 * @return void
	 */
	public function testANameOfOnlyTheTerminatorReadsEmpty(): void {
		$raw = $this->compoundFile();
		$base = strpos($raw, (string)iconv('UTF-8', 'UTF-16LE', "x\0"));
		$this->assertIsInt($base);
		$raw = substr_replace($raw, pack('v', 2), ($base + 64), 2);

		$entries = (new CompoundFileReader($raw))->getEntries();

		$this->assertSame('', $entries[2]['name']);
		$this->assertSame(2, $entries[2]['type']);

	}//end testANameOfOnlyTheTerminatorReadsEmpty()

	/**
	 * A directory whose first entry is not the root is refused.
	 *
	 * @return void
	 */
	public function testADirectoryWithoutARootEntryIsRefused(): void {
		$raw = $this->withType(raw: $this->compoundFile(), name: 'Root Entry', type: 1);

		$this->expectException(MessageParseException::class);
		$this->expectExceptionMessage('Compound file has no root directory entry.');

		new CompoundFileReader($raw);

	}//end testADirectoryWithoutARootEntryIsRefused()

}//end class
