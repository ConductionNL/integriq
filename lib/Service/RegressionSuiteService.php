<?php

/**
 * Integriq RegressionSuiteService.
 *
 * Runs the test cases of one mapping or synchronization and records one
 * `integration_test_run`. A mapping case runs through the mapping, a pure
 * transform. A synchronization case runs its stored input through the
 * synchronization's source-to-target mapping, without fetching from the
 * source and without the rules, which may call out or write (the same
 * line the gated change-set preview draws). Nothing but the run record is
 * written.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\Service\Regression\OutputComparator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Throwable;

/**
 * The suite runner.
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 */
class RegressionSuiteService {

	/**
	 * The register the cases and runs live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * Schema of a test case.
	 *
	 * @var string
	 */
	public const CASE_SCHEMA = 'integration_test_case';

	/**
	 * Schema of a run record.
	 *
	 * @var string
	 */
	public const RUN_SCHEMA = 'integration_test_run';

	/**
	 * The subject types a suite runs for.
	 *
	 * @var array<int,string>
	 */
	public const SUBJECT_TYPES = ['mapping', 'synchronization'];

	/**
	 * The most cases one suite reads.
	 *
	 * @var int
	 */
	private const CASE_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService         $objectService  Reads cases and synchronizations, writes the run record.
	 * @param MappingServiceInterface $mappingService Runs a mapping on an input.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly MappingServiceInterface $mappingService,
	) {
	}//end __construct()

	/**
	 * Run every case of a subject and record the run.
	 *
	 * @param string $subjectType `mapping` or `synchronization`.
	 * @param string $subjectId   The subject's uuid or slug, as the cases name it.
	 * @param string $userId      Who started the run.
	 *
	 * @return array<string,mixed> The run record as stored.
	 *
	 * @throws InvalidArgumentException When the subject type is not known.
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
	 */
	public function run(string $subjectType, string $subjectId, string $userId): array {
		if (in_array($subjectType, self::SUBJECT_TYPES, true) === false) {
			throw new InvalidArgumentException(sprintf('Unknown subject type %s.', $subjectType));
		}

		$startedAt = (new DateTimeImmutable())->format('c');
		$cases = $this->cases(subjectType: $subjectType, subjectId: $subjectId);
		$failures = $this->failuresOf(cases: $cases);

		$run = [
			'subjectType' => $subjectType,
			'subjectId' => $subjectId,
			'total' => count($cases),
			'passed' => (count($cases) - count($failures)),
			'failed' => count($failures),
			'failures' => $failures,
			'runBy' => $userId,
			'startedAt' => $startedAt,
			'finishedAt' => (new DateTimeImmutable())->format('c'),
		];

		$saved = $this->objectService->saveObject(
			object: $run,
			register: self::REGISTER,
			schema: self::RUN_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return array_merge($run, ['id' => (string)$saved->getUuid()]);
	}//end run()

	/**
	 * The failing cases of a subject, run without recording a run.
	 *
	 * The promotion preview asks this for every mapping and synchronization of
	 * the configuration it is about to send, so a look at the preview writes
	 * nothing (automation-integration-regression-tests, REQ-IRT-005).
	 *
	 * @param string $subjectType `mapping` or `synchronization`.
	 * @param string $subjectId   The subject's uuid or slug, as the cases name it.
	 *
	 * @return list<array<string,mixed>> One entry per failing case.
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-the-promotion-preview-shows-failing-cases-before-a-change-goes-live-req-irt-005
	 */
	public function failures(string $subjectType, string $subjectId): array {
		if (in_array($subjectType, self::SUBJECT_TYPES, true) === false || $subjectId === '') {
			return [];
		}

		return $this->failuresOf(cases: $this->cases(subjectType: $subjectType, subjectId: $subjectId));
	}//end failures()

	/**
	 * Run cases and keep what failed.
	 *
	 * @param array<int,array<string,mixed>> $cases The cases.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function failuresOf(array $cases): array {
		$failures = [];
		foreach ($cases as $case) {
			$failure = $this->runCase(case: $case);
			if ($failure !== null) {
				$failures[] = $failure;
			}
		}

		return $failures;
	}//end failuresOf()

	/**
	 * Run one case; null when it passes, else what failed.
	 *
	 * @param array<string,mixed> $case The case data, with its `id`.
	 *
	 * @return array<string,mixed>|null The failure, or null.
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
	 */
	public function runCase(array $case): ?array {
		$input = $this->objectOf(value: $case['input'] ?? []);
		$expected = $this->objectOf(value: $case['expectedOutput'] ?? []);
		$ignored = array_values(array_filter((array)($case['ignoredPaths'] ?? []), 'is_string'));

		try {
			$actual = $this->mappingService->executeMapping(
				mapping: $this->mappingOf(case: $case),
				input: $input
			);
		} catch (Throwable $exception) {
			return [
				'caseId' => (string)($case['id'] ?? ''),
				'caseName' => (string)($case['name'] ?? ''),
				'differences' => [],
				'error' => $exception->getMessage(),
			];
		}

		$differences = OutputComparator::differences(expected: $expected, actual: $actual, ignored: $ignored);
		if ($differences === []) {
			return null;
		}

		return [
			'caseId' => (string)($case['id'] ?? ''),
			'caseName' => (string)($case['name'] ?? ''),
			'differences' => $differences,
		];
	}//end runCase()

	/**
	 * The mapping a case runs: the mapping itself, or the synchronization's source-to-target mapping.
	 *
	 * @param array<string,mixed> $case The case.
	 *
	 * @return string The mapping's uuid or slug.
	 *
	 * @throws InvalidArgumentException When a synchronization has no source-to-target mapping.
	 */
	private function mappingOf(array $case): string {
		$subjectId = (string)($case['subjectId'] ?? '');
		if (($case['subjectType'] ?? '') !== 'synchronization') {
			return $subjectId;
		}

		$synchronization = $this->objectService->find(
			id: $subjectId,
			register: self::REGISTER,
			schema: 'synchronization',
			_rbac: false,
			_multitenancy: false
		);
		$mapping = (string)($synchronization->getObject()['sourceTargetMapping'] ?? '');
		if ($mapping === '') {
			throw new InvalidArgumentException(sprintf('Synchronization %s has no source-to-target mapping to test.', $subjectId));
		}

		return $mapping;
	}//end mappingOf()

	/**
	 * Every case of a subject.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectId   The subject's uuid or slug.
	 *
	 * @return array<int,array<string,mixed>> The case data, each with its `id`.
	 */
	private function cases(string $subjectType, string $subjectId): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::CASE_SCHEMA,
					'subjectType' => $subjectType,
					'subjectId' => $subjectId,
				],
				'limit' => self::CASE_LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		$cases = [];
		foreach (($result['results'] ?? $result) as $entity) {
			if ($entity instanceof ObjectEntity === false) {
				continue;
			}

			$data = $entity->getObject();
			// The filter is a hint to OpenRegister, not a guarantee: match again.
			if (is_array($data) === false || ($data['subjectType'] ?? null) !== $subjectType || (string)($data['subjectId'] ?? '') !== $subjectId) {
				continue;
			}

			$cases[] = array_merge($data, ['id' => (string)$entity->getUuid()]);
		}

		return $cases;
	}//end cases()

	/**
	 * An object value as an array; anything else is empty.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<mixed>
	 */
	private function objectOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end objectOf()
}//end class
