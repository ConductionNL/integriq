<?php

/**
 * One event message delivered to a subscription's action.
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
 * The event message, the subscription data it is delivered under, the
 * subscription's action and the active trace, as EventService hands them to
 * the dispatcher of the action's kind.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
 */
final class ActionDelivery {

	/**
	 * Holds the values of one action delivery.
	 *
	 * @param ObjectEntity               $message          The event message being delivered.
	 * @param array                      $subscriptionData The subscription's data (retry policy and the like).
	 * @param array                      $action           The subscription's action configuration.
	 * @param ExecutionTraceContext|null $trace            The active execution trace, when any.
	 */
	public function __construct(
		public readonly ObjectEntity $message,
		public readonly array $subscriptionData,
		public readonly array $action,
		public readonly ?ExecutionTraceContext $trace = null,
	) {
	}//end __construct()
}//end class
