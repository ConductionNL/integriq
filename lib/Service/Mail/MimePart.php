<?php

/**
 * Integriq MIME part.
 *
 * One MIME part as EmlParser walks it: its parsed headers and its raw body.
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
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

/**
 * One MIME part: headers and body.
 *
 * @spec openspec/specs/mail-intake/spec.md
 */
final class MimePart {

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $headers The part headers, lower-cased names.
	 * @param string               $body    The part body.
	 */
	public function __construct(
		public readonly array $headers,
		public readonly string $body,
	) {

	}//end __construct()
}//end class
