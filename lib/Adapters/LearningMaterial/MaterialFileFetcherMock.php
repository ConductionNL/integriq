<?php

/**
 * Integriq learning material file fetcher (mock).
 *
 * Answers every file with the same small recorded bytes: no network access, ever.
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
 * Recorded file fetcher.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
 */
final class MaterialFileFetcherMock implements MaterialFileFetcher {
	/**
	 * The bytes every file answers with.
	 */
	public const CONTENT = "%PDF-1.4\n% Integriq recorded learning material file\n%%EOF\n";

	/**
	 * Answer the recorded bytes, typed as the record names the file.
	 *
	 * @param string $url    The file's https address.
	 * @param string $format The file's format as the record names it.
	 *
	 * @return array{content: string, mimeType: string} The bytes and their media type.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function fetch(string $url, string $format): array {
		unset($url);
		if ($format === '') {
			$format = 'application/pdf';
		}

		return ['content' => self::CONTENT, 'mimeType' => $format];
	}//end fetch()
}//end class
