<?php

/**
 * Contract of the CaseReferenceDetector.
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
 * Public operations of {@see CaseReferenceDetector} that other components call.
 */
interface CaseReferenceDetectorInterface {
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
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public function detect(ParsedMessage $message, ?string $pattern = null): ?string;

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
	 * @spec openspec/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function detectInText(string $text, ?string $pattern = null): ?string;

	/**
	 * Whether a configured pattern is a usable regular expression.
	 *
	 * @param string $pattern The candidate pattern.
	 *
	 * @return bool True when the pattern compiles.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on CaseReferenceDetector::isUsable()
	 */
	public function isUsable(string $pattern): bool;
}
