<?php

/**
 * The run options one source item is synchronized with.
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
use OCA\Integriq\Service\SynchronizationRunLog;

/**
 * The synchronization, the run's result so far, its flags, run log, flow token,
 * mutation type, trace and contract index, as SynchronizationService hands them
 * to the routing of one source item.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The constructor holds every run option an item is routed with,
 *   so the routing itself takes one request.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flags are the run options themselves, held, not branched on.
 */
final class SourceItemRequest {

	/**
	 * Holds the run options of one source item.
	 *
	 * @param array                      $synchronization The synchronization being processed.
	 * @param array                      $result          The run's result tally so far.
	 * @param bool                       $isTest          Whether this is a test run.
	 * @param bool                       $force           Whether to synchronize regardless of changes.
	 * @param SynchronizationRunLog      $log             The run log.
	 * @param FlowToken                  $flowToken       The flow token shared across the run.
	 * @param string|null                $mutationType    The type of object mutation.
	 * @param ExecutionTraceContext|null $trace           The active execution trace, when any.
	 * @param array|null                 $contractIndex   Contracts pre-read for the whole page, keyed by origin id;
	 *                                                    when given, a missing key is a miss, not a reason to query.
	 */
	public function __construct(
		public readonly array $synchronization,
		public readonly array $result,
		public readonly bool $isTest,
		public readonly bool $force,
		public readonly SynchronizationRunLog $log,
		public readonly FlowToken $flowToken,
		public readonly ?string $mutationType = null,
		public readonly ?ExecutionTraceContext $trace = null,
		public readonly ?array $contractIndex = null,
	) {
	}//end __construct()
}//end class
