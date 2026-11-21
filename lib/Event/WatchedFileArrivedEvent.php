<?php

/**
 * Integriq Watched File Arrived Event: a watched folder hands one file to a named intake app.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched for a synchronization with `target: intake`. The consuming app
 * (filinq's scan intake, for one) takes the file in from its own listener and
 * says so with accept(), naming itself and the reference of what it made. The
 * public surface is a contract: filinq's contract test asserts the same
 * names and types, so a rename on either side fails a test.
 *
 * Nextcloud's event dispatcher carries no caller identity, so the app id is
 * how a listener states which app accepts. It keeps a listener of another
 * app, or one that skipped its own getIntakeApp() check, from claiming the
 * file by mistake; it is not a security boundary against code in the same
 * process.
 *
 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
 */
class WatchedFileArrivedEvent extends Event {

	/**
	 * The reference the accepting app gave, or null while nobody accepted.
	 *
	 * @var string|null
	 */
	private ?string $reference = null;

	/**
	 * Constructor.
	 *
	 * @param string $synchronizationId The synchronization that watches the folder.
	 * @param string $sourceId          The `nextcloud-folder` source it reads.
	 * @param string $intakeApp         The app id that may accept the file.
	 * @param int    $fileId            The file's id.
	 * @param string $path              The file's path, relative to the owner's user folder.
	 * @param string $ownerUid          The owner of the watched folder.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function __construct(
		private readonly string $synchronizationId,
		private readonly string $sourceId,
		private readonly string $intakeApp,
		private readonly int $fileId,
		private readonly string $path,
		private readonly string $ownerUid,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The synchronization that watches the folder.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function getSynchronizationId(): string {
		return $this->synchronizationId;
	}//end getSynchronizationId()

	/**
	 * The `nextcloud-folder` source the synchronization reads.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function getSourceId(): string {
		return $this->sourceId;
	}//end getSourceId()

	/**
	 * The app id that may accept the file.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function getIntakeApp(): string {
		return $this->intakeApp;
	}//end getIntakeApp()

	/**
	 * The file's id.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function getFileId(): int {
		return $this->fileId;
	}//end getFileId()

	/**
	 * The file's path, relative to the owner's user folder.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function getPath(): string {
		return $this->path;
	}//end getPath()

	/**
	 * The owner of the watched folder.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function getOwnerUid(): string {
		return $this->ownerUid;
	}//end getOwnerUid()

	/**
	 * Accept the file: answers true only for the named intake app, with a reference, the first time.
	 *
	 * @param string $appId     The accepting app's id.
	 * @param string $reference What the app made of the file, such as a batch id.
	 *
	 * @return bool Whether the acceptance was recorded.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function accept(string $appId, string $reference): bool {
		$reference = trim($reference);
		if ($appId !== $this->intakeApp || $reference === '' || $this->reference !== null) {
			return false;
		}

		$this->reference = $reference;
		return true;
	}//end accept()

	/**
	 * The outcome: `accepted`, `intakeApp` and `reference`.
	 *
	 * @return array{accepted: bool, intakeApp: string, reference: string|null}
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function getResult(): array {
		return ['accepted' => $this->reference !== null, 'intakeApp' => $this->intakeApp, 'reference' => $this->reference];
	}//end getResult()
}//end class
