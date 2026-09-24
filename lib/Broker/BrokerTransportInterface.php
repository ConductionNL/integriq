<?php

/**
 * Integriq BrokerTransportInterface.
 *
 * One broker is one transport: it takes a rendered CloudEvent, publishes it
 * the way its broker expects, and reads its broker's own answer. Adding a
 * broker is adding a transport, not changing the event engine.
 *
 * @category Broker
 * @package  OCA\Integriq\Broker
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
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker;

/**
 * The contract every broker transport answers to.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
 */
interface BrokerTransportInterface {

	/**
	 * The broker id this transport answers to.
	 *
	 * @return string The broker id.
	 */
	public function getId(): string;

	/**
	 * What this transport needs and what it can do.
	 *
	 * @return array<string,mixed> `{id, label, needsTopic, contentModes}`.
	 */
	public function describe(): array;

	/**
	 * Publish one event.
	 *
	 * A transport that cannot reach its broker MUST answer a refusal rather
	 * than a publish: a delivery that reached nobody looks exactly like one
	 * that arrived.
	 *
	 * @param BrokerPublication $publication The event and its routing.
	 * @param array<string,mixed> $configuration The broker connection settings.
	 *
	 * @return BrokerResult What happened.
	 */
	public function publish(BrokerPublication $publication, array $configuration): BrokerResult;

}//end interface
