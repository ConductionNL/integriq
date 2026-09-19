<?php

/**
 * Integriq HtmlSanitizer.
 *
 * A mail body is attacker-supplied text that later renders on a case page in
 * another app. This strips the parts that execute: script and style blocks,
 * event handler attributes, and `javascript:` targets.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mail
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
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

/**
 * Removes executable constructs from an HTML mail body.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
final class HtmlSanitizer {

	/**
	 * Elements whose content never survives, including the content itself.
	 *
	 * @var array<int,string>
	 */
	private const STRIPPED_ELEMENTS = ['script', 'style', 'iframe', 'object', 'embed', 'form'];

	/**
	 * Sanitize one HTML body.
	 *
	 * @param string $html The raw HTML body.
	 *
	 * @return string The sanitized body.
	 */
	public static function sanitize(string $html): string {
		if (trim($html) === '') {
			return '';
		}

		$clean = $html;
		foreach (self::STRIPPED_ELEMENTS as $element) {
			$clean = (string)preg_replace(
				'#<' . $element . '\b[^>]*>.*?</' . $element . '\s*>#is',
				'',
				$clean
			);
			$clean = (string)preg_replace('#<' . $element . '\b[^>]*/?>#i', '', $clean);
		}

		// Event handler attributes, quoted and unquoted.
		$clean = (string)preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $clean);
		$clean = (string)preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $clean);
		$clean = (string)preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#i', '', $clean);

		// Script targets in href/src/action.
		$clean = (string)preg_replace(
			'#(href|src|action)\s*=\s*(["\']?)\s*(javascript|vbscript|data)\s*:[^"\'>\s]*\2#i',
			'$1="#"',
			$clean
		);

		return $clean;

	}//end sanitize()

}//end class
