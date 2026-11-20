<?php

/**
 * Stub for OCA\OpenRegister\Service\File\FileUpload.
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

/**
 * A file to write: name, content, share toggle and tags.
 */
final class FileUpload {
	/**
	 * @param string $fileName The file name.
	 * @param mixed $content The bytes or a readable stream.
	 * @param bool $share Whether to create a share link.
	 * @param array $tags The tags to attach.
	 */
	public function __construct(
		public readonly string $fileName,
		public readonly mixed $content,
		public readonly bool $share,
		public readonly array $tags = [],
	) {
	}
}
