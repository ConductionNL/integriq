<?php

/**
 * Tests for the folder in Nextcloud Files as a source.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\Integriq\Service\Adapter\DataInfra\NextcloudFolderAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderFiles;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemotePath;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers NextcloudFolderAdapter and the NextcloudFolderFiles it reads through.
 */
#[CoversClass(NextcloudFolderAdapter::class)]
#[CoversClass(NextcloudFolderFiles::class)]
#[UsesClass(AbstractCategoryAdapterProvider::class)]
#[UsesClass(RemotePath::class)]
#[UsesClass(RemoteFileException::class)]
final class NextcloudFolderAdapterTest extends TestCase {

	/**
	 * Nextcloud's file tree.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder $rootFolder;

	/**
	 * The owner's Files.
	 *
	 * @var Folder&MockObject
	 */
	private Folder $userFolder;

	/**
	 * The folder's files over the file tree double.
	 *
	 * @return NextcloudFolderFiles
	 */
	private function files(): NextcloudFolderFiles {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf(format: $text, values: $parameters));

		return new NextcloudFolderFiles(rootFolder: $this->rootFolder, l10n: $l10n);
	}//end files()

	/**
	 * Build the adapter over the folder's files.
	 *
	 * @return NextcloudFolderAdapter
	 */
	private function adapter(): NextcloudFolderAdapter {
		return new NextcloudFolderAdapter(
			credentialBroker: $this->createMock(originalClassName: CredentialBrokerService::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			files: $this->files(),
		);
	}//end adapter()

	/**
	 * A folder source of user woo-intake.
	 *
	 * @param string $rootPath The configured root path.
	 *
	 * @return array<string,mixed>
	 */
	private function source(string $rootPath='Woo/inkomend'): array {
		return ['type' => 'nextcloud-folder', 'configuration' => ['ownerUid' => 'woo-intake', 'rootPath' => $rootPath]];
	}//end source()

	/**
	 * A file node double.
	 *
	 * @param int    $id   The file id.
	 * @param string $name The name.
	 *
	 * @return File&MockObject
	 */
	private function file(int $id, string $name): File {
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(2048);
		$file->method('getMTime')->willReturn(1700000000);

		return $file;
	}//end file()

	/**
	 * A folder double listing the given nodes.
	 *
	 * @param list<object> $nodes The listing.
	 *
	 * @return Folder&MockObject
	 */
	private function folder(array $nodes=[]): Folder {
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getDirectoryListing')->willReturn($nodes);

		return $folder;
	}//end folder()

	/**
	 * Set up the file tree double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userFolder = $this->createMock(originalClassName: Folder::class);
		$this->rootFolder = $this->createMock(originalClassName: IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with('woo-intake')->willReturn($this->userFolder);
	}//end setUp()

	/**
	 * The adapter describes itself as a read-only data-infra provider for the source type.
	 *
	 * @return void
	 */
	public function testTheProviderDescribesTheFolderSource(): void {
		$adapter = $this->adapter();

		$described = [
			$adapter->getId(),
			$adapter->getLabel(),
			$adapter->getCapabilities(),
			$adapter->isEnabled(),
			$adapter->getRequiredApp(),
			$adapter->authRequirements()['type'],
			$adapter->health()['status'],
		];

		$this->assertSame(
			expected: ['data-infra-nextcloud-folder', 'Folder in Nextcloud Files', ['read'], true, null, 'none', 'ok'],
			actual: $described
		);
		$this->assertTrue(condition: $this->files()->handles(source: ['type' => 'Nextcloud-Folder']));
		$this->assertFalse(condition: $this->files()->handles(source: ['type' => 'sftp']));
	}//end testTheProviderDescribesTheFolderSource()

	/**
	 * A source whose folder does not exist fails its test, naming the path.
	 *
	 * @return void
	 */
	public function testAMissingFolderFailsTheTest(): void {
		$this->userFolder->method('get')->with('Woo/inkomend')->willThrowException(new NotFoundException(message: 'not found'));

		$test = $this->adapter()->test(source: $this->source());

		$this->assertFalse(condition: $test['ok']);
		$this->assertStringContainsString(needle: '/Woo/inkomend', haystack: $test['message']);
	}//end testAMissingFolderFailsTheTest()

	/**
	 * A folder that exists passes its test and counts its files.
	 *
	 * @return void
	 */
	public function testAnExistingFolderPassesTheTest(): void {
		$listing = [$this->file(id: 1, name: 'a.pdf'), $this->folder()];
		$this->userFolder->method('get')->with('Woo/inkomend')->willReturn($this->folder(nodes: $listing));

		$test = $this->adapter()->test(source: $this->source());

		$this->assertSame(expected: ['ok' => true, 'message' => 'The folder /Woo/inkomend holds 1 files.'], actual: $test);
	}//end testAnExistingFolderPassesTheTest()

	/**
	 * A source without an owner fails its test.
	 *
	 * @return void
	 */
	public function testASourceWithoutAnOwnerFailsTheTest(): void {
		$test = $this->adapter()->test(source: ['type' => 'nextcloud-folder', 'configuration' => ['rootPath' => 'x']]);

		$this->assertFalse(condition: $test['ok']);
		$this->assertStringContainsString(needle: 'ownerUid', haystack: $test['message']);
	}//end testASourceWithoutAnOwnerFailsTheTest()

	/**
	 * The watched folder itself is the folder at the root path, and a folder path with `..` is refused.
	 *
	 * @return void
	 */
	public function testTheFolderOfTheSourceIsItsRootPath(): void {
		$inbox = $this->folder();
		$this->userFolder->method('get')->with('Woo/inkomend')->willReturn($inbox);

		$this->assertSame(expected: $inbox, actual: $this->files()->folder(source: $this->source()));

		$this->expectException(exception: RemoteFileException::class);
		$this->files()->folder(source: $this->source(), path: '../private');
	}//end testTheFolderOfTheSourceIsItsRootPath()

	/**
	 * A path with `..` is refused by every operation, before any file is touched.
	 *
	 * @return void
	 */
	public function testDotDotIsRefused(): void {
		$this->userFolder->expects($this->never())->method('get');
		$adapter = $this->adapter();
		$files   = $this->files();

		$operations = [
			fn () => $files->listFiles(source: $this->source(), path: '../private'),
			fn () => $files->fetch(source: $this->source(), path: 'sub/../../secret.pdf'),
			fn () => $adapter->list(register: '', schema: '', objectId: '', filters: ['source' => $this->source(rootPath: 'Woo/../other')]),
		];
		foreach ($operations as $operation) {
			try {
				$operation();
				$this->fail(message: 'A path with .. was not refused.');
			} catch (RemoteFileException $e) {
				$this->assertStringContainsString(needle: '..', haystack: $e->getMessage());
			}
		}

		$this->assertFalse(condition: $adapter->test(source: $this->source(rootPath: '../home'))['ok']);
	}//end testDotDotIsRefused()

	/**
	 * Listing answers the matching files of the folder, sorted, with their metadata.
	 *
	 * @return void
	 */
	public function testListingAnswersTheMatchingFilesSorted(): void {
		$listing = [
			$this->file(id: 7, name: 'b.pdf'),
			$this->file(id: 6, name: 'a.pdf'),
			$this->file(id: 8, name: 'notes.txt'),
			$this->folder(),
		];
		$this->userFolder->method('get')->with('Woo/inkomend/2026')->willReturn($this->folder(nodes: $listing));

		$filters = ['source' => $this->source(), 'path' => '2026', 'pattern' => '*.pdf'];
		$files   = $this->adapter()->list(register: '', schema: '', objectId: '', filters: $filters);

		$this->assertSame(expected: ['a.pdf', 'b.pdf'], actual: array_column(array: $files, column_key: 'name'));
		$this->assertSame(
			expected: [
				'fileId'   => 6,
				'name'     => 'a.pdf',
				'path'     => '2026/a.pdf',
				'mimetype' => 'application/pdf',
				'size'     => 2048,
				'mtime'    => 1700000000,
				'owner'    => 'woo-intake',
			],
			actual: $files[0]
		);
	}//end testListingAnswersTheMatchingFilesSorted()

	/**
	 * A listing for another source type, or without a source, is empty.
	 *
	 * @return void
	 */
	public function testListingAnotherSourceTypeIsEmpty(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$adapter = $this->adapter();

		$this->assertSame(expected: [], actual: $adapter->list(register: '', schema: '', objectId: '', filters: ['source' => ['type' => 'sftp']]));
		$this->assertSame(expected: [], actual: $adapter->list(register: '', schema: '', objectId: ''));
	}//end testListingAnotherSourceTypeIsEmpty()

	/**
	 * Fetching answers the file node; a folder or a missing file is refused.
	 *
	 * @return void
	 */
	public function testFetchAnswersTheFileAndRefusesAFolder(): void {
		$file   = $this->file(id: 9, name: 'besluit.pdf');
		$folder = $this->folder();
		$this->userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($file, $folder): object {
				return match ($path) {
					'Woo/inkomend/besluit.pdf' => $file,
					'Woo/inkomend/sub' => $folder,
					default => throw new NotFoundException(message: $path),
				};
			}
		);
		$files = $this->files();

		$this->assertSame(expected: $file, actual: $files->fetch(source: $this->source(), path: 'besluit.pdf'));

		foreach (['sub' => 'is a folder', 'gone.pdf' => 'does not exist'] as $path => $reason) {
			try {
				$files->fetch(source: $this->source(), path: $path);
				$this->fail(message: $path . ' was fetched.');
			} catch (RemoteFileException $e) {
				$this->assertStringContainsString(needle: $reason, haystack: $e->getMessage());
			}
		}
	}//end testFetchAnswersTheFileAndRefusesAFolder()

	/**
	 * A root path of `/` lists the owner's Files themselves.
	 *
	 * @return void
	 */
	public function testTheRootPathSlashIsTheOwnersFiles(): void {
		$this->userFolder->expects($this->never())->method('get');
		$this->userFolder->method('getDirectoryListing')->willReturn([$this->file(id: 1, name: 'top.pdf')]);

		$files = $this->files()->listFiles(source: $this->source(rootPath: '/'));

		$this->assertSame(expected: ['top.pdf'], actual: array_column(array: $files, column_key: 'path'));
	}//end testTheRootPathSlashIsTheOwnersFiles()
}//end class
