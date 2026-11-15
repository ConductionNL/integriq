<?php

/**
 * The state of one push delivery attempt while EventService sends it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Event
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

namespace OCA\Integriq\Service\Event;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Carries the message, its subscription and the request built for it, so the
 * stages of EventService::deliverMessage() name the attempt instead of every
 * value they need.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-push-delivery-with-status-tracking-and-retry-sweep-req-002
 */
class PushDelivery {

	/**
	 * The message's object data.
	 *
	 * @var array
	 */
	public array $messageData = [];

	/**
	 * The subscription's object data; empty until the subscription is read.
	 *
	 * @var array
	 */
	public array $subscriptionData = [];

	/**
	 * The serialized payload, the exact bytes a signature covers.
	 *
	 * @var string|false
	 */
	public string|false $rawBody = false;

	/**
	 * The request headers.
	 *
	 * @var array<string, mixed>
	 */
	public array $headers = [];

	/**
	 * Whether the push is signed; null until that is decided.
	 *
	 * @var bool|null
	 */
	public ?bool $signed = null;

	/**
	 * When the attempt started, for the trace's call step.
	 *
	 * @var float
	 */
	public readonly float $startedAt;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity               $message The pending message.
	 * @param ExecutionTraceContext|null $trace   The active execution trace, if any.
	 */
	public function __construct(
		public readonly ObjectEntity $message,
		public readonly ?ExecutionTraceContext $trace,
	) {
		$this->startedAt = microtime(true);
	}//end __construct()
}//end class
