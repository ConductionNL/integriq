<?php

/**
 * Integriq MessageParser.
 *
 * The one door every uploaded message goes through. It sniffs the bytes
 * rather than trusting the extension, hands them to the `.eml` or `.msg`
 * reader, and when neither can read them keeps the file whole as a single
 * attachment with a warning. A message is never dropped for being unreadable.
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

use Throwable;

/**
 * Parses an uploaded mail file into a {@see ParsedMessage}.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class MessageParser {

	/**
	 * Constructor.
	 *
	 * @param EmlParser $emlParser Reads RFC 5322 messages.
	 * @param MsgParser $msgParser Reads Outlook compound files.
	 */
	public function __construct(
		private readonly EmlParser $emlParser,
		private readonly MsgParser $msgParser,
	) {

	}//end __construct()

	/**
	 * Parse one uploaded file.
	 *
	 * @param string $filename The uploaded file name, used only for the fallback attachment.
	 * @param string $raw The file bytes.
	 *
	 * @return ParsedMessage The parsed message, carrying a warning when the parse fell back.
	 *
	 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
	 */
	public function parse(string $filename, string $raw): ParsedMessage {
		try {
			if (CompoundFileReader::isCompoundFile($raw) === true) {
				return $this->msgParser->parse($raw);
			}

			return $this->emlParser->parse($raw);
		} catch (Throwable $exception) {
			return $this->fallback(filename: $filename, raw: $raw, reason: $exception->getMessage());
		}

	}//end parse()

	/**
	 * Keep an unreadable file as one attachment on a bare message.
	 *
	 * @param string $filename The uploaded file name.
	 * @param string $raw The file bytes.
	 * @param string $reason Why the parse failed.
	 *
	 * @return ParsedMessage The fallback message.
	 */
	private function fallback(string $filename, string $raw, string $reason): ParsedMessage {
		$safeName = 'message';
		if (basename($filename) !== '') {
			$safeName = basename($filename);
		}

		return new ParsedMessage(
			'sha256:' . hash('sha256', $raw),
			'',
			[],
			$safeName,
			null,
			'',
			'',
			[
				[
					'name' => $safeName,
					'mime' => 'application/octet-stream',
					'size' => strlen($raw),
					'content' => $raw,
				],
			],
			'The file could not be parsed and was kept whole: ' . $reason,
		);

	}//end fallback()

}//end class
