<?php

/**
 * Integriq CaseReferenceDetector.
 *
 * Finds the case number a message names, using the pattern configured on the
 * mailbox source. Integriq only reads the reference out of the text; whether
 * it means anything is the owning app's decision.
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
 * Detects a case reference in a message's subject and body.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-received-message-is-offered-to-the-owning-app-as-a-typed-event-req-mail-003
 */
class CaseReferenceDetector {

	/**
	 * The fleet case-number shape, used when a source configures no pattern.
	 *
	 * Two to ten capitals, a four digit year and a sequence number, e.g.
	 * `ZAAK-2026-0042`.
	 *
	 * @var string
	 */
	public const DEFAULT_PATTERN = '/\b([A-Z]{2,10}-\d{4}-\d{1,8})\b/';

	/**
	 * Detect the case reference a message names.
	 *
	 * The subject wins over the body, because a reply keeps the reference in
	 * the subject while the body may quote several older ones.
	 *
	 * @param ParsedMessage $message The parsed message.
	 * @param string|null $pattern The source's `casePattern`, or null for the default.
	 *
	 * @return string|null The reference, or null when the message names none.
	 *
	 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
	 */
	public function detect(ParsedMessage $message, ?string $pattern = null): ?string {
		$effective = $this->resolvePattern(pattern: $pattern);
		if ($effective === null) {
			return null;
		}

		return $this->scan(haystacks: [$message->getSubject(), $message->getBodyText()], pattern: $effective);

	}//end detect()

	/**
	 * Detect the case reference a piece of text names.
	 *
	 * The same detector every channel reads, so a Teams message and a mail
	 * find the same reference under the same configured pattern. A channel
	 * that carries no subject passes its text alone.
	 *
	 * @param string $text The text to read.
	 * @param string|null $pattern The source's `casePattern`, or null for the default.
	 *
	 * @return string|null The reference, or null when the text names none.
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function detectInText(string $text, ?string $pattern = null): ?string {
		$effective = $this->resolvePattern(pattern: $pattern);
		if ($effective === null) {
			return null;
		}

		return $this->scan(haystacks: [$text], pattern: $effective);

	}//end detectInText()

	/**
	 * Read the first reference out of a list of haystacks, in order.
	 *
	 * @param array<int,string> $haystacks The strings to read, most authoritative first.
	 * @param string $pattern The compiled-and-checked pattern.
	 *
	 * @return string|null The reference, or null.
	 */
	private function scan(array $haystacks, string $pattern): ?string {
		foreach ($haystacks as $haystack) {
			if ($haystack === '') {
				continue;
			}

			$matched = @preg_match($pattern, $haystack, $matches);
			if ($matched !== 1) {
				continue;
			}

			$reference = ($matches[1] ?? $matches[0]);
			if (trim((string)$reference) !== '') {
				return trim((string)$reference);
			}
		}

		return null;

	}//end scan()

	/**
	 * Whether a configured pattern is a usable regular expression.
	 *
	 * @param string $pattern The candidate pattern.
	 *
	 * @return bool True when the pattern compiles.
	 */
	public function isUsable(string $pattern): bool {
		return $this->resolvePattern(pattern: $pattern) !== null;

	}//end isUsable()

	/**
	 * Resolve the pattern to use, refusing one that does not compile.
	 *
	 * A pattern that does not compile is not silently replaced by the default:
	 * detecting with the wrong pattern links mail to the wrong case, so the
	 * detector answers "no reference" and the message is offered unreferenced.
	 *
	 * @param string|null $pattern The configured pattern.
	 *
	 * @return string|null The usable pattern, or null.
	 */
	private function resolvePattern(?string $pattern): ?string {
		if ($pattern === null || trim($pattern) === '') {
			return self::DEFAULT_PATTERN;
		}

		$candidate = trim($pattern);
		if (@preg_match($candidate, '') === false) {
			return null;
		}

		return $candidate;

	}//end resolvePattern()

}//end class
