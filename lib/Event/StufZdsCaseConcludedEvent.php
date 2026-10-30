<?php

/**
 * Integriq StufZdsCaseConcluded Event.
 *
 * Announces how a StUF-ZDS case kennisgeving ended (creeerZaak,
 * actualiseerZaak, vrijBericht): confirmed by the case system, or failed for
 * good after its retries. The asking app matches it on `correlationId` and
 * `sourceApp`; a retried message concludes in a cron run, long after the
 * request returned `retrying: true`.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * The terminal outcome of one StUF-ZDS case kennisgeving.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
 */
class StufZdsCaseConcludedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string               $sourceApp     The app that asked for the message.
	 * @param string               $correlationId The asking app's correlation id.
	 * @param string               $operation     creeerZaak, actualiseerZaak or vrijBericht.
	 * @param int                  $attempts      How many attempts it took.
	 * @param array<string, mixed> $result        success, referentienummer, messageId, caseIdentification, fout.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $correlationId,
		private readonly string $operation,
		private readonly int $attempts,
		private readonly array $result,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that asked.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The asking app's correlation id.
	 *
	 * @return string The correlation id.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * The operation.
	 *
	 * @return string The operation.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getOperation(): string {
		return $this->operation;
	}//end getOperation()

	/**
	 * How many attempts it took.
	 *
	 * @return int The attempts.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getAttempts(): int {
		return $this->attempts;
	}//end getAttempts()

	/**
	 * The outcome.
	 *
	 * @return array<string, mixed> success, referentienummer, messageId, caseIdentification, fout.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getResult(): array {
		return $this->result;
	}//end getResult()
}//end class
