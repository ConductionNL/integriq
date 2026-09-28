<?php

/**
 * Integriq DUO ROD persoonsgebonden nummer redactor.
 *
 * A BSN or onderwijsnummer must never reach a log line, an exception message
 * or an error stored on a job or message record. Provider and transport
 * messages are not under the adapter's control (a DUO fault or an HTTP client
 * error can echo the request), so every such message passes through here
 * before it is logged, thrown or stored.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-003-the-persoonsgebonden-nummer-never-reaches-a-log-or-a-stored-error
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

/**
 * Removes a persoonsgebonden nummer from free text.
 *
 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-003-the-persoonsgebonden-nummer-never-reaches-a-log-or-a-stored-error
 */
class RodPersonalNumberRedactor {

	/**
	 * The text that replaces a redacted number.
	 *
	 * @var string
	 */
	public const MASK = '[persoonsgebonden nummer]';

	/**
	 * Remove the known number and any standalone run of nine digits.
	 *
	 * The known number is removed first, wherever it appears. The nine-digit
	 * sweep then catches a number the caller did not know, such as one a DUO
	 * fault echoes back.
	 *
	 * @param string      $text   The free text.
	 * @param string|null $number The number the caller knows, if any.
	 *
	 * @return string The text without any persoonsgebonden nummer.
	 *
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-003-the-persoonsgebonden-nummer-never-reaches-a-log-or-a-stored-error
	 */
	public function redact(string $text, ?string $number=null): string {
		if ($number !== null && $number !== '') {
			$text = str_replace($number, self::MASK, $text);
		}

		return (string) preg_replace('/(?<!\d)\d{9}(?!\d)/', self::MASK, $text);
	}//end redact()
}//end class
