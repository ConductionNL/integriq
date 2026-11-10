<?php

/**
 * Integriq UploadPlan: the files a multipart upload writes to and how many
 * parts it expects.
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
 * @spec openspec/specs/storage-uploads/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * One multipart upload being set up: its target file, the folder its parts
 * land in, the number of parts and the object it belongs to.
 *
 * @spec openspec/specs/storage-uploads/spec.md
 */
final class UploadPlan {
	/**
	 * The number of parts the upload expects.
	 *
	 * @var float
	 */
	public float $numParts = 0;

	/**
	 * The OpenRegister object the upload belongs to, if any.
	 *
	 * @var string|null
	 */
	public ?string $objectId = null;

	/**
	 * Constructor.
	 *
	 * @param File   $target      The (empty) target file.
	 * @param Folder $partsFolder The folder the parts are written to.
	 */
	public function __construct(
		public readonly File $target,
		public readonly Folder $partsFolder,
	) {

	}//end __construct()
}//end class
