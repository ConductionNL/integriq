<?php

/**
 * Reads whether an object records a write-back its target refused.
 *
 * A write-back synchronization that declares
 * `targetConfig.conflictStatusProperty` keeps a refused local edit and marks
 * the object `conflict` there (SynchronizationService::markWriteBack). The
 * "Synced from" leaf shows that mark; this class answers it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Integration;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Answers whether an object records a refused write-back.
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */
class WriteBackConflictReader {

	/**
	 * The value a write-back synchronization records when the target refused the change.
	 *
	 * Mirrors SynchronizationService::WRITE_BACK_CONFLICT.
	 */
	private const WRITE_BACK_CONFLICT = 'conflict';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * Whether the object records a write-back the connected system refused.
	 *
	 * A write-back synchronization that declares
	 * `targetConfig.conflictStatusProperty` keeps a refused local edit and sets
	 * that property on the object to `conflict`; the next accepted push sets it
	 * to `synced`. Every declared property name is checked, so this needs no
	 * match between the route's register and schema (slug or id) and the
	 * binding the installer wrote. Any read failure answers false: the tab
	 * then shows its rows without the notice.
	 *
	 * @param string $register The object's register (slug or id).
	 * @param string $schema   The object's schema (slug or id).
	 * @param string $objectId The object uuid.
	 *
	 * @return bool True when a declared conflict property holds `conflict`.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	public function hasConflict(string $register, string $schema, string $objectId): bool {
		$properties = $this->conflictStatusProperties();
		if ($properties === []) {
			return false;
		}

		try {
			$object = $this->objectService->find(id: $objectId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return false;
		}

		if (is_object($object) === false || method_exists($object, 'getObject') === false) {
			return false;
		}

		$data = (array)$object->getObject();
		foreach ($properties as $property) {
			if (($data[$property] ?? null) === self::WRITE_BACK_CONFLICT) {
				return true;
			}
		}

		return false;
	}//end hasConflict()

	/**
	 * The property names write-back synchronizations record a refusal in.
	 *
	 * @return list<string> The distinct `targetConfig.conflictStatusProperty` values.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function conflictStatusProperties(): array {
		try {
			$found = $this->objectService
				->setRegister('integriq')
				->setSchema('synchronization')
				->findAll(config: ['filters' => ['sourceType' => 'register/schema']]);
		} catch (\Throwable $e) {
			return [];
		}

		$properties = [];
		foreach ((array)($found['results'] ?? $found) as $synchronization) {
			$body = [];
			if (is_object($synchronization) === true && method_exists($synchronization, 'getObject') === true) {
				$body = (array)$synchronization->getObject();
			} else if (is_array($synchronization) === true) {
				$body = (array)($synchronization['object'] ?? $synchronization);
			}

			$property = ($body['targetConfig']['conflictStatusProperty'] ?? null);
			if (is_string($property) === true && $property !== '') {
				$properties[$property] = true;
			}
		}

		return array_keys($properties);
	}//end conflictStatusProperties()
}//end class
