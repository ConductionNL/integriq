<?php

/**
 * Integriq TraceCaseRecorder.
 *
 * Turns one step of a traced execution into an `integration_test_case`: the
 * step's input becomes the case input and its output the expected output. A
 * `mapping` step records a case for the mapping that ran; a `synchronization`
 * step records a case for the synchronization the trace started from. The
 * trace snapshots are redacted before they are stored, so a recorded case
 * keeps the redaction marker unless the engineer replaces it; the recorder
 * answers which paths still carry it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Regression
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Regression;

use InvalidArgumentException;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\RegressionSuiteService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OutOfBoundsException;

/**
 * Records a test case from a trace step.
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 */
class TraceCaseRecorder {

	/**
	 * The register the cases live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * The step types a case can be recorded from.
	 *
	 * @var array<int,string>
	 */
	public const RECORDABLE_STEP_TYPES = ['mapping', 'synchronization'];

	/**
	 * Constructor.
	 *
	 * @param ExecutionTraceService $traces        Reads the trace.
	 * @param OrObjectService       $objectService Writes the case.
	 */
	public function __construct(
		private readonly ExecutionTraceService $traces,
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Save one step of a trace as a test case.
	 *
	 * Values the engineer edited in the dialog (`name`, `input`,
	 * `expectedOutput`, `ignoredPaths`) take the place of the snapshot's;
	 * anything not given is the step's own snapshot, unchanged.
	 *
	 * @param string              $traceId   The trace's id.
	 * @param int                 $stepOrder The step's `order` in the trace.
	 * @param array<string,mixed> $edits     Optional `name`, `description`, `input`, `expectedOutput`, `ignoredPaths`.
	 *
	 * @return array<string,mixed> The case as stored, with its `id` and the `redactedPaths` it still holds.
	 *
	 * @throws OutOfBoundsException     When the trace or the step does not exist.
	 * @throws InvalidArgumentException When the step is not a mapping or synchronization step, or names no subject.
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
	 */
	public function record(string $traceId, int $stepOrder, array $edits = []): array {
		$entity = $this->traces->find(traceId: $traceId);
		if ($entity === null) {
			throw new OutOfBoundsException(sprintf('Trace %s does not exist.', $traceId));
		}

		$trace = $entity->getObject();
		$step = $this->stepOf(trace: $trace, order: $stepOrder);
		$type = (string)($step['type'] ?? '');
		if (in_array($type, self::RECORDABLE_STEP_TYPES, true) === false) {
			throw new InvalidArgumentException(sprintf('A test case is recorded from a mapping or synchronization step, not from a %s step.', $type));
		}

		$subjectId = $this->subjectOf(trace: $trace, step: $step);
		if ($subjectId === '') {
			throw new InvalidArgumentException(sprintf('Step %d does not name the %s that ran.', $stepOrder, $type));
		}

		$case = [
			'name' => $this->nameOf(edits: $edits, step: $step, traceId: $traceId),
			'subjectType' => $type,
			'subjectId' => $subjectId,
			'input' => $this->objectOf(edited: $edits['input'] ?? null, snapshot: $step['input'] ?? []),
			'expectedOutput' => $this->objectOf(edited: $edits['expectedOutput'] ?? null, snapshot: $step['output'] ?? []),
			'ignoredPaths' => array_values(array_filter((array)($edits['ignoredPaths'] ?? []), static fn ($path): bool => is_string($path) && $path !== '')),
		];
		$description = trim((string)($edits['description'] ?? ''));
		if ($description !== '') {
			$case['description'] = $description;
		}

		$saved = $this->objectService->saveObject(
			object: $case,
			register: self::REGISTER,
			schema: RegressionSuiteService::CASE_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		$redacted = array_merge(
			self::redactedPaths(data: $case['input'], prefix: 'input'),
			self::redactedPaths(data: $case['expectedOutput'], prefix: 'expectedOutput')
		);

		return array_merge($case, ['id' => (string)$saved->getUuid(), 'redactedPaths' => $redacted]);
	}//end record()

	/**
	 * The dot paths of every value that is the redaction marker.
	 *
	 * @param array<mixed> $data   The snapshot.
	 * @param string       $prefix The path of `$data` itself; empty at the top.
	 *
	 * @return list<string> The paths, in document order.
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
	 */
	public static function redactedPaths(array $data, string $prefix = ''): array {
		$paths = [];
		foreach ($data as $key => $value) {
			$path = (string)$key;
			if ($prefix !== '') {
				$path = $prefix . '.' . $key;
			}

			if (is_array($value) === true) {
				$paths = array_merge($paths, self::redactedPaths(data: $value, prefix: $path));
				continue;
			}

			if ($value === SensitiveFieldRegistry::PLACEHOLDER) {
				$paths[] = $path;
			}
		}

		return $paths;
	}//end redactedPaths()

	/**
	 * The step with the given order.
	 *
	 * @param array<string,mixed> $trace The trace data.
	 * @param int                 $order The step's order.
	 *
	 * @return array<string,mixed> The step.
	 *
	 * @throws OutOfBoundsException When no step has that order.
	 */
	private function stepOf(array $trace, int $order): array {
		foreach ((array)($trace['steps'] ?? []) as $step) {
			if (is_array($step) === true && (int)($step['order'] ?? 0) === $order) {
				return $step;
			}
		}

		throw new OutOfBoundsException(sprintf('The trace has no step %d.', $order));
	}//end stepOf()

	/**
	 * The uuid or slug of what ran in the step.
	 *
	 * A mapping step names its mapping; a synchronization step belongs to the
	 * synchronization the trace started from, unless the step names one.
	 *
	 * @param array<string,mixed> $trace The trace data.
	 * @param array<string,mixed> $step  The step.
	 *
	 * @return string The subject, or empty when the step does not say.
	 */
	private function subjectOf(array $trace, array $step): string {
		if ($step['type'] === 'mapping') {
			return (string)($step['mappingId'] ?? ($step['mapping'] ?? ($step['name'] ?? '')));
		}

		$named = (string)($step['synchronizationId'] ?? '');
		if ($named !== '') {
			return $named;
		}

		if (($trace['entryPoint'] ?? null) === 'sync') {
			return (string)($trace['entryPointId'] ?? '');
		}

		return '';
	}//end subjectOf()

	/**
	 * The case name: the one given, else the step's name and where it came from.
	 *
	 * @param array<string,mixed> $edits   The edits.
	 * @param array<string,mixed> $step    The step.
	 * @param string              $traceId The trace's id.
	 *
	 * @return string The name.
	 */
	private function nameOf(array $edits, array $step, string $traceId): string {
		$name = trim((string)($edits['name'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return sprintf('%s (trace %s, step %d)', (string)($step['name'] ?? $step['type']), substr($traceId, 0, 8), (int)($step['order'] ?? 0));
	}//end nameOf()

	/**
	 * The edited object when one was given, else the snapshot; anything not an object is empty.
	 *
	 * @param mixed $edited   The edited value, or null.
	 * @param mixed $snapshot The step's snapshot.
	 *
	 * @return array<mixed>
	 */
	private function objectOf(mixed $edited, mixed $snapshot): array {
		if (is_array($edited) === true) {
			return $edited;
		}

		if (is_array($snapshot) === true) {
			return $snapshot;
		}

		return [];
	}//end objectOf()
}//end class
