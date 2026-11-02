<?php

/**
 * Contract of the BerichtenboxLetterBuilder.
 *
 * @category Contract
 * @package  OCA\Integriq\Adapters\Berichtenbox
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

namespace OCA\Integriq\Adapters\Berichtenbox;

use DateTimeImmutable;

/**
 * Public operations of {@see BerichtenboxLetterBuilder} that other components call.
 */
interface LetterBuilderInterface {
	/**
	 * Build and check one letter.
	 *
	 * @param array<string,mixed> $message The digital post message: recipient, subject, body, attachments, caseRef.
	 * @param string $senderOin The sender's OIN.
	 * @param string $berichtType The BerichtType code.
	 * @param DateTimeImmutable|null $now The creation time, for tests.
	 * @param string|null $batchId A BatchID to reuse, for tests and for a resend.
	 * @param string|null $berichtId A BerichtID to reuse.
	 *
	 * @return BerichtenboxBatch The batch.
	 *
	 * @throws BerichtenboxException When the letter breaks a rule.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on BerichtenboxLetterBuilder::build()
	 */
	public function build(
		array $message,
		string $senderOin,
		string $berichtType,
		?DateTimeImmutable $now = null,
		?string $batchId = null,
		?string $berichtId = null,
	): BerichtenboxBatch;

	/**
	 * Check a batch document against the vendored schema.
	 *
	 * @param string $xml The document.
	 *
	 * @return void
	 *
	 * @throws BerichtenboxException When it does not validate, naming what failed.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on BerichtenboxLetterBuilder::assertValid()
	 */
	public function assertValid(string $xml): void;
}
