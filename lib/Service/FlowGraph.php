<?php

/**
 * Integriq FlowGraph: the graph FlowStepsToGraphTranslator is building — its
 * nodes and edges so far, the node the next step chains from, and the node
 * the step being added falls through to.
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
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One graph in progress. Built and used by FlowStepsToGraphTranslator only.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
 */
final class FlowGraph {
	/**
	 * The nodes so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $nodes = [];

	/**
	 * The edges so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $edges = [];

	/**
	 * The node the next step chains from sequentially, or null after a branch.
	 *
	 * @var string|null
	 */
	public ?string $previousId = null;

	/**
	 * The node the step being added falls through to: the next step, or the end.
	 *
	 * @var string
	 */
	public string $nextId = '';
}//end class
