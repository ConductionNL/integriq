<?php

/**
 * Writes one watched file into its target register as a draft, with the file attached.
 *
 * @category Service
 * @package  OCA\Integriq\Service\WatchedFolder
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
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\WatchedFolder;

use OCA\Integriq\Service\MappingService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Target `mapping` of design D6: the file is described as a source object,
 * the synchronization's mapping turns it into the target object, the keys
 * that would publish it are removed, the object is saved, and the file is
 * attached to it. A watched folder never publishes: an officer publishes the
 * draft after checking it.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
 */
class WatchedFileWriter {

	/**
	 * The keys removed from every mapped object, because each publishes it.
	 *
	 * @var list<string>
	 */
	public const PUBLISHING_KEYS = ['published', 'publicatiedatum'];

	/**
	 * The mime types whose text is handed to the mapping as `content`.
	 *
	 * @var list<string>
	 */
	private const TEXT_TYPES = ['text/csv', 'application/json', 'application/xml', 'text/xml'];

	/**
	 * Constructor.
	 *
	 * @param MappingService  $mappings The synchronization's mapping.
	 * @param ObjectService   $objects  OpenRegister, where the object is saved.
	 * @param FileService     $files    OpenRegister, where the file is attached.
	 * @param LoggerInterface $logger   Names each removed key.
	 */
	public function __construct(
		private readonly MappingService $mappings,
		private readonly ObjectService $objects,
		private readonly FileService $files,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write one file as a draft object with the file attached.
	 *
	 * @param WatchedFolder                          $watched The watched folder.
	 * @param File                                   $file    The file.
	 * @param array{register: string, schema: string} $target  The target register and schema.
	 *
	 * @return string The saved object's uuid.
	 *
	 * @throws RuntimeException When the save gives no object to attach the file to.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
	 */
	public function write(WatchedFolder $watched, File $file, array $target): string {
		$object = $this->draft(watched: $watched, input: $this->describe(watched: $watched, file: $file));

		$saved = $this->objects->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $target['register'], schema: $target['schema'])),
			_rbac: false,
			_multitenancy: false
		);
		$uuid = (string)$saved->getUuid();
		if ($uuid === '') {
			throw new RuntimeException('The target register saved no object for "' . $file->getName() . '".');
		}

		$this->files->addFile(
			target: new FileTarget(object: $uuid),
			upload: new FileUpload(fileName: $file->getName(), content: $file->getContent(), share: false)
		);

		return $uuid;
	}//end write()

	/**
	 * The mapped object with the publishing keys removed, each removal logged.
	 *
	 * @param WatchedFolder        $watched The watched folder.
	 * @param array<string, mixed> $input   The file as a source object.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
	 */
	public function draft(WatchedFolder $watched, array $input): array {
		$object = $input;
		if ($watched->mappingId() !== '') {
			$object = $this->mappings->executeMapping(mapping: $watched->mappingId(), input: $input);
		}

		foreach (self::PUBLISHING_KEYS as $key) {
			if (array_key_exists($key, $object) === true) {
				unset($object[$key]);
				$this->logger->info(
					'integriq: watched folder removed "' . $key . '" from the object for "' . (string)($input['name'] ?? '') . '"; it is saved as a draft.',
					['synchronization' => $watched->synchronizationId()]
				);
			}
		}

		return $object;
	}//end draft()

	/**
	 * The file as the mapping's source object: `{fileId, name, path, mimetype, size, mtime, owner}`,
	 * plus `content` for a CSV, JSON or XML file.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 * @param File          $file    The file.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(WatchedFolder $watched, File $file): array {
		$input = [
			'fileId'   => (int)$file->getId(),
			'name'     => $file->getName(),
			'path'     => $file->getPath(),
			'mimetype' => $file->getMimetype(),
			'size'     => (int)$file->getSize(),
			'mtime'    => (int)$file->getMTime(),
			'owner'    => (string)(($watched->source['configuration'] ?? [])['ownerUid'] ?? ''),
		];
		if (in_array($input['mimetype'], self::TEXT_TYPES, true) === true) {
			$input['content'] = $file->getContent();
		}

		return $input;
	}//end describe()
}//end class
