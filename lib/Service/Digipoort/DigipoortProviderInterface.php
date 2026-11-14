<?php

/**
 * Integriq Digipoort Provider Interface.
 *
 * The seam every Digipoort delivery and status request goes through. The
 * `log` binding answers without a network call; the `wus` binding talks to
 * Logius over Digikoppeling WUS. A new binding implements this interface and
 * leaves SbrFilingService alone.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Digipoort
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Digipoort;

use OCA\Integriq\Exception\DigipoortException;

/**
 * Delivers an XBRL instance to Digipoort and reads its processing statuses.
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
 */
interface DigipoortProviderInterface {
	/**
	 * Hand one instance to the aanleverservice.
	 *
	 * @param array<string,mixed> $configuration The `digipoort-sbr` source's `configuration`.
	 * @param SbrDelivery         $delivery      The instance and its envelope values.
	 *
	 * @return string The delivery reference (Digipoort's kenmerk) the status service answers on.
	 *
	 * @throws DigipoortException When the instance is not delivered; nothing may then be marked delivered.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
	 */
	public function deliver(array $configuration, SbrDelivery $delivery): string;

	/**
	 * Every processing status the statusinformatieservice holds for a delivery, oldest first.
	 *
	 * @param array<string,mixed> $configuration     The `digipoort-sbr` source's `configuration`.
	 * @param string              $deliveryReference The reference `deliver()` returned.
	 *
	 * @return list<array{status:string,code:string,description:string,at:string,errors:list<array{code:string,text:string}>}>
	 *         `status` is one of `delivered`, `accepted`, `rejected`; `code` is the channel's own status code.
	 *
	 * @throws DigipoortException When the statuses cannot be read.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
	 */
	public function statuses(array $configuration, string $deliveryReference): array;
}//end interface
