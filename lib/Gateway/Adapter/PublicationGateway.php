<?php

/**
 * Official publication, by reference.
 *
 * @category Adapter
 * @package  OCA\Integriq\Gateway\Adapter
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

namespace OCA\Integriq\Gateway\Adapter;

use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;

/**
 * The document stays where its owning app keeps it. This gateway takes a
 * reference and an instruction, and records the identifier the platform
 * returns. Nothing is copied into integriq, which is the point: a publication
 * route that stores every document becomes a second archive nobody asked for.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-official-publication-is-a-gateway-and-the-document-is-published-by-reference-req-sg-005
 */
class PublicationGateway {
	/**
	 * The gateway id.
	 */
	public const ID = 'publicatie';

	/**
	 * Constructor.
	 *
	 * @param GatewayTransport $transport The shared delivery machinery.
	 */
	public function __construct(private readonly GatewayTransport $transport) {
	}//end __construct()

	/**
	 * Publish a document that is held elsewhere.
	 *
	 * @param array<string,mixed> $reference Where the document is, and who owns it.
	 * @param array<string,mixed> $instruction What to publish, and under which rules.
	 * @param array<string,mixed> $config The gateway's configuration.
	 *
	 * @return GatewayDelivery What happened.
	 */
	public function publish(array $reference, array $instruction, array $config = []): GatewayDelivery {
		$refusals = $this->validate(reference: $reference, instruction: $instruction);
		if ($refusals !== []) {
			return GatewayDelivery::notSent(self::ID, implode(' ', $refusals));
		}

		// Only the reference and the instruction travel. The document itself
		// is fetched by the platform from where it already lives.
		return $this->transport->send(
			self::ID,
			['reference' => $reference, 'instruction' => $instruction],
			$config
		);
	}//end publish()

	/**
	 * Check a publication before anything leaves.
	 *
	 * @param array<string,mixed> $reference The document reference.
	 * @param array<string,mixed> $instruction The publication instruction.
	 *
	 * @return array<int,string> The refusals, empty when it may go.
	 */
	public function validate(array $reference, array $instruction): array {
		$refusals = [];

		foreach (['app', 'id', 'url'] as $field) {
			if ((string)($reference[$field] ?? '') === '') {
				$refusals[] = sprintf('The document reference names no "%s", so nothing can be published.', $field);
			}
		}

		foreach (['publicationType', 'effectiveDate'] as $field) {
			if ((string)($instruction[$field] ?? '') === '') {
				$refusals[] = sprintf('The publication instruction names no "%s".', $field);
			}
		}

		if (array_key_exists('document', $reference) === true) {
			$refusals[] = 'A publication carries a reference, not the document itself. Remove the inline document.';
		}

		return $refusals;
	}//end validate()
}//end class
