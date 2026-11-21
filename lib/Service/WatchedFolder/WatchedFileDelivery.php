<?php

/**
 * Delivers one watched file to its synchronization's target: a draft through the mapping, or an intake app.
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

use OCP\Files\File;
use RuntimeException;

/**
 * `target: mapping` (the default) writes the file as a draft object;
 * `target: intake` hands it to the named app. Either way the answer is what
 * the file became, which the contract records; null means no intake app
 * accepted it, and the file is left as it is.
 *
 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
 */
class WatchedFileDelivery {

	/**
	 * Constructor.
	 *
	 * @param WatchedFileWriter   $writer   Writes the draft object with the file attached.
	 * @param WatchedFileHandOver $handOver Hands the file to an intake app.
	 */
	public function __construct(
		private readonly WatchedFileWriter $writer,
		private readonly WatchedFileHandOver $handOver,
	) {
	}//end __construct()

	/**
	 * Deliver the file.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 * @param File          $file    The file, stable and not yet in a contract.
	 *
	 * @return string|null The saved object's uuid or the intake app's reference; null when no intake app accepted.
	 *
	 * @throws RuntimeException When a mapping target names no register and schema.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005
	 */
	public function deliver(WatchedFolder $watched, File $file): ?string {
		if ($watched->intakeApp() !== null) {
			return $this->handOver->handOver(watched: $watched, file: $file);
		}

		$target = $watched->target();
		if ($target === null) {
			throw new RuntimeException('The synchronization names no target register and schema.');
		}

		return $this->writer->write(watched: $watched, file: $file, target: $target);
	}//end deliver()
}//end class
