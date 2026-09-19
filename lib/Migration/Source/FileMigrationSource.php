<?php

/**
 * A delivered file, read through a stored column mapping.
 *
 * @category Adapter
 * @package  OCA\Integriq\Migration\Source
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

namespace OCA\Integriq\Migration\Source;

use InvalidArgumentException;
use OCA\Integriq\Migration\ColumnMapping;
use OCA\Integriq\Migration\ColumnMappingValidator;
use OCA\Integriq\Migration\MigrationRecord;
use OCA\Integriq\Migration\MigrationSourceAdapterInterface;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The columns are mapped once and the mapping is stored, so the second
 * delivery of the same shape is a selection rather than the same morning
 * again. The file is read and nothing is written to it.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
class FileMigrationSource implements MigrationSourceAdapterInterface {
	/**
	 * The source id a migration names.
	 */
	public const ID = 'file';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Nextcloud's root folder, for the delivered file.
	 * @param ColumnMappingValidator $validator The mapping validator.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ColumnMappingValidator $validator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The source id.
	 *
	 * @return string Source id.
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * What a delivered file can yield. The kind comes from the mapping, so
	 * this adapter declares the shape rather than a fixed list of kinds.
	 *
	 * @return array{id:string,label:string,kinds:array<int,array{kind:string,label:string,identifier:?string,stableIdentifier:bool}>}
	 */
	public function describe(): array {
		return [
			'id' => self::ID,
			'label' => 'Delivered file',
			'kinds' => [
				[
					'kind' => 'row',
					'label' => 'One row of the delivered file',
					'identifier' => null,
					// A file only has a stable key when the mapping names a
					// column for it, so the honest default here is no.
					'stableIdentifier' => false,
				],
			],
		];
	}//end describe()

	/**
	 * How many rows the delivered file holds.
	 *
	 * @param string $kind The record kind, which a file source ignores.
	 * @param array<string,mixed> $config Carries `path` and `mapping`.
	 *
	 * @return array{count:int,complete:bool} The count and its completeness.
	 */
	public function count(string $kind, array $config = []): array {
		try {
			$rows = $this->parse($this->readFile($config), $config);
		} catch (InvalidArgumentException $e) {
			return ['count' => 0, 'complete' => false];
		}

		return ['count' => count($rows), 'complete' => true];
	}//end count()

	/**
	 * Every row of the delivered file, mapped onto target fields.
	 *
	 * @param string $kind The record kind, which a file source ignores.
	 * @param array<string,mixed> $config Carries `path` and `mapping`.
	 *
	 * @return iterable<MigrationRecord> The records.
	 *
	 * @throws InvalidArgumentException When the mapping cannot read this file.
	 */
	public function read(string $kind, array $config = []): iterable {
		$mapping = $this->mapping($config);
		$rows = $this->parse($this->readFile($config), $config);
		$readAt = time();
		$identifierColumn = $mapping->getIdentifierColumn();

		$records = [];
		foreach ($rows as $row) {
			$foreignId = null;
			if ($identifierColumn !== '' && ($row[$identifierColumn] ?? '') !== '') {
				$foreignId = (string)$row[$identifierColumn];
			}

			$kind = $mapping->getKind();
			if ($kind === '') {
				$kind = 'row';
			}

			$records[] = new MigrationRecord(
				$kind,
				$mapping->apply($row),
				self::ID,
				$foreignId,
				$readAt
			);
		}

		return $records;
	}//end read()

	/**
	 * A bounded sample of the delivered file.
	 *
	 * @param string $kind The record kind.
	 * @param array<string,mixed> $config Carries `path` and `mapping`.
	 * @param int $limit How many rows at most.
	 *
	 * @return array<int,MigrationRecord> The sample.
	 */
	public function sample(string $kind, array $config = [], int $limit = 5): array {
		return array_slice((array)$this->read($kind, $config), 0, max(0, $limit));
	}//end sample()

	/**
	 * Refuse a run that cannot finish, before a row is read.
	 *
	 * @param array<string,mixed> $config Carries `path` and `mapping`.
	 * @param array<int,string> $schemaFields Every field the target schema has.
	 * @param array<int,string> $requiredFields The schema's required fields.
	 *
	 * @return array<int,string> The refusals, empty when the run may start.
	 */
	public function preflight(array $config, array $schemaFields, array $requiredFields): array {
		$mapping = $this->mapping($config);

		$refusals = array_merge(
			$this->validator->validateTargets($mapping, $schemaFields),
			$this->validator->validateRequired($mapping, $requiredFields)
		);

		$content = ($config['content'] ?? null);
		if (is_string($content) === true) {
			$headers = $this->headers($content, $config);
			$refusals = array_merge($refusals, $this->validator->validateColumns($mapping, $headers));
		}

		return $refusals;
	}//end preflight()

	/**
	 * Parse a delimited file into rows keyed by column name.
	 *
	 * @param string $content The file's contents.
	 * @param array<string,mixed> $config Carries an optional `delimiter`.
	 *
	 * @return array<int,array<string,string>> The rows.
	 */
	public function parse(string $content, array $config = []): array {
		$delimiter = (string)($config['delimiter'] ?? ',');
		$lines = preg_split('/\R/', trim($content));
		if ($lines === false || $lines === [] || $lines === ['']) {
			return [];
		}

		$headers = str_getcsv(array_shift($lines), $delimiter);
		$rows = [];
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}

			$values = str_getcsv($line, $delimiter);
			$row = [];
			foreach ($headers as $index => $header) {
				$row[(string)$header] = (string)($values[$index] ?? '');
			}

			$rows[] = $row;
		}

		return $rows;
	}//end parse()

	/**
	 * The delivered file's column headers.
	 *
	 * @param string $content The file's contents.
	 * @param array<string,mixed> $config Carries an optional `delimiter`.
	 *
	 * @return array<int,string> The headers.
	 */
	public function headers(string $content, array $config = []): array {
		$delimiter = (string)($config['delimiter'] ?? ',');
		$lines = preg_split('/\R/', trim($content));
		if ($lines === false || $lines === []) {
			return [];
		}

		return array_map(static fn ($header): string => (string)$header, str_getcsv((string)$lines[0], $delimiter));
	}//end headers()

	/**
	 * The mapping a migration declared.
	 *
	 * @param array<string,mixed> $config The migration's configuration.
	 *
	 * @return ColumnMapping The mapping.
	 *
	 * @throws InvalidArgumentException When no usable mapping was declared.
	 */
	private function mapping(array $config): ColumnMapping {
		$stored = ($config['mapping'] ?? null);
		if (is_array($stored) === false) {
			throw new InvalidArgumentException('A file migration needs a stored column mapping before it can read anything.');
		}

		return ColumnMapping::fromArray($stored);
	}//end mapping()

	/**
	 * The delivered file's contents.
	 *
	 * @param array<string,mixed> $config Carries `content` or `path`.
	 *
	 * @return string The contents.
	 *
	 * @throws InvalidArgumentException When the file cannot be read.
	 */
	private function readFile(array $config): string {
		$content = ($config['content'] ?? null);
		if (is_string($content) === true) {
			return $content;
		}

		$path = (string)($config['path'] ?? '');
		if ($path === '') {
			throw new InvalidArgumentException('A file migration needs a path to the delivered file.');
		}

		try {
			$node = $this->rootFolder->get($path);

			// `IRootFolder::get()` answers a Node, and only a File carries
			// getContent(). A path that resolves to a folder used to reach
			// getContent() anyway and land in the Throwable arm below, which
			// reports "could not be read" — true but unhelpful, since the real
			// answer is that the path is a directory.
			if ($node instanceof File === false) {
				throw new InvalidArgumentException(
					sprintf('The delivered file "%s" is a folder, not a file.', $path)
				);
			}

			$read = $node->getContent();
		} catch (NotFoundException $e) {
			throw new InvalidArgumentException(sprintf('The delivered file "%s" is not there.', $path));
		} catch (InvalidArgumentException $e) {
			// Ours, thrown just above: it already says precisely what is wrong.
			// Without this arm the Throwable catch below swallows it and reports
			// the generic "could not be read" instead.
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('migration-source.file.unreadable', ['path' => $path, 'error' => $e->getMessage()]);
			throw new InvalidArgumentException(sprintf('The delivered file "%s" could not be read.', $path));
		}

		return (is_string($read) === true ? $read : '');
	}//end readFile()
}//end class
