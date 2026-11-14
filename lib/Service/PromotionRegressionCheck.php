<?php

/**
 * Integriq Promotion Regression Check
 *
 * Runs the test cases of every mapping and synchronization in a configuration
 * that is about to be promoted, and answers which of them fail. Nothing is
 * recorded: it reads the exported document and replays the cases.
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
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-the-promotion-preview-shows-failing-cases-before-a-change-goes-live-req-irt-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * The failing test cases of an exported configuration.
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-the-promotion-preview-shows-failing-cases-before-a-change-goes-live-req-irt-005
 */
class PromotionRegressionCheck {

	/**
	 * The export's component groups that carry test cases, and the subject type each is.
	 *
	 * @var array<string,string>
	 */
	private const GROUPS = ['mappings' => 'mapping', 'synchronizations' => 'synchronization'];

	/**
	 * Constructor.
	 *
	 * @param RegressionSuiteService $suites Replays a subject's cases.
	 */
	public function __construct(
		private readonly RegressionSuiteService $suites,
	) {
	}//end __construct()

	/**
	 * Every failing case of the document's mappings and synchronizations.
	 *
	 * A case names its subject by uuid or by slug, so each subject is asked
	 * under every identifier it carries; a case is listed once.
	 *
	 * @param array<string,mixed> $document The exported OAS document.
	 *
	 * @return list<array{subjectType:string,subjectId:string,caseId:string,caseName:string}>
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-the-promotion-preview-shows-failing-cases-before-a-change-goes-live-req-irt-005
	 */
	public function failingCases(array $document): array {
		$failing = [];
		foreach (self::GROUPS as $group => $subjectType) {
			$entities = ($document['components'][$group] ?? []);
			if (is_array($entities) === false) {
				continue;
			}

			foreach ($entities as $key => $entity) {
				foreach (self::identifiers(key: $key, entity: $entity) as $subjectId) {
					$found   = $this->suites->failures(subjectType: $subjectType, subjectId: $subjectId);
					$failing = self::merged(failing: $failing, subjectType: $subjectType, subjectId: $subjectId, found: $found);
				}
			}
		}

		return array_values($failing);
	}//end failingCases()

	/**
	 * The identifiers a case may name an exported entity by: its slug key, slug, id and uuid.
	 *
	 * @param int|string $key    The entity's key in its group.
	 * @param mixed      $entity The entity.
	 *
	 * @return list<string>
	 */
	private static function identifiers(int|string $key, mixed $entity): array {
		$found = [];
		if (is_string($key) === true) {
			$found[] = $key;
		}

		if (is_array($entity) === true) {
			foreach (['slug', 'id', 'uuid'] as $field) {
				if (is_string($entity[$field] ?? null) === true) {
					$found[] = $entity[$field];
				}
			}
		}

		return array_values(array_unique(array_filter($found, static fn (string $value): bool => $value !== '')));
	}//end identifiers()

	/**
	 * Add a subject's failures, each case once.
	 *
	 * @param array<string,array<string,string>> $failing     The failures so far, by case id.
	 * @param string                             $subjectType The subject type.
	 * @param string                             $subjectId   The identifier asked.
	 * @param list<array<string,mixed>>          $found       The subject's failures.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function merged(array $failing, string $subjectType, string $subjectId, array $found): array {
		foreach ($found as $failure) {
			$caseId = (string)($failure['caseId'] ?? '');
			$failing[$caseId] ??= [
				'subjectType' => $subjectType,
				'subjectId' => $subjectId,
				'caseId' => $caseId,
				'caseName' => (string)($failure['caseName'] ?? ''),
			];
		}

		return $failing;
	}//end merged()
}//end class
