<?php

/**
 * Refuses a synchronization run whose declared prerequisites are not set.
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

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads `sourceConfig.requiredMappingValues` before a run fetches anything.
 *
 * Each entry names a mapping, a key in that mapping and what the value is,
 * for example the provider's `lti_deployment` in a course marketplace set.
 * A run whose value is empty would write a course and a lesson that the
 * target app then refuses to launch, so the run does not start, and the
 * refusal says what to set and where.
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
 */
class RunPrerequisiteGuard {

	/**
	 * The sourceConfig key that declares the prerequisites.
	 */
	public const KEY = 'requiredMappingValues';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object service, to read the mappings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * The first prerequisite that is not met, as a message for the run log.
	 *
	 * @param array<string, mixed> $sourceConfig The synchronization's source configuration.
	 *
	 * @return string|null The refusal, or null when the run may start.
	 *
	 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
	 */
	public function missing(array $sourceConfig): ?string {
		$required = ($sourceConfig[self::KEY] ?? []);
		if (is_array($required) === false || ($required !== [] && array_is_list($required) === false)) {
			return 'Canceled: sourceConfig.' . self::KEY . ' must be a list of {mapping, key, name} entries.';
		}

		foreach ($required as $entry) {
			$refusal = $this->check(entry: $entry);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}//end missing()

	/**
	 * Check one declared value.
	 *
	 * @param mixed $entry One {mapping, key, name} entry.
	 *
	 * @return string|null The refusal, or null when the value is set.
	 *
	 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
	 */
	private function check(mixed $entry): ?string {
		$entry   = (array)$entry;
		$mapping = trim((string)($entry['mapping'] ?? ''));
		$key     = trim((string)($entry['key'] ?? ''));
		if ($mapping === '' || $key === '') {
			return 'Canceled: every entry of sourceConfig.' . self::KEY . ' needs a mapping and a key.';
		}

		$name = trim((string)($entry['name'] ?? ''));
		if ($name === '') {
			$name = $key;
		}

		$values = $this->mappingValues(mapping: $mapping);
		if ($values === null) {
			return sprintf('Canceled: the mapping "%s" that holds %s is not on this instance.', $mapping, $name);
		}

		$value = ($values[$key] ?? '');
		if (is_string($value) === false || trim($value) === '') {
			return sprintf(
				'Canceled: %s is not set. Set "%s" in the mapping "%s", then run the synchronization again. Nothing was fetched or written.',
				$name,
				$key,
				$mapping
			);
		}

		return null;
	}//end check()

	/**
	 * The `mapping` section of a mapping, or null when it is not on this instance.
	 *
	 * @param string $mapping The mapping slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
	 */
	private function mappingValues(string $mapping): ?array {
		try {
			$found = $this->objectService->find(id: $mapping, register: 'integriq', schema: 'mapping');
		} catch (\Throwable) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		return (array)($found->getObject()['mapping'] ?? []);
	}//end mappingValues()
}//end class
