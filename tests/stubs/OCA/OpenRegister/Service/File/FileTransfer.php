<?php

/**
 * Stub for OCA\OpenRegister\Service\File\FileTransfer.
 *
 * Same constructor as the OpenRegister value object, so a test builds and
 * reads it the way production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A file copied from one object to another.
 */
final class FileTransfer {
	/**
	 * @param ObjectEntity $source The object holding the file.
	 * @param int $fileId The file id.
	 * @param ObjectEntity $target The object receiving the copy.
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly int $fileId,
		public readonly ObjectEntity $target,
	) {
	}
}
