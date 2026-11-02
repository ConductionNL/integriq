<?php

/**
 * Contract of the RabbitMqHttpTransport.
 *
 * @category Contract
 * @package  OCA\Integriq\Broker\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker\Transport;

use OCA\Integriq\Broker\BrokerPublication;
use OCA\Integriq\Broker\BrokerResult;

/**
 * Public operations of {@see RabbitMqHttpTransport} that other components call.
 */
interface RabbitMqHttpTransportInterface {
	/**
	 * The broker id.
	 *
	 * @var string
	 */
	public const BROKER_ID = 'rabbitmq';

	/**
	 * The broker id this transport answers to.
	 *
	 * @return string The broker id.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function getId(): string;

	/**
	 * What this transport needs and can do.
	 *
	 * @return array<string,mixed> The description.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function describe(): array;

	/**
	 * Publish one event to an exchange.
	 *
	 * @param BrokerPublication $publication The event and its routing.
	 * @param array<string,mixed> $configuration `{baseUrl, vhost, username, password, headers}`.
	 *
	 * @return BrokerResult What happened.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
	 */
	public function publish(BrokerPublication $publication, array $configuration): BrokerResult;
}
