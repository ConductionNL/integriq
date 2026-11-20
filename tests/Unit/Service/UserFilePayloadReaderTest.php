<?php

/**
 * Unit tests for UserFilePayloadReader.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/tasks.md#task-2-the-filing-consumer-and-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\UserFilePayloadReader;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading a referenced file, and only from a user's files.
 */
#[CoversClass(UserFilePayloadReader::class)]
class UserFilePayloadReaderTest extends TestCase {
	/**
	 * A file id reference reads the file it names.
	 *
	 * @return void
	 */
	public function testAFileIdIsRead(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getFirstNodeById')->with(1234)
			->willReturn($this->file(path: '/admin/files/Aangiften/ob.xbrl', content: '<xbrl/>'));

		$read = (new UserFilePayloadReader($root, $this->createMock(LoggerInterface::class)))->read(reference: 'nextcloud-file:1234');

		$this->assertSame(['content' => '<xbrl/>', 'name' => 'ob.xbrl'], $read);
	}//end testAFileIdIsRead()

	/**
	 * A path in a user's files is read.
	 *
	 * @return void
	 */
	public function testAUserFilesPathIsRead(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('get')->with('/admin/files/Aangiften/ob.xbrl')
			->willReturn($this->file(path: '/admin/files/Aangiften/ob.xbrl', content: '<xbrl/>'));

		$read = (new UserFilePayloadReader($root, $this->createMock(LoggerInterface::class)))->read(reference: '/admin/files/Aangiften/ob.xbrl');

		$this->assertSame('<xbrl/>', $read['content']);
	}//end testAUserFilesPathIsRead()

	/**
	 * A file id that resolves outside a user's files is refused.
	 *
	 * @return void
	 */
	public function testAFileOutsideUserFilesIsRefused(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturn($this->file(path: '/appdata_x/secret.key', content: 'KEY'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('payload not readable: nextcloud-file:9');

		(new UserFilePayloadReader($root, $this->createMock(LoggerInterface::class)))->read(reference: 'nextcloud-file:9');
	}//end testAFileOutsideUserFilesIsRefused()

	/**
	 * References in neither form are refused without a lookup.
	 *
	 * @return void
	 */
	public function testOtherReferencesAreRefusedWithoutALookup(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('get');
		$root->expects($this->never())->method('getFirstNodeById');
		$reader = new UserFilePayloadReader($root, $this->createMock(LoggerInterface::class));

		foreach (['/appdata_x/files.xbrl', 'nextcloud-file:12a', 'https://example.org/ob.xbrl', ''] as $reference) {
			try {
				$reader->read(reference: $reference);
				$this->fail('Reference ' . $reference . ' must be refused.');
			} catch (RuntimeException $exception) {
				$this->assertSame('payload not readable: ' . $reference, $exception->getMessage());
			}
		}
	}//end testOtherReferencesAreRefusedWithoutALookup()

	/**
	 * A missing path, a folder and an empty file are refused; the missing path is logged.
	 *
	 * @return void
	 */
	public function testMissingFoldersAndEmptyFilesAreRefused(): void {
		$root = $this->createMock(IRootFolder::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/admin/files/Aangiften');
		$root->method('get')->willReturnCallback(
			function (string $path) use ($folder): object {
				if ($path === '/admin/files/gone.xbrl') {
					throw new NotFoundException('gone');
				}

				if ($path === '/admin/files/Aangiften') {
					return $folder;
				}

				return $this->file(path: $path, content: '');
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$reader = new UserFilePayloadReader($root, $logger);

		foreach (['/admin/files/gone.xbrl', '/admin/files/Aangiften', '/admin/files/empty.xbrl'] as $reference) {
			try {
				$reader->read(reference: $reference);
				$this->fail('Reference ' . $reference . ' must be refused.');
			} catch (RuntimeException $exception) {
				$this->assertSame('payload not readable: ' . $reference, $exception->getMessage());
			}
		}
	}//end testMissingFoldersAndEmptyFilesAreRefused()

	/**
	 * A file double.
	 *
	 * @param string $path    Its path.
	 * @param string $content Its content.
	 *
	 * @return File
	 */
	private function file(string $path, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		$file->method('getContent')->willReturn($content);
		$file->method('getName')->willReturn(basename($path));
		return $file;
	}//end file()
}//end class
