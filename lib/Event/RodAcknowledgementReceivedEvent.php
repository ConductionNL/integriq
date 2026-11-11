<?php

/**
 * Integriq RodAcknowledgementReceived Event.
 *
 * Dispatched whenever a DUO ROD retour/acknowledgement is received and
 * translated, so a sibling app (learniq's `bron-rod` job owner) can react
 * without integriq knowing learniq's own schema — e.g. feeding a
 * signaalcode rejection into learniq's `ExchangeRejectionDetail` worklist.
 * Dispatched on every acknowledgement, accepted or rejected: a listener
 * that only reacts to a rejection still needs to see the accepted ones go
 * by to know nothing was missed (mirrors DigitalPostDeliveredEvent).
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * A DUO ROD acknowledgement, translated and ready for a listener to act on.
 *
 * The kenmerk, signaalcode, description and acceptance live in {@see AcknowledgementReceivedEvent}.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */
class RodAcknowledgementReceivedEvent extends AcknowledgementReceivedEvent {
	/**
	 * Constructor.
	 *
	 * @param string $kenmerk The correlation id echoed back from the original outbound send.
	 * @param string $signaalcode The DUO signaalcode (`0` means accepted).
	 * @param string|null $signaalOmschrijving The DUO signal description, when supplied.
	 * @param bool $accepted Whether the signaalcode indicates acceptance.
	 * @param string $berichtsoort The berichtsoort the acknowledgement responds to, when known.
	 */
	public function __construct(
		string $kenmerk,
		string $signaalcode,
		?string $signaalOmschrijving,
		bool $accepted,
		private readonly string $berichtsoort = '',
	) {
		parent::__construct(kenmerk: $kenmerk, signaalcode: $signaalcode, signaalOmschrijving: $signaalOmschrijving, accepted: $accepted);
	}//end __construct()

	/**
	 * The berichtsoort the acknowledgement responds to, when known.
	 *
	 * @return string Berichtsoort, empty string when unresolved.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
	 */
	public function getBerichtsoort(): string {
		return $this->berichtsoort;
	}//end getBerichtsoort()
}//end class
