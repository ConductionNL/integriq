<?php

/**
 * Integriq learning material file fetcher.
 *
 * Fetches the bytes of one file a source record names, so a consuming app can keep a copy without the browser fetching it.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Fetches one file of a source record.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
 */
interface MaterialFileFetcher {
	/**
	 * The largest file a copy may be, in bytes.
	 */
	public const MAX_BYTES = 26214400;

	/**
	 * Fetch one file.
	 *
	 * @param string $url    The file's https address.
	 * @param string $format The file's format as the record names it.
	 *
	 * @return array{content: string, mimeType: string} The bytes and their media type.
	 *
	 * @throws SourceUnavailableException When the file could not be fetched.
	 * @throws LearningMaterialRefusedException When the file is larger than MAX_BYTES.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function fetch(string $url, string $format): array;
}//end interface
