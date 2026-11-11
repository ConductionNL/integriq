<?php

/**
 * Integriq IntakePayloadReader: reading the values every intake channel payload
 * shares — an optional timestamp as text, a value that must be a map, and a
 * list of base64-encoded files.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake\Adapter
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake\Adapter;

/**
 * Shared payload reads of the intake channel adapters.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */
final class IntakePayloadReader {
	/**
	 * A payload value as text, keeping a missing value missing.
	 *
	 * @param mixed $value The value, or null.
	 *
	 * @return string|null The text, or null.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
	 */
	public static function optionalString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end optionalString()

	/**
	 * A payload value when it is a map, an empty map otherwise.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string,mixed> The map.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
	 */
	public static function mapOrEmpty(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end mapOrEmpty()

	/**
	 * Decode a payload's list of base64 files, skipping entries that are not maps.
	 *
	 * @param mixed            $items    The payload's file list.
	 * @param IntakeFileNaming $defaults The name and type a file without them gets.
	 *
	 * @return array<int,array<string,mixed>> Name, type, size and content per file.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
	 */
	public static function decodeFiles(mixed $items, IntakeFileNaming $defaults): array {
		$files = [];
		foreach (($items ?? []) as $index => $file) {
			if (is_array($file) === false) {
				continue;
			}

			$content = (string)base64_decode((string)($file['contentBase64'] ?? ''), false);
			$files[] = [
				'name' => (string)($file['name'] ?? sprintf($defaults->nameFormat, ((int)$index + 1))),
				'mime' => (string)($file['mime'] ?? $defaults->mime),
				'size' => strlen($content),
				'content' => $content,
			];
		}

		return $files;
	}//end decodeFiles()
}//end class
