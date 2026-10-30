<?php

/**
 * One file to fetch and the object it is stored on.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

/**
 * The endpoint of one file and how it is stored: the object it attaches to,
 * its filename, tags, published state and register.
 *
 * @spec openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
 */
final class FileFetchItem {

	/**
	 * Holds the values of one file to fetch.
	 *
	 * @param string          $endpoint   The endpoint of the file.
	 * @param string          $objectId   The object the file is stored on.
	 * @param string|null     $filename   The filename to give the file, when known.
	 * @param array           $tags       The tags to give the file.
	 * @param string|null     $published  The published state of the file, when given.
	 * @param int|string|null $registerId The register of the object, when given.
	 */
	public function __construct(
		public readonly string $endpoint,
		public readonly string $objectId,
		public readonly ?string $filename = null,
		public readonly array $tags = [],
		public readonly ?string $published = null,
		public readonly int|string|null $registerId = null,
	) {
	}//end __construct()
}//end class
