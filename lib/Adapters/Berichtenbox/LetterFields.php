<?php

/**
 * Integriq — the checked fields of one Berichtenbox letter.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Berichtenbox;

/**
 * What one letter is written from: the batch identity, the sender and the
 * checked recipient, subject and text.
 *
 * Built by {@see BerichtenboxLetterBuilder::build()} and never handed out.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010
 */
final class LetterFields {

	/**
	 * The BatchID GUID.
	 *
	 * @var string
	 */
	public string $batchId = '';

	/**
	 * The BerichtID GUID.
	 *
	 * @var string
	 */
	public string $berichtId = '';

	/**
	 * The creation time, in UTC, as the schema writes it.
	 *
	 * @var string
	 */
	public string $created = '';

	/**
	 * The sender's OIN.
	 *
	 * @var string
	 */
	public string $senderOin = '';

	/**
	 * The BerichtType code.
	 *
	 * @var string
	 */
	public string $berichtType = '';

	/**
	 * The recipient's BSN.
	 *
	 * @var string
	 */
	public string $bsn = '';

	/**
	 * The subject.
	 *
	 * @var string
	 */
	public string $subject = '';

	/**
	 * The letter text, line breaks written out.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * The case reference shown to the citizen, or empty.
	 *
	 * @var string
	 */
	public string $reference = '';

	/**
	 * Start the fields of a letter from its sender and type.
	 *
	 * @param string $senderOin   The sender's OIN.
	 * @param string $berichtType The BerichtType code.
	 */
	public function __construct(string $senderOin, string $berichtType) {
		$this->senderOin = $senderOin;
		$this->berichtType = $berichtType;
	}//end __construct()
}//end class
