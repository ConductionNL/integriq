<?php

/**
 * Integriq VerzuimloketAcknowledgementReceived Event.
 *
 * Dispatched whenever a DUO Verzuimloket retour/acknowledgement is received
 * and translated, so a sibling app (learniq's `leerplicht` job owner) can
 * react without integriq knowing learniq's own schema. Dispatched on every
 * acknowledgement, accepted or rejected (mirrors
 * RodAcknowledgementReceivedEvent / DigitalPostDeliveredEvent).
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
 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-003-duo-acknowledgement-translation-to-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * A DUO Verzuimloket acknowledgement, translated and ready for a listener to act on.
 *
 * The kenmerk, signaalcode, description and acceptance live in {@see AcknowledgementReceivedEvent}.
 *
 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-003-duo-acknowledgement-translation-to-a-typed-event
 */
class VerzuimloketAcknowledgementReceivedEvent extends AcknowledgementReceivedEvent {
	/**
	 * Constructor.
	 *
	 * @param string $kenmerk The correlation id echoed back from the original outbound send.
	 * @param string $signaalcode The DUO signaalcode (`0` means accepted).
	 * @param string|null $signaalOmschrijving The DUO signal description, when supplied.
	 * @param bool $accepted Whether the signaalcode indicates acceptance.
	 * @param string $meldingType The melding kind the acknowledgement responds to, when known.
	 */
	public function __construct(
		string $kenmerk,
		string $signaalcode,
		?string $signaalOmschrijving,
		bool $accepted,
		private readonly string $meldingType = '',
	) {
		parent::__construct(kenmerk: $kenmerk, signaalcode: $signaalcode, signaalOmschrijving: $signaalOmschrijving, accepted: $accepted);
	}//end __construct()

	/**
	 * The melding kind the acknowledgement responds to, when known.
	 *
	 * @return string Melding kind, empty string when unresolved.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-003-duo-acknowledgement-translation-to-a-typed-event
	 */
	public function getMeldingType(): string {
		return $this->meldingType;
	}//end getMeldingType()
}//end class
