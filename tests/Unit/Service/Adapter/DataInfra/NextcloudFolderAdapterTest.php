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
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemotePath;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers NextcloudFolderAdapter.
 */
#[CoversClass(NextcloudFolderAdapter::class)]
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
	 * Build the adapter over a file tree double.
	 *
	 * @return NextcloudFolderAdapter
	 */
	private function adapter(): NextcloudFolderAdapter {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters));

		return new NextcloudFolderAdapter(
			credentialBroker: $this->createMock(CredentialBrokerService::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
			rootFolder: $this->rootFolder,
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
	 * @param int    $id    The file id.
	 * @param string $name  The name.
	 * @param int    $mtime The modification time.
	 *
	 * @return File&MockObject
	 */
	private function file(int $id, string $name, int $mtime=1700000000): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(2048);
		$file->method('getMTime')->willReturn($mtime);

		return $file;
	}//end file()

	/**
	 * Set up the file tree double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with('woo-intake')->willReturn($this->userFolder);
	}//end setUp()

	/**
	 * The adapter describes itself as a read-only data-infra provider for the source type.
	 *
	 * @return void
	 */
	public function testTheProviderDescribesTheFolderSource(): void {
		$adapter = $this->adapter();

		$this->assertSame(
			['data-infra-nextcloud-folder', 'Folder in Nextcloud Files', ['read'], true, null, 'none'],
			[$adapter->getId(), $adapter->getLabel(), $adapter->getCapabilities(), $adapter->isEnabled(), $adapter->getRequiredApp(), $adapter->authRequirements()['type']]
		);
		$this->assertSame('ok', $adapter->health()['status']);
		$this->assertTrue(NextcloudFolderAdapter::handles(source: ['type' => 'Nextcloud-Folder']));
		$this->assertFalse(NextcloudFolderAdapter::handles(source: ['type' => 'sftp']));
	}//end testTheProviderDescribesTheFolderSource()

	/**
	 * A source whose folder does not exist fails its test, naming the path.
	 *
	 * @return void
	 */
	public function testAMissingFolderFailsTheTest(): void {
		$this->userFolder->method('get')->with('Woo/inkomend')->willThrowException(new NotFoundException('not found'));

		$test = $this->adapter()->test(source: $this->source());

		$this->assertFalse($test['ok']);
		$this->assertStringContainsString('/Woo/inkomend', $test['message']);
	}//end testAMissingFolderFailsTheTest()

	/**
	 * A folder that exists passes its test and counts its files.
	 *
	 * @return void
	 */
	public function testAnExistingFolderPassesTheTest(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$this->file(id: 1, name: 'a.pdf'), $this->createMock(Folder::class)]);
		$this->userFolder->method('get')->with('Woo/inkomend')->willReturn($folder);

		$test = $this->adapter()->test(source: $this->source());

		$this->assertSame(['ok' => true, 'message' => 'The folder /Woo/inkomend holds 1 files.'], $test);
	}//end testAnExistingFolderPassesTheTest()

	/**
	 * A source without an owner fails its test.
	 *
	 * @return void
	 */
	public function testASourceWithoutAnOwnerFailsTheTest(): void {
		$test = $this->adapter()->test(source: ['type' => 'nextcloud-folder', 'configuration' => ['rootPath' => 'x']]);

		$this->assertFalse($test['ok']);
		$this->assertStringContainsString('ownerUid', $test['message']);
	}//end testASourceWithoutAnOwnerFailsTheTest()

	/**
	 * A path with `..` is refused by every operation, before any file is touched.
	 *
	 * @return void
	 */
	public function testDotDotIsRefused(): void {
		$this->userFolder->expects($this->never())->method('get');
		$adapter = $this->adapter();

		foreach ([
			fn () => $adapter->listFiles(source: $this->source(), path: '../private'),
			fn () => $adapter->fetch(source: $this->source(), path: 'sub/../../secret.pdf'),
			fn () => $adapter->listFiles(source: $this->source(rootPath: 'Woo/../other')),
		] as $operation) {
			try {
				$operation();
				$this->fail('A path with .. was not refused.');
			} catch (RemoteFileException $e) {
				$this->assertStringContainsString('..', $e->getMessage());
			}
		}

		$this->assertFalse($adapter->test(source: $this->source(rootPath: '../home'))['ok']);
	}//end testDotDotIsRefused()

	/**
	 * Listing answers the matching files of the folder, sorted, with their metadata.
	 *
	 * @return void
	 */
	public function testListingAnswersTheMatchingFilesSorted(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([
			$this->file(id: 7, name: 'b.pdf'),
			$this->file(id: 6, name: 'a.pdf'),
			$this->file(id: 8, name: 'notes.txt'),
			$this->createMock(Folder::class),
		]);
		$this->userFolder->method('get')->with('Woo/inkomend/2026')->willReturn($folder);

		$files = $this->adapter()->list(register: '', schema: '', objectId: '', filters: ['source' => $this->source(), 'path' => '2026', 'pattern' => '*.pdf']);

		$this->assertSame(['a.pdf', 'b.pdf'], array_column($files, 'name'));
		$this->assertSame(
			['fileId' => 6, 'name' => 'a.pdf', 'path' => '2026/a.pdf', 'mimetype' => 'application/pdf', 'size' => 2048, 'mtime' => 1700000000, 'owner' => 'woo-intake'],
			$files[0]
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

		$this->assertSame([], $adapter->list(register: '', schema: '', objectId: '', filters: ['source' => ['type' => 'sftp']]));
		$this->assertSame([], $adapter->list(register: '', schema: '', objectId: ''));
	}//end testListingAnotherSourceTypeIsEmpty()

	/**
	 * Fetching answers the file node; a folder or a missing file is refused.
	 *
	 * @return void
	 */
	public function testFetchAnswersTheFileAndRefusesAFolder(): void {
		$file = $this->file(id: 9, name: 'besluit.pdf');
		$this->userFolder->method('get')->willReturnCallback(
			function (string $path) use ($file) {
				return match ($path) {
					'Woo/inkomend/besluit.pdf' => $file,
					'Woo/inkomend/sub' => $this->createMock(Folder::class),
					default => throw new NotFoundException($path),
				};
			}
		);
		$adapter = $this->adapter();

		$this->assertSame($file, $adapter->fetch(source: $this->source(), path: 'besluit.pdf'));

		foreach (['sub' => 'is a folder', 'gone.pdf' => 'does not exist'] as $path => $reason) {
			try {
				$adapter->fetch(source: $this->source(), path: $path);
				$this->fail($path . ' was fetched.');
			} catch (RemoteFileException $e) {
				$this->assertStringContainsString($reason, $e->getMessage());
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

		$files = $this->adapter()->listFiles(source: $this->source(rootPath: '/'));

		$this->assertSame(['top.pdf'], array_column($files, 'path'));
	}//end testTheRootPathSlashIsTheOwnersFiles()
}//end class
