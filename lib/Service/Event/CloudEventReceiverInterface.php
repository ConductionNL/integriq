<?php

/**
 * Contract of the CloudEventReceiver.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Event;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see CloudEventReceiver} that other components call.
 */
interface CloudEventReceiverInterface {
	/**
	 * Store a received CloudEvent and route it, unless its id was received before.
	 *
	 * @param string              $receiver   The source id from the receiver URL.
	 * @param array<string,mixed> $cloudEvent The decoded event, its required attributes checked.
	 *
	 * @return ObjectEntity|null The stored event, or null when the same source sent this id before.
	 *
	 * @throws \Exception When the event cannot be stored or routed.
	 *
	 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
	 */
	public function receive(string $receiver, array $cloudEvent): ?ObjectEntity;
}
