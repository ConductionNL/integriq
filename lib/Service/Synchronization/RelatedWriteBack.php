<?php

/**
 * After a run wrote its objects, moves the object they point at to its next state.
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

use Adbar\Dot;
use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a pull's `targetConfig.relatedWriteBack` and applies it once per run.
 *
 * `relatedWriteBack` is `{schema: "register/schema", field: "<property of a
 * written object holding the related object's id>", when: {field: value},
 * set: {field: value}}`. After the run, each related object that the written
 * objects name and whose fields equal `when` gets `set`, filled like a push's
 * write-back with `{{ now }}` (the time, ISO 8601) and `{{ count }}` (how many
 * written objects name it).
 *
 * Applied after the whole run, not per object, so an app listening for the
 * transition sees every object the run brought in. The save is a normal one,
 * so that listener runs. A related object that cannot be read or saved is
 * logged and never fails the run, whose writes already happened.
 *
 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-pull-can-move-the-object-its-written-objects-point-at-req-hrx-004
 */
class RelatedWriteBack {

	/**
	 * Constructor.
	 *
	 * @param OrObjectService  $objectService OpenRegister's object service.
	 * @param LoggerInterface  $logger        Where a failed write-back is recorded.
	 * @param OutcomeWriteBack $templates     Fills the `set` templates.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly OutcomeWriteBack $templates = new OutcomeWriteBack(),
	) {
	}//end __construct()

	/**
	 * Apply the declared write-back for the objects one run wrote.
	 *
	 * @param array<string, mixed> $synchronization The pull synchronization.
	 * @param list<mixed>          $targetIds       The ids of the target objects the run wrote.
	 *
	 * @return int How many related objects were moved.
	 *
	 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-pull-can-move-the-object-its-written-objects-point-at-req-hrx-004
	 */
	public function apply(array $synchronization, array $targetIds): int {
		$config = $this->config(synchronization: $synchronization);
		$target = explode('/', (string)($synchronization['targetId'] ?? ''));
		if ($config === null || $targetIds === [] || ($synchronization['targetType'] ?? null) !== 'register/schema' || count($target) !== 2) {
			return 0;
		}

		$counts = $this->relatedIds(register: $target[0], schema: $target[1], field: $config['field'], targetIds: $targetIds);
		$moved  = 0;
		foreach ($counts as $relatedId => $count) {
			if ($this->move(config: $config, relatedId: (string)$relatedId, count: $count) === true) {
				$moved++;
			}
		}

		return $moved;
	}//end apply()

	/**
	 * The declaration, or null when there is none or it is malformed.
	 *
	 * @param array<string, mixed> $synchronization The synchronization.
	 *
	 * @return array{register: string, schema: string, field: string, when: array<string, mixed>, set: array<string, mixed>}|null
	 */
	private function config(array $synchronization): ?array {
		$targetConfig = ($synchronization['targetConfig'] ?? null);
		if (is_array($targetConfig) === false || is_array($targetConfig['relatedWriteBack'] ?? null) === false) {
			return null;
		}

		$declared = $targetConfig['relatedWriteBack'];
		$schema   = explode('/', (string)($declared['schema'] ?? ''));
		$field    = (string)($declared['field'] ?? '');
		$set      = ($declared['set'] ?? null);
		$when     = ($declared['when'] ?? []);
		if ($this->wellFormed(schema: $schema, field: $field, set: $set, when: $when) === false) {
			$this->logger->warning(
				'Synchronization ' . (string)($synchronization['id'] ?? '')
				. ': relatedWriteBack needs a schema as "register/schema", a field and a set; it is ignored.'
			);
			return null;
		}

		return ['register' => $schema[0], 'schema' => $schema[1], 'field' => $field, 'when' => $when, 'set' => $set];
	}//end config()

	/**
	 * Whether a declaration names a register and schema, a field and something to set.
	 *
	 * @param list<string> $schema The schema, split on `/`.
	 * @param string       $field  The property holding the related id.
	 * @param mixed        $set    The fields to set.
	 * @param mixed        $when   The required values.
	 *
	 * @return bool
	 */
	private function wellFormed(array $schema, string $field, mixed $set, mixed $when): bool {
		if (count($schema) !== 2 || $schema[0] === '' || $schema[1] === '' || $field === '') {
			return false;
		}

		return is_array($set) === true && $set !== [] && is_array($when) === true;
	}//end wellFormed()

	/**
	 * Each related id the written objects name, with how many name it.
	 *
	 * @param string      $register  The target register.
	 * @param string      $schema    The target schema.
	 * @param string      $field     The property holding the related id.
	 * @param list<mixed> $targetIds The written objects.
	 *
	 * @return array<string, int>
	 */
	private function relatedIds(string $register, string $schema, string $field, array $targetIds): array {
		$counts = [];
		foreach (array_unique(array_map('strval', $targetIds)) as $targetId) {
			$data = $this->read(id: $targetId, register: $register, schema: $schema);
			$relatedId = (new Dot($data ?? []))->get($field);
			if (is_scalar($relatedId) === false || (string)$relatedId === '') {
				continue;
			}

			$counts[(string)$relatedId] = (($counts[(string)$relatedId] ?? 0) + 1);
		}

		return $counts;
	}//end relatedIds()

	/**
	 * Move one related object when it is in the state `when` names.
	 *
	 * @param array<string, mixed> $config    The declaration: register, schema, field, when and set.
	 * @param string               $relatedId The related object's id.
	 * @param int                  $count     How many written objects name it.
	 *
	 * @return bool Whether it was moved.
	 */
	private function move(array $config, string $relatedId, int $count): bool {
		$data = $this->read(id: $relatedId, register: $config['register'], schema: $config['schema']);
		if ($data === null || $this->holds(when: $config['when'], data: $data) === false) {
			return false;
		}

		$fields = $this->templates->fields(
			writeBack: [OutcomeWriteBack::SUCCESS => $config['set']],
			outcome: OutcomeWriteBack::SUCCESS,
			context: ['now' => (new DateTimeImmutable())->format(DATE_ATOM), 'count' => $count]
		);

		try {
			$this->objectService->saveObject(
				object: array_merge($data, $fields),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $config['register'], schema: $config['schema']),
					uuid: $relatedId
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Could not write back onto related object ' . $relatedId . ': ' . $e->getMessage());
			return false;
		}

		return true;
	}//end move()

	/**
	 * Whether every `when` field holds its value.
	 *
	 * @param array<string, mixed> $when The required values.
	 * @param array<string, mixed> $data The related object.
	 *
	 * @return bool
	 */
	private function holds(array $when, array $data): bool {
		$dot = new Dot($data);
		foreach ($when as $field => $value) {
			if ($dot->get((string)$field) !== $value) {
				return false;
			}
		}

		return true;
	}//end holds()

	/**
	 * One object's data, or null when it cannot be read.
	 *
	 * @param string $id       The object's id.
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $id, string $register, string $schema): ?array {
		try {
			$entity = $this->objectService->find(id: $id, options: new ObjectFindOptions(register: $register, schema: $schema));
		} catch (Throwable $e) {
			$this->logger->warning('Could not read object ' . $id . ' for a related write-back: ' . $e->getMessage());
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->getObject();
	}//end read()
}//end class
