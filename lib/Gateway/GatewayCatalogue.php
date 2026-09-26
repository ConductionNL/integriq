<?php

/**
 * The gateway entries this instance ships.
 *
 * @category Service
 * @package  OCA\Integriq\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Gateway;

/**
 * Declarations, in one place, so the catalogue page and the gateway overview
 * read the same list and nobody has to open a configuration file to answer
 * which laws this instance reaches and where data leaves to.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
 */
final class GatewayCatalogue {
	/**
	 * Every gateway entry, in the order the catalogue lists them.
	 *
	 * @return array<int,array<string,mixed>> The declared entries.
	 */
	public static function entries(): array {
		return [
			[
				'id' => 'digikoppeling-wus',
				'label' => 'Digikoppeling WUS',
				'standard' => 'Digikoppeling WUS',
				'claimLevel' => GatewayDescriptor::CLAIM_PARTIAL,
				'claimEvidence' => 'WUS 2.0 signing and PKIoverheid key resolution are implemented and unit tested; no Logius compliance test has been run.',
				'jurisdiction' => 'NL',
				'transport' => 'https',
			],
			[
				'id' => 'digikoppeling-ebms2',
				'label' => 'Digikoppeling ebMS2',
				'standard' => 'Digikoppeling ebMS2',
				'claimLevel' => GatewayDescriptor::CLAIM_PARTIAL,
				'claimEvidence' => 'Reliable messaging and the grote berichten reference are implemented; no Logius compliance test has been run.',
				'jurisdiction' => 'NL',
				'transport' => 'https',
			],
			[
				'id' => 'rod',
				'label' => 'DUO ROD',
				'standard' => 'ROD (Register Onderwijsdeelnemers) via Edukoppeling',
				'claimLevel' => GatewayDescriptor::CLAIM_PLANNED,
				'claimEvidence' => 'Envelope build and translation validated against fixtures; no certificate, no koppelvlak connection (M3(c)).',
				'jurisdiction' => 'NL',
			],
			[
				'id' => 'stuf-zkn',
				'label' => 'StUF-ZKN 3.10',
				'standard' => 'StUF-ZKN 3.10',
				'claimLevel' => GatewayDescriptor::CLAIM_PARTIAL,
				'claimEvidence' => 'The message shapes this instance sends are covered by the StUF adapter suite; the full koppelvlak is not.',
				'jurisdiction' => 'NL',
				'transport' => 'https',
			],
			[
				'id' => 'corv',
				'label' => 'CORV',
				'standard' => 'CORV koppelvlak',
				'claimLevel' => GatewayDescriptor::CLAIM_PLANNED,
				'claimEvidence' => 'The adapter validates and routes messages against a mock-mode fixture. No connection to the statutory route has been made.',
				'jurisdiction' => 'NL',
				'transport' => 'https',
			],
			[
				'id' => 'ggk',
				'label' => 'GGK',
				'standard' => 'GGK koppelvlak',
				'claimLevel' => GatewayDescriptor::CLAIM_PLANNED,
				'claimEvidence' => 'The adapter validates and routes messages against a mock-mode fixture. No connection to the statutory route has been made.',
				'jurisdiction' => 'NL',
				'transport' => 'https',
			],
			[
				'id' => 'berichtenbox',
				'label' => 'Berichtenbox',
				'standard' => 'Wmebv',
				'claimLevel' => GatewayDescriptor::CLAIM_PARTIAL,
				'claimEvidence' => 'The route records what it delivers and when. The obligations it hands on are listed below rather than assumed.',
				'jurisdiction' => 'NL',
				'transport' => 'https',
				'wmebvMet' => [
					'Electronic channel is open for this message type',
					'Delivery moment is recorded',
				],
				'wmebvHandedToConsumer' => [
					[
						'obligation' => 'Confirmation of receipt to the sender',
						'consumerDuty' => 'The consuming app sends the confirmation, because it owns the case the message belongs to.',
					],
					[
						'obligation' => 'Notice of the processing term',
						'consumerDuty' => 'The consuming app states the term, because integriq does not know the case type.',
					],
				],
			],
			[
				'id' => 'publicatie',
				'label' => 'Officiele publicatie',
				'standard' => 'Wet elektronisch publiceren',
				'claimLevel' => GatewayDescriptor::CLAIM_PLANNED,
				'claimEvidence' => 'The gateway publishes by reference and records the identifier the platform returns. '
					.'No connection to the publication platform has been made.',
				'jurisdiction' => 'NL',
				'transport' => 'https',
			],
			[
				'id' => 'wkpb',
				'label' => 'WKPB',
				'standard' => 'Wkpb',
				'claimLevel' => GatewayDescriptor::CLAIM_PLANNED,
				'claimEvidence' => 'The gateway registers a restriction and records the returned identifier, against a mock-mode fixture.',
				'jurisdiction' => 'NL',
				'transport' => 'https',
			],
		];
	}//end entries()
}//end class
