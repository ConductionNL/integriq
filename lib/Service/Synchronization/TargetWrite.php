<?php

/**
 * The working state of one write to an external target.
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

/**
 * Carries the inputs of one create, update or delete on an `api` target
 * between the stages of SynchronizationService::writeObjectToTarget().
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
 */
class TargetWrite {

	/**
	 * The synchronization.
	 *
	 * @var array
	 */
	public array $synchronization = [];

	/**
	 * The contract, updated as the write goes.
	 *
	 * @var array
	 */
	public array $contract = [];

	/**
	 * The endpoint on the target, relative to its location.
	 *
	 * @var string
	 */
	public string $endpoint = '';

	/**
	 * The object to write.
	 *
	 * @var array
	 */
	public array $object = [];

	/**
	 * The active execution trace context, or null when the write is not traced.
	 *
	 * @var ExecutionTraceContext|null
	 */
	public ?ExecutionTraceContext $trace = null;

	/**
	 * The target source.
	 *
	 * @var array
	 */
	public array $target = [];

	/**
	 * The dot-expanded target config; its `json` is the body sent.
	 *
	 * @var array
	 */
	public array $targetConfig = [];

	/**
	 * The remote id the object has on the target, or null before it is created.
	 *
	 * @var mixed
	 */
	public mixed $targetId = null;
}//end class
