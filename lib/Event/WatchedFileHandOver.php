<?php

/**
 * Hands one watched file to the intake app its synchronization names.
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

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;

/**
 * Target `intake` of design D6: the file is announced as a
 * WatchedFileArrivedEvent, and only the app the synchronization names may
 * accept it. What that app makes of it (filinq's scan batch, for one) is its
 * own; integriq keeps the reference it answered. It sits beside the event,
 * and takes the synchronization and its source as they are stored.
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
	 * @param array<string, mixed> $synchronization The synchronization, with `target: intake`.
	 * @param array<string, mixed> $source          Its `nextcloud-folder` source.
	 * @param File                 $file            The file.
	 *
	 * @return string|null The accepting app's reference, or null when nobody accepted.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function handOver(array $synchronization, array $source, File $file): ?string {
		$intakeApp = trim((string)(($synchronization['sourceConfig'] ?? [])['intakeApp'] ?? ''));
		if ($intakeApp === '') {
			// No app is named, so none may accept; announcing it would let accept('') through.
			return null;
		}

		$owner = trim((string)(($source['configuration'] ?? [])['ownerUid'] ?? ''));
		$event = new WatchedFileArrivedEvent(
			synchronizationId: (string)($synchronization['id'] ?? $synchronization['uuid'] ?? ''),
			sourceId: (string)($source['id'] ?? $source['uuid'] ?? $synchronization['sourceId'] ?? ''),
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
