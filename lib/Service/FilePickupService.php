<?php

/**
 * Picks new files up from a partner's SFTP or FTPS server.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use InvalidArgumentException;
use OCA\Integriq\Service\Adapter\DataInfra\AbstractFileTransferAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\FtpsAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\SftpAdapter;
use OCA\Integriq\Util\SafeXmlParser;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The pickup synchronization (design D4, REQ-SFTP-002).
 *
 * A synchronization with `sourceType` `sftp` or `ftps` carries `sourceConfig`
 * `{path, pattern, after: move|delete|keep, archivePath, target:
 * files|mapping, filesFolder, filesOwner, stableSeconds}`. Each new file (by
 * path and modification time, remembered as a synchronization contract) is
 * fetched, written locally (into a Nextcloud folder, or as objects through the
 * mapping), and only then moved to the archive folder or deleted on the
 * server. A file whose local write fails stays where it was.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The pickup joins adapters, contracts, files and mappings by design.
 */
class FilePickupService {

	/**
	 * Seconds a file must sit unchanged before it is picked up.
	 *
	 * @var int
	 */
	public const DEFAULT_STABLE_SECONDS = 60;

	/**
	 * Fields a pickup never lets through: arriving is not publishing.
	 *
	 * @var array<int,string>
	 */
	private const NEVER_SET = ['published', 'publicatiedatum'];

	/**
	 * Constructor.
	 *
	 * @param SftpAdapter                   $sftpAdapter   The SFTP adapter.
	 * @param FtpsAdapter                   $ftpsAdapter   The FTPS adapter.
	 * @param SynchronizationContractService $contracts    Remembers what was fetched.
	 * @param IRootFolder                   $rootFolder    Nextcloud Files.
	 * @param MappingService                $mappingService Runs the synchronization's mapping.
	 * @param ORObjectService               $objectService Loads the mapping, saves objects.
	 * @param LoggerInterface               $logger        Logger.
	 */
	public function __construct(
		private readonly SftpAdapter $sftpAdapter,
		private readonly FtpsAdapter $ftpsAdapter,
		private readonly SynchronizationContractService $contracts,
		private readonly IRootFolder $rootFolder,
		private readonly MappingService $mappingService,
		private readonly ORObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether a synchronization is a file pickup.
	 *
	 * @param array<string,mixed> $synchronization The synchronization.
	 *
	 * @return bool True for `sftp` and `ftps`.
	 */
	public static function handles(array $synchronization): bool {
		return in_array(($synchronization['sourceType'] ?? null), ['sftp', 'ftps'], true);

	}//end handles()

	/**
	 * Run one pickup.
	 *
	 * @param array<string,mixed> $synchronization The synchronization.
	 * @param array<string,mixed> $source          The source.
	 * @param bool                $isTest          A test run lists what it would fetch and writes nothing.
	 * @param int|null            $now             The current time, for tests.
	 *
	 * @return array<string,mixed> `pickup` counts and `objects` counts.
	 */
	public function run(array $synchronization, array $source, bool $isTest = false, ?int $now = null): array {
		$config = (array)($synchronization['sourceConfig'] ?? []);
		$adapter = $this->adapterFor(protocol: (string)$synchronization['sourceType']);
		$now = ($now ?? time());
		$stableSeconds = (int)($config['stableSeconds'] ?? self::DEFAULT_STABLE_SECONDS);
		$synchronizationId = (string)($synchronization['id'] ?? ($synchronization['uuid'] ?? ''));
		$sourceId = (string)($source['id'] ?? ($source['uuid'] ?? ($synchronization['sourceId'] ?? '')));

		$report = ['listed' => 0, 'fetched' => 0, 'stored' => 0, 'archived' => 0, 'skipped' => 0, 'wouldFetch' => [], 'failed' => []];
		$objects = ['found' => 0, 'created' => 0];

		$files = $adapter->listFiles(
			source: $source,
			path: (string)($config['path'] ?? '.'),
			pattern: (string)($config['pattern'] ?? '*')
		);
		$report['listed'] = count($files);

		foreach ($files as $file) {
			$originId = $adapter->protocol() . '-file:' . $sourceId . ':' . $file['path'] . ':' . $file['mtime'];
			if (($now - $file['mtime']) < $stableSeconds
				|| $this->contracts->findBySyncAndOrigin(synchronizationId: $synchronizationId, originId: $originId) !== null
			) {
				$report['skipped']++;
				continue;
			}

			if ($isTest === true) {
				$report['wouldFetch'][] = $file['path'];
				continue;
			}

			$this->pickOne(
				adapter: $adapter,
				synchronization: $synchronization,
				source: $source,
				file: $file,
				originId: $originId,
				report: $report,
				objects: $objects
			);
		}//end foreach

		return ['pickup' => $report, 'objects' => $objects];

	}//end run()

	/**
	 * Fetch, write locally, remember, then archive one file.
	 *
	 * @param AbstractFileTransferAdapter              $adapter         The adapter.
	 * @param array<string,mixed>                      $synchronization The synchronization.
	 * @param array<string,mixed>                      $source          The source.
	 * @param array{name:string,size:int,mtime:int,path:string} $file   The remote file.
	 * @param string                                   $originId        The contract key.
	 * @param array<string,mixed>                      $report          Pickup counts, updated.
	 * @param array<string,int>                        $objects         Object counts, updated.
	 *
	 * @return void
	 */
	private function pickOne(
		AbstractFileTransferAdapter $adapter,
		array $synchronization,
		array $source,
		array $file,
		string $originId,
		array &$report,
		array &$objects,
	): void {
		$config = (array)($synchronization['sourceConfig'] ?? []);
		$local = null;
		try {
			$local = $adapter->fetch(source: $source, path: $file['path']);
			$report['fetched']++;

			if (($config['target'] ?? 'files') === 'mapping') {
				$created = $this->writeObjects(synchronization: $synchronization, file: $file, localPath: $local);
				$objects['found'] += $created;
				$objects['created'] += $created;
			} else {
				$this->writeToFiles(config: $config, name: $file['name'], localPath: $local);
			}

			$report['stored']++;
		} catch (Throwable $exception) {
			// The local write did not happen, so the remote file stays put.
			$report['failed'][] = ['file' => $file['path'], 'reason' => $exception->getMessage()];
			$this->logger->warning('integriq file pickup: ' . $file['path'] . ' not picked up: ' . $exception->getMessage());
			return;
		} finally {
			if ($local !== null) {
				@unlink($local);
			}
		}//end try

		$this->contracts->createFromArray(
			object: [
				'synchronizationId' => (string)($synchronization['id'] ?? ($synchronization['uuid'] ?? '')),
				'originId' => $originId,
				'sourceLastSynced' => date(DATE_ATOM),
			]
		);

		try {
			$this->archive(adapter: $adapter, source: $source, config: $config, file: $file);
			$report['archived'] += (int)(($config['after'] ?? 'move') !== 'keep');
		} catch (Throwable $exception) {
			$report['failed'][] = ['file' => $file['path'], 'reason' => 'Stored, but not archived: ' . $exception->getMessage()];
		}

	}//end pickOne()

	/**
	 * Move, delete or keep the remote file once it is safe locally.
	 *
	 * @param AbstractFileTransferAdapter $adapter The adapter.
	 * @param array<string,mixed>         $source  The source.
	 * @param array<string,mixed>         $config  The pickup configuration.
	 * @param array<string,mixed>         $file    The remote file.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When `move` has no archive path.
	 */
	private function archive(AbstractFileTransferAdapter $adapter, array $source, array $config, array $file): void {
		$after = (string)($config['after'] ?? 'move');
		if ($after === 'keep') {
			return;
		}

		if ($after === 'delete') {
			$adapter->deleteFile(source: $source, path: $file['path']);
			return;
		}

		$archivePath = (string)($config['archivePath'] ?? '');
		if ($archivePath === '') {
			throw new InvalidArgumentException(message: 'The pickup moves files but names no archive path.');
		}

		$adapter->move(source: $source, from: $file['path'], to: rtrim($archivePath, '/') . '/' . $file['name']);

	}//end archive()

	/**
	 * Write the fetched file into the owner's Nextcloud folder.
	 *
	 * @param array<string,mixed> $config    The pickup configuration.
	 * @param string              $name      The file name.
	 * @param string              $localPath The fetched file.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no owner or folder is configured.
	 * @throws RuntimeException When the file cannot be read back.
	 */
	private function writeToFiles(array $config, string $name, string $localPath): void {
		$owner = (string)($config['filesOwner'] ?? '');
		$folderPath = trim((string)($config['filesFolder'] ?? ''), '/');
		if ($owner === '' || $folderPath === '') {
			throw new InvalidArgumentException(message: 'The pickup stores files but names no filesOwner or filesFolder.');
		}

		$folder = $this->rootFolder->getUserFolder($owner);
		foreach (explode('/', $folderPath) as $segment) {
			if ($folder->nodeExists($segment) === false) {
				$folder = $folder->newFolder($segment);
				continue;
			}

			$node = $folder->get($segment);
			if (($node instanceof Folder) === false) {
				throw new RuntimeException(message: 'The files folder path runs through a file: ' . $segment);
			}

			$folder = $node;
		}

		$handle = fopen($localPath, 'rb');
		if ($handle === false) {
			throw new RuntimeException(message: 'The fetched file could not be read back.');
		}

		try {
			$folder->newFile($this->freeName(folder: $folder, name: $name), $handle);
		} finally {
			if (is_resource($handle) === true) {
				fclose($handle);
			}
		}

	}//end writeToFiles()

	/**
	 * A name not yet taken in the folder: `name (2).ext` after `name.ext`.
	 *
	 * @param Folder $folder The folder.
	 * @param string $name   The wanted name.
	 *
	 * @return string A free name.
	 */
	private function freeName(Folder $folder, string $name): string {
		$candidate = $name;
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$base = pathinfo($name, PATHINFO_FILENAME);
		for ($counter = 2; $folder->nodeExists($candidate) === true; $counter++) {
			$candidate = $base . ' (' . $counter . ')' . ($extension === '' ? '' : '.' . $extension);
		}

		return $candidate;

	}//end freeName()

	/**
	 * Parse the file, map each record and save it as an object.
	 *
	 * @param array<string,mixed> $synchronization The synchronization (`targetId` register/schema, `sourceTargetMapping`).
	 * @param array<string,mixed> $file            The remote file.
	 * @param string              $localPath       The fetched file.
	 *
	 * @return int The number of objects saved.
	 *
	 * @throws InvalidArgumentException When the target or the format is unknown.
	 */
	private function writeObjects(array $synchronization, array $file, string $localPath): int {
		[$register, $schema] = array_pad(explode('/', (string)($synchronization['targetId'] ?? '')), 2, '');
		if ($register === '' || $schema === '') {
			throw new InvalidArgumentException(message: 'The pickup maps files but the synchronization names no target register/schema.');
		}

		$mapping = null;
		if (empty($synchronization['sourceTargetMapping']) === false) {
			$mapping = $this->objectService->find(id: (string)$synchronization['sourceTargetMapping'], register: 'integriq', schema: 'mapping');
		}

		$saved = 0;
		foreach (self::parseRecords(name: $file['name'], content: (string)file_get_contents($localPath)) as $record) {
			$object = $record;
			if ($mapping !== null) {
				$object = $this->mappingService->executeMapping(mapping: $mapping, input: $record);
			}

			foreach (self::NEVER_SET as $field) {
				unset($object[$field]);
			}

			$this->objectService->saveObject(object: $object, register: $register, schema: $schema);
			$saved++;
		}

		return $saved;

	}//end writeObjects()

	/**
	 * Records from a CSV, JSON or XML file.
	 *
	 * @param string $name    The file name; its extension picks the format.
	 * @param string $content The content.
	 *
	 * @return array<int,array<string,mixed>> The records.
	 *
	 * @throws InvalidArgumentException When the format is not CSV, JSON or XML, or does not parse.
	 */
	public static function parseRecords(string $name, string $content): array {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($extension === 'csv') {
			$lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $content), static fn (string $line): bool => trim($line) !== ''));
			if ($lines === []) {
				return [];
			}

			$delimiter = (substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',');
			$headers = str_getcsv(array_shift($lines), $delimiter, '"', '\\');
			$records = [];
			foreach ($lines as $line) {
				$values = str_getcsv($line, $delimiter, '"', '\\');
				$records[] = array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), null));
			}

			return $records;
		}

		if ($extension === 'json') {
			$decoded = json_decode($content, true);
			if (is_array($decoded) === false) {
				throw new InvalidArgumentException(message: 'The JSON file does not parse.');
			}

			return (array_is_list($decoded) === true ? $decoded : [$decoded]);
		}

		if ($extension === 'xml') {
			$document = SafeXmlParser::parse($content);
			if ($document === false) {
				throw new InvalidArgumentException(message: 'The XML file does not parse.');
			}

			return [(array)json_decode((string)json_encode($document), true)];
		}

		throw new InvalidArgumentException(message: 'A mapped pickup reads CSV, JSON or XML, not "' . $extension . '".');

	}//end parseRecords()

	/**
	 * The adapter for a protocol.
	 *
	 * @param string $protocol `sftp` or `ftps`.
	 *
	 * @return AbstractFileTransferAdapter The adapter.
	 */
	private function adapterFor(string $protocol): AbstractFileTransferAdapter {
		if ($protocol === 'ftps') {
			return $this->ftpsAdapter;
		}

		return $this->sftpAdapter;

	}//end adapterFor()
}//end class
