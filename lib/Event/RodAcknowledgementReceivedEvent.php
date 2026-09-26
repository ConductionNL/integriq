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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * A DUO ROD acknowledgement, translated and ready for a listener to act on.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */
class RodAcknowledgementReceivedEvent extends Event {
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
		private readonly string $kenmerk,
		private readonly string $signaalcode,
		private readonly ?string $signaalOmschrijving,
		private readonly bool $accepted,
		private readonly string $berichtsoort = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The correlation id echoed back from the original outbound send.
	 *
	 * @return string Kenmerk.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
	 */
	public function getKenmerk(): string {
		return $this->kenmerk;
	}//end getKenmerk()

	/**
	 * The DUO signaalcode (`0` means accepted).
	 *
	 * @return string Signaalcode.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
	 */
	public function getSignaalcode(): string {
		return $this->signaalcode;
	}//end getSignaalcode()

	/**
	 * The DUO signal description, when supplied.
	 *
	 * @return string|null Description, or null when absent.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
	 */
	public function getSignaalOmschrijving(): ?string {
		return $this->signaalOmschrijving;
	}//end getSignaalOmschrijving()

	/**
	 * Whether the signaalcode indicates acceptance.
	 *
	 * @return bool True when accepted.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
	 */
	public function isAccepted(): bool {
		return $this->accepted;
	}//end isAccepted()

	/**
	 * The berichtsoort the acknowledgement responds to, when known.
	 *
	 * @return string Berichtsoort, empty string when unresolved.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
	 */
	public function getBerichtsoort(): string {
		return $this->berichtsoort;
	}//end getBerichtsoort()
}//end class
