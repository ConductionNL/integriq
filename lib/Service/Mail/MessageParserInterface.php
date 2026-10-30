<?php

/**
 * Contract of the MessageParser.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

/**
 * Public operations of {@see MessageParser} that other components call.
 */
interface MessageParserInterface {
	/**
	 * Parse one uploaded file.
	 *
	 * @param string $filename The uploaded file name, used only for the fallback attachment.
	 * @param string $raw The file bytes.
	 *
	 * @return ParsedMessage The parsed message, carrying a warning when the parse fell back.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function parse(string $filename, string $raw): ParsedMessage;
}
