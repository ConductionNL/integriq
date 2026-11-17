<?php

/**
 * The working state of one source item in a synchronization run.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
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

namespace OCA\Integriq\Service\Synchronization;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;

/**
 * Carries the inputs and the result of one item between the stages of
 * SynchronizationService::processSynchronizationObject().
 *
 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
 */
class ItemRun {

	/**
	 * The synchronization.
	 *
	 * @var array
	 */
	public array $synchronization = [];

	/**
	 * The dot-expanded source config of the synchronization.
	 *
	 * @var array
	 */
	public array $sourceConfig = [];

	/**
	 * The flow token shared across the run.
	 *
	 * @var FlowToken
	 */
	public FlowToken $flowToken;

	/**
	 * The active execution trace context, or null when the run is not traced.
	 *
	 * @var ExecutionTraceContext|null
	 */
	public ?ExecutionTraceContext $trace = null;

	/**
	 * When processing of the item started, for its trace step.
	 *
	 * @var float
	 */
	public float $stepStart = 0.0;

	/**
	 * The page's contracts keyed by origin id, or null to query per item.
	 *
	 * @var array|null
	 */
	public ?array $contractIndex = null;

	/**
	 * The run result the item is counted in.
	 *
	 * @var array
	 */
	public array $result = [];

	/**
	 * Whether the item had no contract yet.
	 *
	 * @var boolean
	 */
	public bool $isNewContract = false;

	/**
	 * What synchronizeContract() did with the item.
	 *
	 * @var mixed
	 */
	public mixed $resultAction = null;
}//end class
