<?php

/**
 * Golden recording of the multipart upload: what createUpload() hands out and
 * caches, and what writePart() writes, joins, deletes and throws for each set
 * of parts. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/storage-uploads/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\StorageService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Pins the multipart upload's parts, joins and refusals.
 *
 * @spec openspec/specs/storage-uploads/spec.md
 */
class StorageServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/storage-service-uploads.json';

	/**
	 * Every file call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The cache's entries.
	 *
	 * @var array<string,mixed>
	 */
	private array $cached = [];

	/**
	 * The upload sizes to hand out parts for (part size 1000).
	 *
	 * @return array<string,array{size:int,objectId:string|null}>
	 */
	private static function uploads(): array {
		return [
			'empty file'          => ['size' => 0, 'objectId' => null],
			'smaller than a part' => ['size' => 400, 'objectId' => 'obj-1'],
			'exactly two parts'   => ['size' => 2000, 'objectId' => null],
			'two and a bit'       => ['size' => 2001, 'objectId' => 'obj-2'],
		];

	}//end uploads()

	/**
	 * The parts folders a part lands in: the parts already there (name => content,
	 * null for a sub folder), the part written, the number of parts, and what
	 * stays in the folder after the join.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function writes(): array {
		return [
			'first of three'           => ['present' => [], 'part' => 1, 'numParts' => 3, 'left' => []],
			'last of three, in order'  => ['present' => ['1.part.txt' => 'a', '2.part.txt' => 'b'], 'part' => 3, 'numParts' => 3, 'left' => []],
			'last of three, folder kept' => ['present' => ['1.part.txt' => 'a', '2.part.txt' => 'b'], 'part' => 3, 'numParts' => 3, 'left' => ['x']],
			'listed out of order'      => ['present' => ['2.part.txt' => 'b', '1.part.txt' => 'a'], 'part' => 3, 'numParts' => 3, 'left' => []],
			'a part missing'           => ['present' => ['1.part.txt' => 'a', 'x.part.txt' => 'q'], 'part' => 3, 'numParts' => 3, 'left' => []],
			'a part twice too many'    => ['present' => ['1.part.txt' => 'a', '2.part.txt' => 'b', '4.part.txt' => 'd'], 'part' => 3, 'numParts' => 3, 'left' => []],
			'ten parts, string order'  => ['present' => ['1.part.txt' => '1', '2.part.txt' => '2', '3.part.txt' => '3', '4.part.txt' => '4', '5.part.txt' => '5', '6.part.txt' => '6', '7.part.txt' => '7', '8.part.txt' => '8', '9.part.txt' => '9'], 'part' => 10, 'numParts' => 10, 'left' => []],
			'an extra file joins too'  => ['present' => ['1.part.txt' => 'a', 'notes.md' => 'n'], 'part' => 2, 'numParts' => 2, 'left' => []],
			'a sub folder is skipped'  => ['present' => ['1.part.txt' => 'a', 'sub' => null], 'part' => 2, 'numParts' => 2, 'left' => []],
			'other extension'          => ['present' => ['1.part.pdf' => 'a'], 'part' => 2, 'numParts' => 2, 'left' => []],
			'parts folder is a file'   => ['present' => [], 'part' => 1, 'numParts' => 1, 'left' => [], 'partsFolderIsFile' => true],
			'target is a folder'       => ['present' => [], 'part' => 1, 'numParts' => 1, 'left' => [], 'targetIsFolder' => true],
		];

	}//end writes()

	/**
	 * Every upload and write does exactly what the recording holds.
	 *
	 * The join compares the part numbers, in name order with their listing
	 * keys, to 1..n: parts listed out of order, ten or more parts, or another
	 * file in the folder leave the parts unjoined. The recording keeps that
	 * as it is.
	 *
	 * @return void
	 */
	public function testUploadsMatchTheRecording(): void {
		$found = [];
		foreach (self::uploads() as $name => $upload) {
			$found['create ' . $name] = $this->create(upload: $upload);
		}

		foreach (self::writes() as $name => $write) {
			$found['write ' . $name] = $this->write(write: $write);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], $name);
		}

	}//end testUploadsMatchTheRecording()

	/**
	 * Run one createUpload and record its parts and cache entries (part ids masked).
	 *
	 * @param array<string,mixed> $upload The upload.
	 *
	 * @return array<string,mixed> The recording.
	 */
	private function create(array $upload): array {
		$this->calls = [];
		$this->cached = [];
		$target = $this->createMock(File::class);
		$target->method('getId')->willReturn(42);
		$partsFolder = $this->createMock(Folder::class);
		$partsFolder->method('getPath')->willReturn('/up/doc.txt_parts');
		$uploadFolder = $this->createMock(Folder::class);
		$uploadFolder->method('newFile')->willReturnCallback(function (string $name) use ($target): File {
			$this->calls[] = 'newFile ' . $name;
			return $target;
		});
		$uploadFolder->method('newFolder')->willReturnCallback(function (string $name) use ($partsFolder): Folder {
			$this->calls[] = 'newFolder ' . $name;
			return $partsFolder;
		});
		$root = $this->createMock(IRootFolder::class);
		$root->method('get')->willReturnCallback(function (string $path) use ($uploadFolder): Folder {
			$this->calls[] = 'get ' . $path;
			return $uploadFolder;
		});

		$parts = $this->service(root: $root)->createUpload(path: '/up', fileName: 'doc.txt', size: $upload['size'], objectId: $upload['objectId']);

		$ids = [];
		foreach ($parts as $i => $part) {
			$ids[(string)$part['id']] = 'part-' . $i;
			$parts[$i]['id'] = 'part-' . $i . ' (' . get_debug_type($part['id']) . ')';
		}

		$cached = [];
		foreach ($this->cached as $key => $value) {
			$cached[strtr($key, $ids)] = $value;
		}

		return ['parts' => $parts, 'cached' => $cached, 'calls' => $this->calls];

	}//end create()

	/**
	 * Run one writePart and record what it did or threw.
	 *
	 * @param array<string,mixed> $write The parts folder and the part.
	 *
	 * @return array<string,mixed> The recording.
	 */
	private function write(array $write): array {
		$this->calls = [];
		$this->cached = ['upload_p' => ['upload-target-id' => 7, 'upload-target-path' => '/up/doc.txt_parts', 'number-of-parts' => $write['numParts']]];
		$partsFolder = $this->partsFolder(write: $write);
		$target = $this->target(write: $write);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn($target);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$root->method('get')->willReturnCallback(function (string $path) use ($partsFolder) {
			$this->calls[] = 'get ' . $path;
			return $partsFolder;
		});

		try {
			$answer = $this->service(root: $root)->writePart(partId: $write['part'], partUuid: 'p', data: 'data-' . $write['part']);
		} catch (Throwable $exception) {
			$answer = get_class($exception) . ': ' . $exception->getMessage();
		}

		return ['answer' => $answer, 'calls' => $this->calls];

	}//end write()

	/**
	 * The parts folder: its listing holds the present parts plus each written one.
	 *
	 * @param array<string,mixed> $write The parts folder and the part.
	 *
	 * @return File|Folder The parts folder (a file when the case says so).
	 */
	private function partsFolder(array $write): File|Folder {
		if (($write['partsFolderIsFile'] ?? false) === true) {
			return $this->createMock(File::class);
		}

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/up/doc.txt_parts');
		$nodes = [];
		foreach ($write['present'] as $name => $content) {
			$nodes[] = $this->node(name: $name, content: $content, folder: $folder);
		}

		$folder->method('newFile')->willReturnCallback(function (string $name, $content) use (&$nodes, $folder): File {
			$this->calls[] = 'newFile ' . $name . ' ' . $content;
			$node = $this->node(name: $name, content: $content, folder: $folder);
			$nodes[] = $node;
			return $node;
		});
		$listings = 0;
		$folder->method('getDirectoryListing')->willReturnCallback(function () use (&$nodes, &$listings, $write): array {
			$listings++;
			$this->calls[] = 'getDirectoryListing';
			if ($listings === 1) {
				return $nodes;
			}

			return $write['left'];
		});
		$folder->method('delete')->willReturnCallback(function (): void {
			$this->calls[] = 'delete the parts folder';
		});

		return $folder;

	}//end partsFolder()

	/**
	 * A node in the parts folder: a file with content, or a sub folder.
	 *
	 * @param string      $name    Its name.
	 * @param string|null $content Its content, or null for a sub folder.
	 * @param Folder      $folder  Its parent.
	 *
	 * @return File|Folder The node.
	 */
	private function node(string $name, ?string $content, Folder $folder): File|Folder {
		if ($content === null) {
			$sub = $this->createMock(Folder::class);
			$sub->method('getName')->willReturn($name);
			$sub->method('getParent')->willReturn($folder);
			return $sub;
		}

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getParent')->willReturn($folder);
		$file->method('getContent')->willReturnCallback(function () use ($name, $content): string {
			$this->calls[] = 'read ' . $name;
			return $content;
		});
		$file->method('delete')->willReturnCallback(function () use ($name): void {
			$this->calls[] = 'delete ' . $name;
		});

		return $file;

	}//end node()

	/**
	 * The target file (a folder when the case says so).
	 *
	 * @param array<string,mixed> $write The case.
	 *
	 * @return File|Folder The target.
	 */
	private function target(array $write): File|Folder {
		if (($write['targetIsFolder'] ?? false) === true) {
			return $this->createMock(Folder::class);
		}

		$target = $this->createMock(File::class);
		$target->method('getExtension')->willReturn('txt');
		$target->method('putContent')->willReturnCallback(function (string $content): void {
			$this->calls[] = 'putContent ' . $content;
		});

		return $target;

	}//end target()

	/**
	 * The service over the root folder, a part size of 1000 and a recording cache.
	 *
	 * @param IRootFolder $root The root folder.
	 *
	 * @return StorageService
	 */
	private function service(IRootFolder $root): StorageService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(1000);
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->cached[$key] = $value;
			return true;
		});
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->cached[$key] ?? null));
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new StorageService(rootFolder: $root, config: $config, cacheFactory: $factory, userManager: $this->createMock(IUserManager::class));

	}//end service()
}//end class
