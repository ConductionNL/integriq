<?php

/**
 * Integriq StufZdsCaseRequested Event.
 *
 * The ADR-041 cross-app command for StUF-ZDS case messages: a sibling app
 * (dossiq) names a StUF-ZKN source, an operation and its arguments, and
 * integriq's ZDS case client sends it. The listener fills the result slot in
 * the same request. The asking app MUST guard the dispatch with
 * class_exists() and treat an unhandled event as a message that was not sent.
 *
 * Operations and their arguments:
 * - `creeerZaak`: case (omschrijving, startdatum, betrokkenen[], documenten[]),
 *   zaaktypeOmschrijving, includeDocuments, payloadLimitBytes
 * - `actualiseerZaak`: caseIdentification, changes
 * - `geefZaakdetails`: caseIdentification, wantedElements[]
 * - `genereerZaakIdentificatie`: none
 * - `vrijBericht`: name, payload
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "send this StUF-ZDS case message on my behalf".
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
 */
class StufZdsCaseRequestedEvent extends Event {

	/**
	 * Whether integriq handled the request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The client's result, once handled.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Why the request was refused before anything was sent, if it was.
	 *
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string               $sourceApp     The asking app id.
	 * @param string               $sourceId      The integriq StUF-ZKN source.
	 * @param string               $operation     The operation (see the class docblock).
	 * @param array<string, mixed> $arguments     The operation's arguments.
	 * @param string               $correlationId Echoed on the StufZdsCaseConcludedEvent.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $sourceId,
		private readonly string $operation,
		private readonly array $arguments,
		private readonly string $correlationId,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The asking app.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The StUF-ZKN source.
	 *
	 * @return string The source id.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getSourceId(): string {
		return $this->sourceId;
	}//end getSourceId()

	/**
	 * The operation.
	 *
	 * @return string The operation.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getOperation(): string {
		return $this->operation;
	}//end getOperation()

	/**
	 * The arguments.
	 *
	 * @return array<string, mixed> The arguments.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getArguments(): array {
		return $this->arguments;
	}//end getArguments()

	/**
	 * The correlation id.
	 *
	 * @return string The correlation id.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Record the client's result.
	 *
	 * @param array<string, mixed> $result success, retrying, referentienummer, messageId, caseIdentification, fout (and case for geefZaakdetails).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function setResult(array $result): void {
		$this->handled = true;
		$this->result  = $result;
	}//end setResult()

	/**
	 * Record a refusal: nothing was sent.
	 *
	 * @param string $reason Why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function refuse(string $reason): void {
		$this->handled = true;
		$this->refusal = $reason;
	}//end refuse()

	/**
	 * Whether integriq handled the request.
	 *
	 * @return bool True when a listener ran.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The result, or null when refused or unhandled.
	 *
	 * @return array<string, mixed>|null The result.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * The refusal, or null.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function getRefusal(): ?string {
		return $this->refusal;
	}//end getRefusal()
}//end class
