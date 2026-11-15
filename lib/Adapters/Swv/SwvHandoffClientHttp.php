<?php

/**
 * Integriq SWV hand-off client (live).
 *
 * Sends the composed support-request dossier to the samenwerkingsverband's
 * system through the receiver's own source row (`swv-kindkans`,
 * `swv-ldos`), so its address and brokered credential come from
 * configuration, and returns the receiver's acknowledgement. Bound only when
 * `swv.handoff.feature_flag` is on. The dossier holds special personal
 * data, so neither it nor the answer body is written to the call log. An
 * unreachable or refusing receiver throws: the caller keeps the dossier
 * queued, and the call log records the failure.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Swv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/connectors-education-live-clients/specs/swv-handoff/spec.md#requirement-a-live-client-hands-a-support-request-to-the-swv-system-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Swv;

use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Adapters\Support\SupplierSourceCall;

/**
 * Live hand-off of a dossier, answered by the receiver's acknowledgement.
 *
 * @spec openspec/changes/connectors-education-live-clients/specs/swv-handoff/spec.md#requirement-a-live-client-hands-a-support-request-to-the-swv-system-req-005
 */
final class SwvHandoffClientHttp extends SwvHandoffClient {

	/**
	 * The hand-off endpoint below the receiver's address.
	 *
	 * @var string
	 */
	public const ENDPOINT = '/handoff';

	/**
	 * Constructor.
	 *
	 * @param SupplierSourceCall $calls Calls the receiver's source.
	 */
	public function __construct(
		private readonly SupplierSourceCall $calls,
	) {
	}//end __construct()

	/**
	 * The transport flavour.
	 *
	 * @return string Always `https`.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/swv-handoff/spec.md#requirement-a-live-client-hands-a-support-request-to-the-swv-system-req-005
	 */
	public function flavour(): string {
		return 'https';
	}//end flavour()

	/**
	 * Post the dossier and return the receiver's acknowledgement.
	 *
	 * @param string              $receiverId The receiver source slug.
	 * @param array<string,mixed> $dossier    The composed dossier.
	 *
	 * @return array{referenceId:string,acceptedStatus:string,receivedAt:string,flavour:string} The acknowledgement.
	 *
	 * @throws SupplierCallException When the receiver is unreachable, refuses, or answers without a reference.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/swv-handoff/spec.md#requirement-a-live-client-hands-a-support-request-to-the-swv-system-req-005
	 */
	public function handOff(string $receiverId, array $dossier): array {
		$answer = $this->calls->json(
			slug: $receiverId,
			endpoint: self::ENDPOINT,
			method: 'POST',
			config: ['json' => $dossier],
			withholdBodies: true
		);

		$referenceId = (string)($answer['referenceId'] ?? '');
		if ($referenceId === '') {
			throw new SupplierCallException(message: sprintf('%s acknowledged the hand-off without a reference.', $receiverId));
		}

		return [
			'referenceId' => $referenceId,
			'acceptedStatus' => (string)($answer['acceptedStatus'] ?? 'received'),
			'receivedAt' => (string)($answer['receivedAt'] ?? gmdate('c')),
			'flavour' => $this->flavour(),
		];
	}//end handOff()
}//end class
