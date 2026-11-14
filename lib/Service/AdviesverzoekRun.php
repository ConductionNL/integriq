<?php

/**
 * Adviesverzoek Run
 *
 * The state of one DSO-SWF adviesverzoek: what is sent, where, and the id
 * its result reports.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One adviesverzoek send; built and used by DSOSamenwerkingService only.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
 */
final class AdviesverzoekRun {

	/**
	 * The id the result and the log lines report: the payload's, else a fresh one.
	 *
	 * @var mixed
	 */
	public readonly mixed $adviesverzoekId;

	/**
	 * Constructor.
	 *
	 * @param string              $uri     The DSO-SWF adviesverzoeken endpoint.
	 * @param array<string,mixed> $payload The adviesverzoek payload.
	 */
	public function __construct(
		public readonly string $uri,
		public readonly array $payload,
	) {
		$this->adviesverzoekId = ($payload['adviesverzoekId'] ?? uniqid(prefix: 'avz-', more_entropy: true));

	}//end __construct()

	/**
	 * The OIN of the partner organisation consulted.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	public function partnerOin(): mixed {
		return $this->payload['partnerOin'];

	}//end partnerOin()

	/**
	 * The zaak the advice is asked for, or null.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-10
	 */
	public function caseId(): mixed {
		return $this->payload['caseId'];

	}//end caseId()
}//end class
