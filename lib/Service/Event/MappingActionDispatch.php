<?php

/**
 * One `action.kind = 'mapping'` dispatch of an event message.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Carries the message, the action and what EventService::dispatchMappingAction()
 * resolves for it (mapping, source, form and submission), so each stage names
 * the dispatch instead of every value it needs.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
 */
class MappingActionDispatch {

	/**
	 * The subscription's retry policy.
	 *
	 * @var array
	 */
	public array $retryPolicy = [];

	/**
	 * The resolved mapping.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $mapping = null;

	/**
	 * The resolved source the mapped submission is sent to.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $source = null;

	/**
	 * The Forms form id from the event.
	 *
	 * @var int
	 */
	public int $formId = 0;

	/**
	 * The Forms submission id from the event.
	 *
	 * @var int
	 */
	public int $submissionId = 0;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $message The message being dispatched.
	 * @param array        $action  The resolved `action` block (`{kind, mappingId, sourceId, endpoint, method?}`).
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
	 */
	public function __construct(
		public readonly ObjectEntity $message,
		public readonly array $action,
	) {
	}//end __construct()
}//end class
