<?php

/**
 * Integriq TraceReplayRequest.
 *
 * What one replay of a stored execution trace starts from: the original
 * trace's entry point, entry point id and steps, whether the replay writes
 * (forced) or only previews, and who asked. Built by ExecutionTraceService
 * per replay() call and never handed out.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-points-real-dispatch-path-req-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One replay of a stored trace.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-points-real-dispatch-path-req-006
 */
final class TraceReplayRequest {

	/**
	 * The original entry point: sync, job, event or endpoint; empty when the trace names none.
	 *
	 * @var string
	 */
	public readonly string $entryPoint;

	/**
	 * The original entry point's id, when the trace names one.
	 *
	 * @var string|null
	 */
	public readonly ?string $entryPointId;

	/**
	 * The original trace's steps.
	 *
	 * @var array<int, mixed>
	 */
	public readonly array $steps;

	/**
	 * True to perform the real write; false to preview only.
	 *
	 * @var bool
	 */
	public bool $force = false;

	/**
	 * The acting operator's user id.
	 *
	 * @var string
	 */
	public string $actorUid = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $original The stored trace's data.
	 */
	public function __construct(array $original) {
		$this->entryPoint = (string)($original['entryPoint'] ?? '');
		$this->entryPointId = ($original['entryPointId'] ?? null);
		$this->steps = (array)($original['steps'] ?? []);
	}//end __construct()
}//end class
