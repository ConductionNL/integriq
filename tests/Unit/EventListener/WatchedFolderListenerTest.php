<?php

/**
 * Unit tests for WatchedFolderListener: a file landing in a watched folder is
 * queued, and nothing else happens on the upload's request.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\WatchedFolderListener;
use OCA\Integriq\Service\WatchedFolder\WatchedFolders;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\EventListener\WatchedFolderListener
 */
final class WatchedFolderListenerTest extends TestCase {

	/**
	 * A file in a watched folder queues one job per synchronization that watches it, and reads nothing.
	 *
	 * @return void
	 */
	public function testTheListenerOnlyQueues(): void {
		$file    = $this->file(id: 42, folderId: 7);
		$folders = $this->folders(watching: [7 => ['sync-1', 'sync-2']]);
		$file->expects($this->never())->method('getContent');
		$folders->expects($this->exactly(2))->method('queue')->willReturnCallback(
			function (int $fileId, string $synchronizationId): bool {
				$this->assertSame(expected: 42, actual: $fileId);
				$this->assertContains(needle: $synchronizationId, haystack: ['sync-1', 'sync-2']);
				return true;
			}
		);

		(new WatchedFolderListener(folders: $folders))->handle(new NodeCreatedEvent($file));
	}//end testTheListenerOnlyQueues()

	/**
	 * A written file is queued the same way.
	 *
	 * @return void
	 */
	public function testAWrittenFileIsQueuedToo(): void {
		$folders = $this->folders(watching: [7 => ['sync-1']]);
		$folders->expects($this->once())->method('queue')->with(42, 'sync-1')->willReturn(true);

		(new WatchedFolderListener(folders: $folders))->handle(new NodeWrittenEvent($this->file(id: 42, folderId: 7)));
	}//end testAWrittenFileIsQueuedToo()

	/**
	 * A file outside every watched folder, a folder, and a file whose parent cannot be read are ignored.
	 *
	 * @return void
	 */
	public function testAFileOutsideAWatchedFolderIsIgnored(): void {
		$folders = $this->folders(watching: [7 => ['sync-1']]);
		$folders->expects($this->never())->method('queue');
		$listener = new WatchedFolderListener(folders: $folders);

		$listener->handle(new NodeCreatedEvent($this->file(id: 43, folderId: 8)));

		$folder = $this->createMock(originalClassName: Folder::class);
		$listener->handle(new NodeCreatedEvent($folder));

		$orphan = $this->createMock(originalClassName: File::class);
		$orphan->method('getParent')->willThrowException(new NotFoundException('gone'));
		$listener->handle(new NodeCreatedEvent($orphan));
	}//end testAFileOutsideAWatchedFolderIsIgnored()

	/**
	 * A file in a folder.
	 *
	 * @param int $id       The file id.
	 * @param int $folderId The folder's id.
	 *
	 * @return File&MockObject
	 */
	private function file(int $id, int $folderId): File {
		$parent = $this->createMock(originalClassName: Folder::class);
		$parent->method('getId')->willReturn($folderId);
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getParent')->willReturn($parent);

		return $file;
	}//end file()

	/**
	 * A watched-folder map.
	 *
	 * @param array<int, list<string>> $watching Folder id to synchronization ids.
	 *
	 * @return WatchedFolders&MockObject
	 */
	private function folders(array $watching): WatchedFolders {
		$folders = $this->createMock(originalClassName: WatchedFolders::class);
		$folders->method('watching')->willReturnCallback(static fn (int $folderId): array => ($watching[$folderId] ?? []));

		return $folders;
	}//end folders()
}//end class
