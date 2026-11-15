<?php

/**
 * Integriq StufNamespaces.
 *
 * Finds the namespace a StUF message uses for a sector or the SOAP envelope
 * by a hint (`zkn`, `stuf`, `bg`, `soap`): the first entry, in map order,
 * whose prefix or URI contains the hint. The StUF-BG and StUF-ZKN services
 * read their messages through it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Stuf
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
 * @spec openspec/changes/stuf-adapter/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Stuf;

/**
 * Resolves a StUF namespace URI by hint.
 *
 * @spec openspec/changes/stuf-adapter/tasks.md#task-8
 */
final class StufNamespaces {

	/**
	 * Resolve a namespace URI from the namespace map by hint.
	 *
	 * @param array  $namespaces The namespace map (prefix => URI).
	 * @param string $hint       Lowercase hint string (e.g. 'zkn', 'stuf', 'soap').
	 *
	 * @return string The namespace URI, or empty string if not found.
	 *
	 * @spec openspec/changes/stuf-adapter/tasks.md#task-8
	 */
	public static function byHint(array $namespaces, string $hint): string {
		foreach ($namespaces as $prefix => $uri) {
			if (str_contains(haystack: strtolower($prefix), needle: $hint) === true) {
				return (string)$uri;
			}

			if (str_contains(haystack: strtolower($uri), needle: $hint) === true) {
				return (string)$uri;
			}
		}

		return '';
	}//end byHint()
}//end class
