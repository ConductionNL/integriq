<?php

/**
 * Integriq SBR Delivery.
 *
 * One XBRL instance ready to be handed to Digipoort's aanleverservice: the
 * berichtsoort it is filed as, the sender's own kenmerk, the identity of the
 * party it is filed for, and the instance itself.
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Digipoort;

/**
 * An XBRL instance and the envelope values Digipoort needs to accept it.
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
 */
final class SbrDelivery {
	/**
	 * Constructor.
	 *
	 * @param string $berichtsoort    The Digipoort berichtsoort the filing type maps to (e.g. `Omzetbelasting`).
	 * @param string $aanleverkenmerk The sender's own reference, stable per filed object and filing type.
	 * @param string $identityNumber  The identity number of the party the filing is for (e.g. an OB number or KvK number).
	 * @param string $identityType    The kind of that number (e.g. `Fi`, `KvK`).
	 * @param string $instance        The XBRL instance.
	 * @param string $fileName        The file name the instance is delivered under.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
	 */
	public function __construct(
		public readonly string $berichtsoort,
		public readonly string $aanleverkenmerk,
		public readonly string $identityNumber,
		public readonly string $identityType,
		public readonly string $instance,
		public readonly string $fileName,
	) {
	}//end __construct()
}//end class
