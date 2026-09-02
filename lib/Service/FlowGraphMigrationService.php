<?php

/**
 * Integriq Flow Graph Migration Service.
 *
 * Applies (and rolls back) the steps-to-graph migration over the LIVE `flow`
 * objects in the register (retire-integriq-flow-schema Task 2). The
 * translation itself is `FlowStepsToGraphTranslator` and stays pure; this
 * service is the only place that reads and writes the register, so the
 * repair step and the occ command share one behaviour instead of drifting.
 *
 * THE THREE RULES
 * ---------------
 * 1. **Idempotent** — an object that already carries `nodes` is skipped, never
 *    overwritten. Running the migration twice yields the first run's result.
 * 2. **Additive** — `steps` is KEPT beside the written `nodes`/`edges`. The
 *    two engines dual-run through the migration window, and `steps` is the
 *    rollback shape: `rollback()` deletes `nodes`/`edges` and refuses to
 *    touch an object whose `steps` are gone, because that object would be
 *    left with no executable shape at all.
 * 3. **A refusal skips one flow, loudly** — a flow the translator cannot
 *    express is reported with its reasons and left exactly as it was. It
 *    keeps running on `FlowRunnerService` until it is re-modelled by hand.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/retire-integriq-flow-schema/specs/flow-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, translates and rewrites live `flow` objects, both directions.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$apply` is the dry-run/write
 * switch the occ command exposes as its own flag — the same
 * dry-run-by-default contract MigrateInlineSecrets and DedupeContracts
 * already carry (both suppress this rule for the same reason).
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
 */
class FlowGraphMigrationService {

	/**
	 * Result marker: the flow's graph was written (or would be, on a dry run).
	 *
	 * @var string
	 */
	public const MIGRATED = 'migrated';

	/**
	 * Result marker: the flow already carries `nodes` and was left alone.
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Result marker: the translator refused the flow; reasons attached.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * Result marker: the graph was removed (or would be, on a dry run).
	 *
	 * @var string
	 */
	public const ROLLED_BACK = 'rolled_back';

	/**
	 * Constructor.
	 *
	 * @param FlowStepsToGraphTranslator $translator The pure steps-to-graph translation.
	 * @param OrObjectService $orObjectService OpenRegister object persistence.
	 * @param LoggerInterface $logger Migration diagnostics.
	 */
	public function __construct(
		private readonly FlowStepsToGraphTranslator $translator,
		private readonly OrObjectService $orObjectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Translate every live `flow` object's steps into a graph, in place.
	 *
	 * @param bool $apply False (the default posture for the occ command) reports
	 *                    what WOULD happen without writing anything.
	 *
	 * @return array<int, array{id: string, name: string, action: string, reasons: array<int, string>}> One row per flow.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function migrate(bool $apply = false): array {
		$report = [];

		foreach ($this->liveFlows() as $flow) {
			$data = $flow->getObject();
			$row = [
				'id' => (string)$flow->getUuid(),
				'name' => (string)($data['name'] ?? ''),
				'action' => self::MIGRATED,
				'reasons' => [],
			];

			if (empty($data['nodes']) === false) {
				// Idempotence: a graph already written (by this migration or
				// by hand) is never overwritten — refusing is the rule the
				// tasks file states verbatim.
				$row['action'] = self::SKIPPED;
				$report[] = $row;
				continue;
			}

			try {
				$graph = $this->translator->translate(flow: $data);
			} catch (EntityNotMigratableException $e) {
				$row['action'] = self::REFUSED;
				$row['reasons'] = $e->getReasons();
				$this->logger->warning(
					'FlowGraphMigrationService: flow refused by the translator: ' . $e->getMessage(),
					['flowId' => $row['id'], 'reasons' => $e->getReasons()]
				);
				$report[] = $row;
				continue;
			}

			if ($apply === true) {
				$data['nodes'] = $graph['nodes'];
				$data['edges'] = $graph['edges'];
				$this->orObjectService->saveObject(
					object: $data,
					register: FlowRunnerService::REGISTER,
					schema: FlowRunnerService::SCHEMA_FLOW,
					uuid: (string)$flow->getUuid()
				);
			}

			$report[] = $row;
		}//end foreach

		return $report;

	}//end migrate()

	/**
	 * Remove the written graph from every live `flow` object, in place.
	 *
	 * The rollback of a migration whose forward direction is additive: it
	 * deletes `nodes`/`edges` and leaves `steps` as the only shape again. A
	 * flow whose `steps` are gone is REFUSED — deleting its graph would
	 * leave nothing executable at all, and how its steps vanished is a
	 * question for a person, not a rollback.
	 *
	 * @param bool $apply False reports what WOULD happen without writing.
	 *
	 * @return array<int, array{id: string, name: string, action: string, reasons: array<int, string>}> One row per flow.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function rollback(bool $apply = false): array {
		$report = [];

		foreach ($this->liveFlows() as $flow) {
			$data = $flow->getObject();
			$row = [
				'id' => (string)$flow->getUuid(),
				'name' => (string)($data['name'] ?? ''),
				'action' => self::ROLLED_BACK,
				'reasons' => [],
			];

			if (empty($data['nodes']) === true && empty($data['edges']) === true) {
				$row['action'] = self::SKIPPED;
				$report[] = $row;
				continue;
			}

			if (empty($data['steps']) === true) {
				$row['action'] = self::REFUSED;
				$row['reasons'] = ['The flow has a graph but no steps; removing the graph would leave it with no executable shape.'];
				$report[] = $row;
				continue;
			}

			if ($apply === true) {
				unset($data['nodes'], $data['edges']);
				$this->orObjectService->saveObject(
					object: $data,
					register: FlowRunnerService::REGISTER,
					schema: FlowRunnerService::SCHEMA_FLOW,
					uuid: (string)$flow->getUuid()
				);
			}

			$report[] = $row;
		}//end foreach

		return $report;

	}//end rollback()

	/**
	 * Every live `flow` object, unfiltered by tenancy — a migration walks
	 * the whole table or it is not a migration.
	 *
	 * @return array<int, ObjectEntity> The flow objects.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function liveFlows(): array {
		try {
			$matches = $this->orObjectService->findAll(
				config: [
					'filters' => [
						'register' => FlowRunnerService::REGISTER,
						'schema' => FlowRunnerService::SCHEMA_FLOW,
					],
					'limit' => 1000,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// A register that does not exist yet (fresh install ordering) has
			// no flows to migrate; that is a no-op, not a failure.
			$this->logger->info(
				'FlowGraphMigrationService: could not list flows (register not initialised yet?): ' . $e->getMessage()
			);

			return [];
		}

		$results = ($matches['results'] ?? $matches);

		return array_values(
			array_filter(
				(array)$results,
				static fn ($row): bool => $row instanceof ObjectEntity
			)
		);

	}//end liveFlows()
}//end class
