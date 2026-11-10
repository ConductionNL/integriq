<?php

/**
 * Integriq MIME collection.
 *
 * What EmlParser collects while it walks one message's MIME parts: the
 * plain-text body, the HTML body and the attachments, in part order. It
 * never leaves EmlParser.
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
 * The bodies and attachments collected from one message.
 *
 * @spec openspec/specs/mail-intake/spec.md
 */
final class MimeCollection {

	/**
	 * The plain-text body parts, concatenated.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * The HTML body parts, concatenated.
	 *
	 * @var string
	 */
	public string $html = '';

	/**
	 * The attachments: `{name, mime, size, content}`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $attachments = [];
}//end class
