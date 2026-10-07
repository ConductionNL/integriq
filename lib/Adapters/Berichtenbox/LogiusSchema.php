<?php

/**
 * Integriq — validates a document against a vendored Logius schema.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
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

namespace OCA\Integriq\Adapters\Berichtenbox;

use DOMDocument;

/**
 * Nextcloud installs an external entity loader that refuses every entity, to
 * close XXE. That loader also refuses the schema file itself and its imports,
 * so a plain `schemaValidate()` fails inside Nextcloud while it passes in a
 * unit test (found on bbx-live, 2026-10-08). This class lets libxml read files
 * from the vendored Logius directory only, for the one validation, and puts
 * the previous loader back afterwards. Anything else, a URL or a path outside
 * that directory, still resolves to nothing.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010
 */
final class LogiusSchema {
	/**
	 * The vendored contract directory.
	 *
	 * @return string The real path.
	 */
	public static function directory(): string {
		return (string)realpath(__DIR__ . '/Logius');
	}//end directory()

	/**
	 * Validate a document against a schema in the vendored directory.
	 *
	 * @param string $xml The document.
	 * @param string $schema The schema path, relative to the vendored directory.
	 *
	 * @return array<int,string> The errors, empty when the document is valid.
	 */
	public static function errors(string $xml, string $schema): array {
		$directory = self::directory();
		$previousLoader = libxml_get_external_entity_loader();
		$previousErrors = libxml_use_internal_errors(true);
		libxml_set_external_entity_loader(
			static function (?string $public, ?string $system, array $context) use ($directory) {
				unset($public, $context);
				$path = realpath((string)$system);
				if ($path !== false && str_starts_with($path, $directory . DIRECTORY_SEPARATOR) === true) {
					return $path;
				}

				return null;
			}
		);

		try {
			$document = new DOMDocument();
			if ($xml === '' || $document->loadXML($xml, LIBXML_NONET) === false) {
				$errors = ['The document is not XML.'];
			} else {
				$valid = $document->schemaValidate($directory . '/' . $schema, LIBXML_NONET);
				$errors = array_map(static fn ($error) => trim($error->message), libxml_get_errors());
				if ($valid === true) {
					$errors = [];
				} elseif ($errors === []) {
					$errors = ['The document does not validate.'];
				}
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previousErrors);
			libxml_set_external_entity_loader($previousLoader);
		}

		return $errors;
	}//end errors()
}//end class
