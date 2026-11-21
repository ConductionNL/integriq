<?php

/**
 * Hands one watched file to the intake app its synchronization names.
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
 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\WatchedFolder;

use OCA\Integriq\Event\WatchedFileArrivedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;

/**
 * Target `intake` of design D6: the file is announced as a
 * WatchedFileArrivedEvent, and only the app the synchronization names may
 * accept it. What that app makes of it (filinq's scan batch, for one) is its
 * own; integriq keeps the reference it answered.
 *
 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
 */
class WatchedFileHandOver {

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches the event to the intake app's listener.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * Announce the file and answer the reference of the app that accepted it.
	 *
	 * @param WatchedFolder $watched The watched folder, with `target: intake`.
	 * @param File          $file    The file.
	 *
	 * @return string|null The accepting app's reference, or null when nobody accepted.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function handOver(WatchedFolder $watched, File $file): ?string {
		$intakeApp = (string)$watched->intakeApp();
		if ($intakeApp === '') {
			// No app is named, so none may accept; announcing it would let accept('') through.
			return null;
		}

		$owner = $watched->ownerUid();
		$event = new WatchedFileArrivedEvent(
			synchronizationId: $watched->synchronizationId(),
			sourceId: (string)($watched->source['id'] ?? $watched->source['uuid'] ?? $watched->synchronization['sourceId'] ?? ''),
			intakeApp: $intakeApp,
			fileId: (int)$file->getId(),
			path: $this->relativePath(absolute: $file->getPath(), owner: $owner),
			ownerUid: $owner
		);
		$this->dispatcher->dispatchTyped($event);

		return $event->getResult()['reference'];
	}//end handOver()

	/**
	 * A node path relative to the owner's user folder: `/alice/files/Scans/in.pdf` is `Scans/in.pdf`.
	 *
	 * @param string $absolute The node's path.
	 * @param string $owner    The owner.
	 *
	 * @return string
	 */
	private function relativePath(string $absolute, string $owner): string {
		$prefix = '/' . $owner . '/files/';
		if ($owner !== '' && str_starts_with($absolute, $prefix) === true) {
			return substr($absolute, strlen($prefix));
		}

		return ltrim($absolute, '/');
	}//end relativePath()
}//end class
