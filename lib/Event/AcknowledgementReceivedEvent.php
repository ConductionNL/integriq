<?php

/**
 * Integriq AcknowledgementReceived Event.
 *
 * The body the ROD, Verzuimloket, OSO and UWLR/Edu-V acknowledgement events
 * share: the kenmerk echoed back from the original send, the signaalcode, its
 * description and whether it means acceptance. Each adapter dispatches its
 * own subclass, so a listener still registers per adapter.
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * An education acknowledgement, translated and ready for a listener to act on.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-003-duo-acknowledgement-translation-to-a-typed-event
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
 */
abstract class AcknowledgementReceivedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string      $kenmerk             The correlation id echoed back from the original send.
	 * @param string      $signaalcode         The signaalcode (`0` / `0000` means accepted).
	 * @param string|null $signaalOmschrijving The signal description, when supplied.
	 * @param bool        $accepted            Whether the signaalcode indicates acceptance.
	 */
	public function __construct(
		private readonly string $kenmerk,
		private readonly string $signaalcode,
		private readonly ?string $signaalOmschrijving,
		private readonly bool $accepted,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The correlation id echoed back from the original send.
	 *
	 * @return string Kenmerk.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
	 */
	public function getKenmerk(): string {
		return $this->kenmerk;
	}//end getKenmerk()

	/**
	 * The signaalcode.
	 *
	 * @return string Signaalcode.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
	 */
	public function getSignaalcode(): string {
		return $this->signaalcode;
	}//end getSignaalcode()

	/**
	 * The signal description, when supplied.
	 *
	 * @return string|null Description, or null when absent.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
	 */
	public function getSignaalOmschrijving(): ?string {
		return $this->signaalOmschrijving;
	}//end getSignaalOmschrijving()

	/**
	 * Whether the signaalcode indicates acceptance.
	 *
	 * @return bool True when accepted.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
	 */
	public function isAccepted(): bool {
		return $this->accepted;
	}//end isAccepted()
}//end class
