<?php

/**
 * Integriq OsoAcknowledgementReceived Event.
 *
 * Dispatched whenever an OSO export acknowledgement/retour is received and
 * translated, mirroring RodAcknowledgementReceivedEvent /
 * VerzuimloketAcknowledgementReceivedEvent for the OSO export leg.
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * An OSO export acknowledgement, translated and ready for a listener to act on.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 */
class OsoAcknowledgementReceivedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $kenmerk The correlation id echoed back from the original export.
	 * @param string $signaalcode The signaalcode (`0` means accepted).
	 * @param string|null $signaalOmschrijving The signal description, when supplied.
	 * @param bool $accepted Whether the signaalcode indicates acceptance.
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
	 * The correlation id echoed back from the original export.
	 *
	 * @return string Kenmerk.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	public function getKenmerk(): string {
		return $this->kenmerk;
	}//end getKenmerk()

	/**
	 * The signaalcode (`0` means accepted).
	 *
	 * @return string Signaalcode.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	public function getSignaalcode(): string {
		return $this->signaalcode;
	}//end getSignaalcode()

	/**
	 * The signal description, when supplied.
	 *
	 * @return string|null Description, or null when absent.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	public function getSignaalOmschrijving(): ?string {
		return $this->signaalOmschrijving;
	}//end getSignaalOmschrijving()

	/**
	 * Whether the signaalcode indicates acceptance.
	 *
	 * @return bool True when accepted.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	public function isAccepted(): bool {
		return $this->accepted;
	}//end isAccepted()
}//end class
