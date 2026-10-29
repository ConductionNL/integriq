<?php

/**
 * The working state of one synchronizeContract() run.
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
 * Carries the inputs of one contract run and its contract log between the
 * stages of SynchronizationService::synchronizeContract().
 *
 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
 */
class ContractRun {

	/**
	 * The synchronization configuration.
	 *
	 * @var array|null
	 */
	public ?array $synchronization = null;

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
	 * Whether this is a test run, which writes nothing.
	 *
	 * @var boolean|null
	 */
	public ?bool $isTest = false;

	/**
	 * The mutation type of a single-object sync: 'create', 'update' or 'delete'.
	 *
	 * @var string|null
	 */
	public ?string $mutationType = null;

	/**
	 * The active execution trace context.
	 *
	 * @var ExecutionTraceContext|null
	 */
	public ?ExecutionTraceContext $trace = null;

	/**
	 * The source-to-target mapping, or null when none is configured.
	 *
	 * @var mixed
	 */
	public mixed $mapping = null;

	/**
	 * The per-contract log, or null when none is kept.
	 *
	 * @var array|null
	 */
	public ?array $contractLog = null;
}//end class
