<?php

/**
 * Integriq HeldMessageFiles.
 *
 * A held message is routed again later, by hand, once a rule exists for it.
 * Its stored object carries only the metadata of its attachments and media,
 * so the bytes are kept as files on the held `intake_message` object and read
 * back when it is routed again. A message routed on arrival stores nothing
 * here: its bytes went with the hand-off.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
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
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps a held message's attachment and media bytes, and reads them back.
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
class HeldMessageFiles {

	/**
	 * The stored-object keys that list files, the two kinds a message carries.
	 *
	 * @var array<int,string>
	 */
	private const FILE_LISTS = ['attachments', 'media'];

	/**
	 * The tag a kept file carries, so it reads as intake evidence.
	 *
	 * @var string
	 */
	public const TAG = 'intake-held';

	/**
	 * Constructor.
	 *
	 * @param FileService     $fileService Stores and reads object files.
	 * @param LoggerInterface $logger      Records a file that could not be kept or read.
	 */
	public function __construct(
		private readonly FileService $fileService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Store each file of a message on its held object, once.
	 *
	 * The payload's file entries gain the `fileId` of the kept file. An entry
	 * that already names one (a message held again after a later route) is
	 * left alone, so a file is never stored twice. A file that cannot be
	 * stored keeps its metadata without a `fileId`: the message is still
	 * held, and the review shows what arrived.
	 *
	 * @param string              $uuid    The held `intake_message` uuid.
	 * @param InboundMessage      $message The message, bytes and all.
	 * @param array<string,mixed> $payload The object payload about to be saved.
	 *
	 * @return array<string,mixed> The payload with the kept files' ids.
	 *
	 * @spec openspec/changes/messaging-intake-routing-rules/tasks.md#task-4-keep-a-held-messages-files
	 */
	public function keep(string $uuid, InboundMessage $message, array $payload): array {
		$sources = ['attachments' => $message->getAttachments(), 'media' => $message->getMedia()];
		foreach (self::FILE_LISTS as $list) {
			$entries = $payload[$list] ?? [];
			if (is_array($entries) === false) {
				continue;
			}

			foreach ($sources[$list] as $index => $file) {
				$entry = $entries[$index] ?? [];
				if (is_array($entry) === false || isset($entry['fileId']) === true) {
					continue;
				}

				$fileId = $this->store(uuid: $uuid, file: $file);
				if ($fileId !== null) {
					$entries[$index] = array_merge($entry, ['fileId' => $fileId]);
				}
			}

			$payload[$list] = $entries;
		}//end foreach

		return $payload;

	}//end keep()

	/**
	 * Read the kept files of a stored message back, with their bytes.
	 *
	 * An entry without a `fileId`, or whose file is gone, is handed on as
	 * metadata only, as it was before this change.
	 *
	 * @param string              $uuid   The `intake_message` uuid.
	 * @param array<string,mixed> $object The stored message.
	 *
	 * @return array{attachments: array<int,array<string,mixed>>, media: array<int,array<string,mixed>>} The files.
	 *
	 * @spec openspec/changes/messaging-intake-routing-rules/tasks.md#task-5-route-a-held-message-again
	 */
	public function restore(string $uuid, array $object): array {
		$restored = ['attachments' => [], 'media' => []];
		foreach (self::FILE_LISTS as $list) {
			$entries = $object[$list] ?? [];
			if (is_array($entries) === false) {
				continue;
			}

			foreach (array_values($entries) as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				$restored[$list][] = $this->withContent(uuid: $uuid, entry: $entry);
			}
		}

		return $restored;

	}//end restore()

	/**
	 * Store one file on the held object.
	 *
	 * @param string              $uuid The held message uuid.
	 * @param array<string,mixed> $file The file, with its `content`.
	 *
	 * @return int|null The stored file id, or null when nothing was stored.
	 */
	private function store(string $uuid, array $file): ?int {
		$content = (string)($file['content'] ?? '');
		if ($content === '') {
			return null;
		}

		try {
			$stored = $this->fileService->addFile(
				target: new FileTarget(object: $uuid),
				upload: new FileUpload(
					fileName: (string)($file['name'] ?? 'attachment'),
					content: $content,
					share: false,
					tags: [self::TAG]
				)
			);

			return (int)$stored->getId();
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Integriq intake: a held message file could not be kept.',
				['message' => $uuid, 'reason' => $exception->getMessage()]
			);
		}

		return null;

	}//end store()

	/**
	 * One stored entry with its bytes read back, when it has a kept file.
	 *
	 * @param string              $uuid  The message uuid.
	 * @param array<string,mixed> $entry The stored file entry.
	 *
	 * @return array<string,mixed> The entry, with `content` when it was read.
	 */
	private function withContent(string $uuid, array $entry): array {
		if (isset($entry['fileId']) === false) {
			return $entry;
		}

		try {
			$file = $this->fileService->getFile(object: $uuid, file: (int)$entry['fileId']);
			if ($file !== null) {
				$entry['content'] = $file->getContent();
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Integriq intake: a held message file could not be read back.',
				['message' => $uuid, 'reason' => $exception->getMessage()]
			);
		}

		return $entry;

	}//end withContent()

}//end class
