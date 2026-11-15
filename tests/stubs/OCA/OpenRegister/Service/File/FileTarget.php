<?php

/**
 * Stub for OCA\OpenRegister\Service\File\FileTarget.
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
 * The object a file is added to and, optionally, its register.
 */
final class FileTarget {
	/**
	 * @param ObjectEntity|string $object The object or its id.
	 * @param int|string|null $registerId The register, when the object does not name it.
	 */
	public function __construct(
		public readonly ObjectEntity|string $object,
		public readonly int|string|null $registerId = null,
	) {
	}
}
