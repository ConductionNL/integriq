<?php

/**
 * Integriq Berichtenbox letter status lookup.
 *
 * One status question about a sent Berichtenbox letter: its BerichtID as
 * the caller holds it, and the source configuration the answers are read
 * with. Logius and the ebMS adapter key the letter by the lower-cased id.
 *
 * @category Provider
 * @package  OCA\Integriq\Service\DigitalPost
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
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

/**
 * Look up the status of one Berichtenbox letter.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
 */
final class LetterStatusLookup {

	/**
	 * Constructor.
	 *
	 * @param string              $providerReference The BerichtID, as the caller holds it.
	 * @param array<string,mixed> $config            The source configuration.
	 */
	public function __construct(
		public readonly string $providerReference,
		public readonly array $config,
	) {

	}//end __construct()

	/**
	 * The BerichtID as results and transport events are keyed: lower-cased.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
	 */
	public function reference(): string {
		return strtolower($this->providerReference);

	}//end reference()
}//end class
