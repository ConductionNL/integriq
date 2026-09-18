<?php

/**
 * CompoundFileBuilder — writes a minimal MS-CFB file for tests.
 *
 * The `.msg` reader is only worth having if something can prove it reads a
 * real container, so the fixture is a real compound file rather than a canned
 * byte string: streams under the 4096 byte cutoff land in the mini stream and
 * larger ones get their own FAT chain, which is exactly the split the reader
 * has to get right.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

/**
 * Builds a compound file from named streams and one level of storages.
 */
final class CompoundFileBuilder {

	/**
	 * Bytes per sector.
	 *
	 * @var int
	 */
	private const SECTOR_SIZE = 512;

	/**
	 * Bytes per mini sector.
	 *
	 * @var int
	 */
	private const MINI_SECTOR_SIZE = 64;

	/**
	 * Streams under this size live in the mini stream.
	 *
	 * @var int
	 */
	private const MINI_CUTOFF = 4096;

	/**
	 * End of a sector chain.
	 *
	 * @var int
	 */
	private const END_OF_CHAIN = 0xFFFFFFFE;

	/**
	 * A free sector.
	 *
	 * @var int
	 */
	private const FREE_SECT = 0xFFFFFFFF;

	/**
	 * A sector holding part of the FAT.
	 *
	 * @var int
	 */
	private const FAT_SECT = 0xFFFFFFFD;

	/**
	 * Top level streams, name to content.
	 *
	 * @var array<string,string>
	 */
	private array $streams = [];

	/**
	 * Storages, name to their streams.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $storages = [];

	/**
	 * The sectors written so far.
	 *
	 * @var array<int,string>
	 */
	private array $sectors = [];

	/**
	 * The file allocation table.
	 *
	 * @var array<int,int>
	 */
	private array $fat = [];

	/**
	 * Add one stream to the root storage.
	 *
	 * @param string $name The stream name.
	 * @param string $content The stream content.
	 *
	 * @return self This builder.
	 */
	public function addStream(string $name, string $content): self {
		$this->streams[$name] = $content;
		return $this;

	}//end addStream()

	/**
	 * Add one storage with its streams.
	 *
	 * @param string $name The storage name.
	 * @param array<string,string> $streams The storage's streams.
	 *
	 * @return self This builder.
	 */
	public function addStorage(string $name, array $streams): self {
		$this->storages[$name] = $streams;
		return $this;

	}//end addStorage()

	/**
	 * Build the compound file.
	 *
	 * @return string The file bytes.
	 */
	public function build(): string {
		$this->sectors = [];
		$this->fat = [];

		$entries = [];
		$miniPayload = '';
		$miniFat = [];

		$place = function (string $content) use (&$miniPayload, &$miniFat): array {
			if ($content === '') {
				return ['start' => self::END_OF_CHAIN, 'size' => 0];
			}

			if (strlen($content) < self::MINI_CUTOFF) {
				$start = (int)(strlen($miniPayload) / self::MINI_SECTOR_SIZE);
				$padded = str_pad(
					$content,
					(int)(ceil(strlen($content) / self::MINI_SECTOR_SIZE) * self::MINI_SECTOR_SIZE),
					"\0"
				);
				$count = (int)(strlen($padded) / self::MINI_SECTOR_SIZE);
				for ($index = 0; $index < $count; $index++) {
					$miniFat[($start + $index)] = (($index === ($count - 1)) ? self::END_OF_CHAIN : ($start + $index + 1));
				}

				$miniPayload .= $padded;
				return ['start' => $start, 'size' => strlen($content)];
			}

			return ['start' => $this->allocate($content), 'size' => strlen($content)];
		};

		// Root's children: the top level streams, then the storages.
		$children = [];
		foreach ($this->streams as $name => $content) {
			$placed = $place($content);
			$children[] = [
				'name' => $name,
				'type' => 2,
				'start' => $placed['start'],
				'size' => $placed['size'],
				'child' => 0xFFFFFFFF,
			];
		}

		$storageChildren = [];
		foreach ($this->storages as $storageName => $storageStreams) {
			$inner = [];
			foreach ($storageStreams as $name => $content) {
				$placed = $place($content);
				$inner[] = [
					'name' => $name,
					'type' => 2,
					'start' => $placed['start'],
					'size' => $placed['size'],
					'child' => 0xFFFFFFFF,
				];
			}

			$storageChildren[$storageName] = $inner;
			$children[] = [
				'name' => $storageName,
				'type' => 1,
				'start' => self::END_OF_CHAIN,
				'size' => 0,
				'child' => null,
			];
		}

		// Directory ids: 0 is the root, then every child in the order above,
		// then each storage's own children.
		$entries[] = [
			'name' => 'Root Entry',
			'type' => 5,
			'start' => self::END_OF_CHAIN,
			'size' => 0,
			'child' => (count($children) > 0 ? 1 : 0xFFFFFFFF),
			'left' => 0xFFFFFFFF,
			'right' => 0xFFFFFFFF,
		];

		$nextId = 1;
		$childIds = [];
		foreach ($children as $child) {
			$childIds[] = $nextId;
			$nextId++;
		}

		$storageChildStart = [];
		foreach ($storageChildren as $storageName => $inner) {
			$storageChildStart[$storageName] = ($inner === [] ? 0xFFFFFFFF : $nextId);
			$nextId += count($inner);
		}

		// Root's children as a left-leaning chain: entry n points at entry n+1.
		foreach ($children as $position => $child) {
			$id = $childIds[$position];
			$entries[$id] = [
				'name' => $child['name'],
				'type' => $child['type'],
				'start' => $child['start'],
				'size' => $child['size'],
				'child' => ($child['child'] === null
					? $storageChildStart[$child['name']]
					: $child['child']),
				'left' => (isset($childIds[($position + 1)]) === true ? $childIds[($position + 1)] : 0xFFFFFFFF),
				'right' => 0xFFFFFFFF,
			];
		}

		foreach ($storageChildren as $storageName => $inner) {
			$base = $storageChildStart[$storageName];
			foreach ($inner as $position => $entry) {
				$entries[($base + $position)] = [
					'name' => $entry['name'],
					'type' => $entry['type'],
					'start' => $entry['start'],
					'size' => $entry['size'],
					'child' => 0xFFFFFFFF,
					'left' => (($position + 1) < count($inner) ? ($base + $position + 1) : 0xFFFFFFFF),
					'right' => 0xFFFFFFFF,
				];
			}
		}

		// The mini stream is the root entry's own stream.
		$miniStart = self::END_OF_CHAIN;
		if ($miniPayload !== '') {
			$miniStart = $this->allocate($miniPayload);
		}

		$entries[0]['start'] = $miniStart;
		$entries[0]['size'] = strlen($miniPayload);

		$miniFatBytes = '';
		$miniFatCount = 0;
		if ($miniFat !== []) {
			ksort($miniFat);
			foreach ($miniFat as $next) {
				$miniFatBytes .= pack('V', $next);
			}

			$miniFatBytes = str_pad(
				$miniFatBytes,
				(int)(ceil(strlen($miniFatBytes) / self::SECTOR_SIZE) * self::SECTOR_SIZE),
				pack('V', self::FREE_SECT)
			);
			$miniFatCount = (int)(strlen($miniFatBytes) / self::SECTOR_SIZE);
		}

		$miniFatStart = ($miniFatBytes === '' ? self::END_OF_CHAIN : $this->allocate($miniFatBytes));

		$directoryBytes = '';
		ksort($entries);
		foreach ($entries as $entry) {
			$directoryBytes .= $this->directoryEntry($entry);
		}

		$directoryStart = $this->allocate($directoryBytes);

		return $this->serialize($directoryStart, $miniFatStart, $miniFatCount);

	}//end build()

	/**
	 * Allocate one blob into consecutive sectors.
	 *
	 * @param string $content The blob.
	 *
	 * @return int The first sector id.
	 */
	private function allocate(string $content): int {
		$padded = str_pad($content, (int)(ceil(strlen($content) / self::SECTOR_SIZE) * self::SECTOR_SIZE), "\0");
		$count = (int)(strlen($padded) / self::SECTOR_SIZE);
		$start = count($this->sectors);
		for ($index = 0; $index < $count; $index++) {
			$id = ($start + $index);
			$this->sectors[$id] = substr($padded, ($index * self::SECTOR_SIZE), self::SECTOR_SIZE);
			$this->fat[$id] = (($index === ($count - 1)) ? self::END_OF_CHAIN : ($id + 1));
		}

		return $start;

	}//end allocate()

	/**
	 * Serialize one 128 byte directory entry.
	 *
	 * @param array<string,mixed> $entry The entry.
	 *
	 * @return string The entry bytes.
	 */
	private function directoryEntry(array $entry): string {
		$name = (string)$entry['name'];
		$utf16 = (string)iconv('UTF-8', 'UTF-16LE', $name) . "\0\0";
		$bytes = str_pad($utf16, 64, "\0");
		$bytes .= pack('v', strlen($utf16));
		$bytes .= chr((int)$entry['type']);
		$bytes .= chr(1);
		$bytes .= pack('V', (int)$entry['left']);
		$bytes .= pack('V', (int)$entry['right']);
		$bytes .= pack('V', (int)$entry['child']);
		$bytes .= str_repeat("\0", 16);
		$bytes .= str_repeat("\0", 4);
		$bytes .= str_repeat("\0", 16);
		$bytes .= pack('V', (int)$entry['start']);
		$bytes .= pack('V', (int)$entry['size']);
		$bytes .= pack('V', 0);

		return str_pad($bytes, 128, "\0");

	}//end directoryEntry()

	/**
	 * Write the header, the FAT sectors and the data sectors.
	 *
	 * @param int $directoryStart The first directory sector.
	 * @param int $miniFatStart The first mini FAT sector.
	 * @param int $miniFatCount How many mini FAT sectors there are.
	 *
	 * @return string The file bytes.
	 */
	private function serialize(int $directoryStart, int $miniFatStart, int $miniFatCount): string {
		$entriesPerSector = (int)(self::SECTOR_SIZE / 4);

		// The FAT sectors are themselves sectors, so the count has to settle.
		$fatSectorCount = 1;
		while (true) {
			$total = (count($this->sectors) + $fatSectorCount);
			$needed = (int)max(1, ceil($total / $entriesPerSector));
			if ($needed === $fatSectorCount) {
				break;
			}

			$fatSectorCount = $needed;
		}

		$fatSectorIds = [];
		for ($index = 0; $index < $fatSectorCount; $index++) {
			$id = (count($this->sectors) + $index);
			$fatSectorIds[] = $id;
			$this->fat[$id] = self::FAT_SECT;
		}

		$fatBytes = '';
		$totalSectors = (count($this->sectors) + $fatSectorCount);
		for ($id = 0; $id < ($fatSectorCount * $entriesPerSector); $id++) {
			$fatBytes .= pack('V', ($this->fat[$id] ?? self::FREE_SECT));
		}

		$header = self::signature();
		$header .= str_repeat("\0", 16);
		$header .= pack('v', 0x003E);
		$header .= pack('v', 3);
		$header .= pack('v', 0xFFFE);
		$header .= pack('v', 9);
		$header .= pack('v', 6);
		$header .= str_repeat("\0", 6);
		$header .= pack('V', 0);
		$header .= pack('V', $fatSectorCount);
		$header .= pack('V', $directoryStart);
		$header .= pack('V', 0);
		$header .= pack('V', self::MINI_CUTOFF);
		$header .= pack('V', $miniFatStart);
		$header .= pack('V', $miniFatCount);
		$header .= pack('V', self::END_OF_CHAIN);
		$header .= pack('V', 0);
		for ($index = 0; $index < 109; $index++) {
			$header .= pack('V', ($fatSectorIds[$index] ?? self::FREE_SECT));
		}

		$body = '';
		for ($id = 0; $id < $totalSectors; $id++) {
			if (in_array($id, $fatSectorIds, true) === true) {
				$position = (int)array_search($id, $fatSectorIds, true);
				$body .= substr($fatBytes, ($position * self::SECTOR_SIZE), self::SECTOR_SIZE);
				continue;
			}

			$body .= ($this->sectors[$id] ?? str_repeat("\0", self::SECTOR_SIZE));
		}

		return $header . $body;

	}//end serialize()

	/**
	 * The compound file magic bytes.
	 *
	 * @return string The signature.
	 */
	private static function signature(): string {
		return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	}//end signature()

}//end class
