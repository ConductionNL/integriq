<?php

/**
 * Unit tests for ZipArchiveMembers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
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

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\ZipArchiveMembers;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Pins which members of an archive are read, in which order, and where the limits stop it.
 *
 * @covers \OCA\Integriq\Service\Synchronization\ZipArchiveMembers
 *
 * @spec openspec/changes/native-data-gathering-provider/tasks.md
 */
class ZipArchiveMembersTest extends TestCase {

	/**
	 * Builds an archive in memory.
	 *
	 * @param array<string, string|null> $entries Member name to contents; null adds a directory.
	 *
	 * @return string The archive's bytes.
	 */
	public static function archive(array $entries): string {
		$path = tempnam(sys_get_temp_dir(), 'zip-test-');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($entries as $name => $contents) {
			if ($contents === null) {
				$zip->addEmptyDir($name);
				continue;
			}

			$zip->addFromString($name, $contents);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);

		return $bytes;
	}//end archive()

	/**
	 * Every file member comes back in archive order, directories and resource forks left out.
	 *
	 * @return void
	 */
	public function testFileMembersComeBackInArchiveOrder(): void {
		$bytes = self::archive(
			[
				'feeds/'                 => null,
				'feeds/2026-11-01.atom'  => '<feed>one</feed>',
				'__MACOSX/._x.atom'      => 'fork',
				'feeds/._hidden.atom'    => 'fork',
				'feeds/2026-11-02.atom'  => '<feed>two</feed>',
			]
		);

		$members = (new ZipArchiveMembers())->read($bytes);

		$this->assertSame(
			[
				['name' => 'feeds/2026-11-01.atom', 'body' => '<feed>one</feed>'],
				['name' => 'feeds/2026-11-02.atom', 'body' => '<feed>two</feed>'],
			],
			$members
		);
	}//end testFileMembersComeBackInArchiveOrder()

	/**
	 * A pattern keeps only the members whose name matches it.
	 *
	 * @return void
	 */
	public function testAPatternNarrowsTheMembers(): void {
		$bytes = self::archive(['a.atom' => 'A', 'readme.txt' => 'R', 'b.atom' => 'B']);

		$members = (new ZipArchiveMembers())->read($bytes, '*.atom');

		$this->assertSame(['a.atom', 'b.atom'], array_column($members, 'name'));
	}//end testAPatternNarrowsTheMembers()

	/**
	 * An archive with more members than allowed is refused, not cut short.
	 *
	 * @return void
	 */
	public function testTooManyMembersAreRefused(): void {
		$bytes = self::archive(['a.json' => '[]', 'b.json' => '[]', 'c.json' => '[]']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('more than 2 members');

		(new ZipArchiveMembers(maxMembers: 2))->read($bytes);
	}//end testTooManyMembersAreRefused()

	/**
	 * An archive that unpacks past the byte budget is refused.
	 *
	 * @return void
	 */
	public function testAnArchiveOverTheByteBudgetIsRefused(): void {
		$bytes = self::archive(['a.json' => str_repeat('x', 60), 'b.json' => str_repeat('y', 60)]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('more than 100 bytes');

		(new ZipArchiveMembers(maxTotalBytes: 100))->read($bytes);
	}//end testAnArchiveOverTheByteBudgetIsRefused()

	/**
	 * Bytes that are not an archive are refused.
	 *
	 * @return void
	 */
	public function testBytesThatAreNotAnArchiveAreRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not be opened');

		(new ZipArchiveMembers())->read('{"not":"a zip"}');
	}//end testBytesThatAreNotAnArchiveAreRefused()

	/**
	 * Only bytes with the local-file-header signature look like an archive.
	 *
	 * @return void
	 */
	public function testOnlyTheZipSignatureLooksLikeAnArchive(): void {
		$members = new ZipArchiveMembers();

		$this->assertTrue($members->looksLikeZip(self::archive(['a' => 'b'])));
		$this->assertFalse($members->looksLikeZip('[{"id":1}]'));
		$this->assertFalse($members->looksLikeZip('PK'));
	}//end testOnlyTheZipSignatureLooksLikeAnArchive()
}//end class
