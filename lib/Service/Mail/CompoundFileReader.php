<?php

/**
 * Integriq CompoundFileReader.
 *
 * A reader for the Compound File Binary format (MS-CFB), the container an
 * Outlook `.msg` file is. It walks the FAT, the mini FAT and the directory
 * tree so {@see MsgParser} can read property streams by name. Read-only, no
 * network, no shell-out.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 * @link https://learn.microsoft.com/en-us/openspecs/windows_protocols/ms-cfb/
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

use OCA\Integriq\Exception\MessageParseException;

/**
 * Reads streams and storages out of a compound file.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
final class CompoundFileReader {

	/**
	 * The compound file magic bytes.
	 *
	 * @var string
	 */
	public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	/**
	 * Sector id marking the end of a chain.
	 *
	 * @var int
	 */
	private const END_OF_CHAIN = 0xFFFFFFFE;

	/**
	 * Directory entry type for a storage (a folder).
	 *
	 * @var int
	 */
	public const TYPE_STORAGE = 1;

	/**
	 * Directory entry type for a stream (a file).
	 *
	 * @var int
	 */
	public const TYPE_STREAM = 2;

	/**
	 * Directory entry type for the root entry.
	 *
	 * @var int
	 */
	public const TYPE_ROOT = 5;

	/**
	 * Bytes per sector.
	 *
	 * @var int
	 */
	private int $sectorSize;

	/**
	 * Bytes per mini sector.
	 *
	 * @var int
	 */
	private int $miniSectorSize;

	/**
	 * Streams smaller than this live in the mini stream.
	 *
	 * @var int
	 */
	private int $miniCutoff;

	/**
	 * The file allocation table as a flat list of next-sector ids.
	 *
	 * @var array<int,int>
	 */
	private array $fat = [];

	/**
	 * The mini file allocation table.
	 *
	 * @var array<int,int>
	 */
	private array $miniFat = [];

	/**
	 * The directory entries, in directory order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $entries = [];

	/**
	 * The mini stream bytes, read lazily.
	 *
	 * @var string|null
	 */
	private ?string $miniStream = null;

	/**
	 * Constructor.
	 *
	 * @param string $raw The whole compound file.
	 *
	 * @throws MessageParseException When the bytes are not a readable compound file.
	 */
	public function __construct(private readonly string $raw) {
		if (self::isCompoundFile(raw: $raw) === false) {
			throw new MessageParseException('Not a compound file: the MS-CFB signature is missing.');
		}

		if (strlen($raw) < 512) {
			throw new MessageParseException('Truncated compound file: the header is incomplete.');
		}

		$this->sectorSize = (1 << $this->uint16(offset: 30));
		$this->miniSectorSize = (1 << $this->uint16(offset: 32));
		$this->miniCutoff = $this->uint32(offset: 56);

		if ($this->sectorSize < 128 || $this->miniSectorSize < 16) {
			throw new MessageParseException('Unsupported compound file: implausible sector size.');
		}

		$this->readFat();
		$this->readDirectory();
		$this->readMiniFat();

	}//end __construct()

	/**
	 * Whether these bytes open with the compound file signature.
	 *
	 * @param string $raw The candidate bytes.
	 *
	 * @return bool True when the signature matches.
	 */
	public static function isCompoundFile(string $raw): bool {
		return str_starts_with($raw, self::SIGNATURE);

	}//end isCompoundFile()

	/**
	 * Every directory entry, indexed by directory id.
	 *
	 * @return array<int,array<string,mixed>> The entries.
	 */
	public function getEntries(): array {
		return $this->entries;

	}//end getEntries()

	/**
	 * The direct children of one storage, by directory id.
	 *
	 * The children of a storage form a red-black tree; this walks it and
	 * returns the members in no particular order.
	 *
	 * @param int $entryId The storage's directory id.
	 *
	 * @return array<int,int> The children's directory ids.
	 */
	public function getChildren(int $entryId): array {
		$entry = ($this->entries[$entryId] ?? null);
		if ($entry === null) {
			return [];
		}

		$children = [];
		$this->collectSiblings(entryId: (int)$entry['child'], collected: $children, seen: []);
		return $children;

	}//end getChildren()

	/**
	 * Read one stream's bytes.
	 *
	 * @param int $entryId The stream's directory id.
	 *
	 * @return string The stream bytes.
	 *
	 * @throws MessageParseException When the directory id is unknown.
	 */
	public function readStream(int $entryId): string {
		$entry = ($this->entries[$entryId] ?? null);
		if ($entry === null) {
			throw new MessageParseException('Unknown directory entry ' . $entryId . '.');
		}

		$size = (int)$entry['size'];
		$start = (int)$entry['start'];
		if ($size === 0) {
			return '';
		}

		if ($entryId !== 0 && $size < $this->miniCutoff) {
			return $this->readChain(
				start: $start,
				size: $size,
				table: $this->miniFat,
				sectorSize: $this->miniSectorSize,
				container: $this->readMiniStream()
			);
		}

		return $this->readChain(start: $start, size: $size, table: $this->fat, sectorSize: $this->sectorSize, container: null);

	}//end readStream()

	/**
	 * Walk a sector chain and concatenate its payload.
	 *
	 * @param int $start The first sector id.
	 * @param int $size How many bytes the stream holds.
	 * @param array<int,int> $table The allocation table to follow.
	 * @param int $sectorSize Bytes per sector in this table.
	 * @param string|null $container The mini stream when reading mini sectors, null for the file itself.
	 *
	 * @return string The stream bytes.
	 */
	private function readChain(int $start, int $size, array $table, int $sectorSize, ?string $container): string {
		$bytes = '';
		$sector = $start;
		$guard = (count($table) + 2);
		while ($sector !== self::END_OF_CHAIN && $sector >= 0 && $guard > 0) {
			if ($container === null) {
				$offset = (($sector + 1) * $this->sectorSize);
				$bytes .= substr($this->raw, $offset, $sectorSize);
			} else {
				$bytes .= substr($container, ($sector * $sectorSize), $sectorSize);
			}

			if (strlen($bytes) >= $size) {
				break;
			}

			$sector = ($table[$sector] ?? self::END_OF_CHAIN);
			$guard--;
		}

		return substr($bytes, 0, $size);

	}//end readChain()

	/**
	 * Build the file allocation table from the DIFAT.
	 *
	 * @return void
	 */
	private function readFat(): void {
		$fatSectors = [];
		for ($index = 0; $index < 109; $index++) {
			$sector = $this->uint32(offset: (76 + ($index * 4)));
			if ($sector === self::END_OF_CHAIN || $sector === 0xFFFFFFFF) {
				continue;
			}

			$fatSectors[] = $sector;
		}

		$difatSector = $this->uint32(offset: 68);
		$perSector = (int)(($this->sectorSize / 4) - 1);
		$guard = 1024;
		while ($difatSector !== self::END_OF_CHAIN && $difatSector !== 0xFFFFFFFF && $guard > 0) {
			$base = (($difatSector + 1) * $this->sectorSize);
			for ($index = 0; $index < $perSector; $index++) {
				$sector = $this->uint32(offset: ($base + ($index * 4)));
				if ($sector === self::END_OF_CHAIN || $sector === 0xFFFFFFFF) {
					continue;
				}

				$fatSectors[] = $sector;
			}

			$difatSector = $this->uint32(offset: ($base + ($perSector * 4)));
			$guard--;
		}

		foreach ($fatSectors as $fatSector) {
			$base = (($fatSector + 1) * $this->sectorSize);
			for ($index = 0; $index < ($this->sectorSize / 4); $index++) {
				$this->fat[] = $this->uint32(offset: ($base + ($index * 4)));
			}
		}

	}//end readFat()

	/**
	 * Build the mini file allocation table.
	 *
	 * @return void
	 */
	private function readMiniFat(): void {
		$count = $this->uint32(offset: 64);
		if ($count === 0) {
			return;
		}

		$raw = $this->readChain(
			start: $this->uint32(offset: 60),
			size: ($count * $this->sectorSize),
			table: $this->fat,
			sectorSize: $this->sectorSize,
			container: null
		);
		for ($offset = 0; ($offset + 4) <= strlen($raw); $offset += 4) {
			$unpacked = unpack('V', substr($raw, $offset, 4));
			$this->miniFat[] = (int)($unpacked[1] ?? self::END_OF_CHAIN);
		}

	}//end readMiniFat()

	/**
	 * Read the directory entries.
	 *
	 * @return void
	 *
	 * @throws MessageParseException When the directory chain holds no root entry.
	 */
	private function readDirectory(): void {
		$raw = $this->readChain(start: $this->uint32(offset: 48), size: PHP_INT_MAX, table: $this->fat, sectorSize: $this->sectorSize, container: null);
		$count = (int)floor(strlen($raw) / 128);
		for ($index = 0; $index < $count; $index++) {
			$base = ($index * 128);
			$nameLength = (int)(unpack('v', substr($raw, ($base + 64), 2))[1] ?? 0);
			$name = '';
			if ($nameLength > 2) {
				$utf16 = substr($raw, $base, ($nameLength - 2));
				$converted = @iconv('UTF-16LE', 'UTF-8//IGNORE', $utf16);
				$name = $converted;
				if ($converted === false) {
					$name = '';
				}
			}

			$type = ord(substr($raw, ($base + 66), 1));
			if ($type !== self::TYPE_STORAGE && $type !== self::TYPE_STREAM && $type !== self::TYPE_ROOT) {
				$this->entries[$index] = [
					'name' => '',
					'type' => 0,
					'size' => 0,
					'start' => self::END_OF_CHAIN,
					'child' => -1,
					'left' => -1,
					'right' => -1,
				];
				continue;
			}

			$this->entries[$index] = [
				'name' => $name,
				'type' => $type,
				'size' => (int)(unpack('V', substr($raw, ($base + 120), 4))[1] ?? 0),
				'start' => (int)(unpack('V', substr($raw, ($base + 116), 4))[1] ?? self::END_OF_CHAIN),
				'left' => $this->directoryId(raw: $raw, offset: ($base + 68)),
				'right' => $this->directoryId(raw: $raw, offset: ($base + 72)),
				'child' => $this->directoryId(raw: $raw, offset: ($base + 76)),
			];
		}

		if (isset($this->entries[0]) === false || $this->entries[0]['type'] !== self::TYPE_ROOT) {
			throw new MessageParseException('Compound file has no root directory entry.');
		}

	}//end readDirectory()

	/**
	 * Read a directory id, mapping the "none" marker to -1.
	 *
	 * @param string $raw The directory bytes.
	 * @param int $offset Where the id sits.
	 *
	 * @return int The id, or -1.
	 */
	private function directoryId(string $raw, int $offset): int {
		$value = (int)(unpack('V', substr($raw, $offset, 4))[1] ?? 0xFFFFFFFF);
		if ($value === 0xFFFFFFFF) {
			return -1;
		}

		return $value;

	}//end directoryId()

	/**
	 * Walk the red-black sibling tree of one storage.
	 *
	 * @param int $entryId The node to visit.
	 * @param array<int,int> $collected The ids collected so far, by reference.
	 * @param array<int,bool> $seen Guard against a cyclic tree.
	 *
	 * @return void
	 */
	private function collectSiblings(int $entryId, array &$collected, array $seen): void {
		if ($entryId < 0 || isset($this->entries[$entryId]) === false || isset($seen[$entryId]) === true) {
			return;
		}

		$seen[$entryId] = true;
		$collected[] = $entryId;
		$this->collectSiblings(entryId: (int)$this->entries[$entryId]['left'], collected: $collected, seen: $seen);
		$this->collectSiblings(entryId: (int)$this->entries[$entryId]['right'], collected: $collected, seen: $seen);

	}//end collectSiblings()

	/**
	 * The mini stream, read once from the root entry.
	 *
	 * @return string The mini stream bytes.
	 */
	private function readMiniStream(): string {
		if ($this->miniStream === null) {
			$root = $this->entries[0];
			$this->miniStream = $this->readChain(
				start: (int)$root['start'],
				size: (int)$root['size'],
				table: $this->fat,
				sectorSize: $this->sectorSize,
				container: null
			);
		}

		return $this->miniStream;

	}//end readMiniStream()

	/**
	 * Read a little-endian uint16 out of the raw file.
	 *
	 * @param int $offset The byte offset.
	 *
	 * @return int The value.
	 */
	private function uint16(int $offset): int {
		$unpacked = unpack('v', substr($this->raw, $offset, 2));
		return (int)($unpacked[1] ?? 0);

	}//end uint16()

	/**
	 * Read a little-endian uint32 out of the raw file.
	 *
	 * @param int $offset The byte offset.
	 *
	 * @return int The value.
	 */
	private function uint32(int $offset): int {
		$unpacked = unpack('V', substr($this->raw, $offset, 4));
		return (int)($unpacked[1] ?? 0xFFFFFFFF);

	}//end uint32()

}//end class
