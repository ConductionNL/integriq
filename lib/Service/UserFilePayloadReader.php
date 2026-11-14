<?php

/**
 * Integriq User File Payload Reader.
 *
 * Reads the document an outbound request names by reference into a user's
 * Nextcloud Files. Two forms are accepted: `nextcloud-file:<id>`, a file id,
 * and an absolute path `/<userId>/files/...`. Only a file inside a user's
 * `files/` tree is read, checked on the node it resolves to as well as on the
 * reference, so a reference cannot reach app data or another storage root.
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
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads a non-empty file from a user's Nextcloud Files by id or path.
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
 */
class UserFilePayloadReader {
	/**
	 * Prefix of a reference that names a file by its id.
	 *
	 * @var string
	 */
	public const FILE_ID_PREFIX = 'nextcloud-file:';

	/**
	 * An absolute path inside a user's Nextcloud Files.
	 *
	 * @var string
	 */
	private const USER_FILES_PATH = '#^/[^/]+/files/.+#';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder     $rootFolder The root of every user's files.
	 * @param LoggerInterface $logger     Logger for the cause of an unreadable reference.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The content of the file a reference names.
	 *
	 * @param string $reference `nextcloud-file:<id>` or `/<userId>/files/...`.
	 *
	 * @return array{content:string,name:string} The content and the file's name.
	 *
	 * @throws RuntimeException When the reference does not name a readable, non-empty file in a user's files.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
	 */
	public function read(string $reference): array {
		$content = null;
		$name = '';
		try {
			$node = $this->resolve(reference: $reference);
			if ($node instanceof File && preg_match(self::USER_FILES_PATH, $node->getPath()) === 1) {
				$content = $node->getContent();
				$name = $node->getName();
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[UserFilePayloadReader] could not read the referenced file',
				['reference' => $reference, 'exception' => $exception->getMessage()]
			);
		}

		if (is_string($content) === false || $content === '') {
			throw new RuntimeException(message: 'payload not readable: ' . $reference);
		}

		return ['content' => $content, 'name' => $name];
	}//end read()

	/**
	 * The node a reference names, or null when it has neither accepted form.
	 *
	 * @param string $reference The reference.
	 *
	 * @return Node|null
	 *
	 * @throws \OCP\Files\NotFoundException When a path names nothing.
	 */
	private function resolve(string $reference): ?Node {
		if (str_starts_with($reference, self::FILE_ID_PREFIX) === true) {
			$fileId = substr($reference, strlen(self::FILE_ID_PREFIX));
			if (ctype_digit($fileId) === false) {
				return null;
			}

			return $this->rootFolder->getFirstNodeById(id: (int)$fileId);
		}

		if (preg_match(self::USER_FILES_PATH, $reference) === 1) {
			return $this->rootFolder->get(path: $reference);
		}

		return null;
	}//end resolve()
}//end class
