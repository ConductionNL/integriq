<?php

/**
 * Integriq delivery ingest run.
 *
 * The state of one cross-app delivery request while EventService ingests it.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service;

use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One delivery request on its way through EventService::ingestDeliveryRequest().
 * Built and filled there; it never leaves EventService.
 *
 * @spec openspec/changes/absorb-dossiq-deliveries/specs/delivery-intake/spec.md
 */
final class DeliveryIngestRun {

	/**
	 * The payload as it is stored, with the unsubscribe material when the
	 * opt-out list allowed the send.
	 *
	 * @var array<string,mixed>
	 */
	public array $payload = [];

	/**
	 * The opt-out decision, or null when no person was named.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $optOut = null;

	/**
	 * The stored CloudEvent.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $event = null;

	/**
	 * The delivery messages routing created.
	 *
	 * @var ObjectEntity[]
	 */
	public array $messages = [];

	/**
	 * Constructor.
	 *
	 * @param DeliveryRequestedEvent $request The delivery request.
	 */
	public function __construct(
		public readonly DeliveryRequestedEvent $request,
	) {

	}//end __construct()

	/**
	 * Whether the opt-out list refused the delivery.
	 *
	 * @return bool True when a person was named and the send is not allowed.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function isRefused(): bool {
		return ($this->optOut !== null && $this->optOut['decision']['send'] !== true);

	}//end isRefused()
}//end class
