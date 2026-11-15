<?php

/**
 * Integriq FlowStep: one step of a legacy flow being translated to a graph —
 * its order, type, config block and referenced entity.
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
 * One legacy flow step, read the way FlowStepsToGraphTranslator reads it.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
 */
final class FlowStep {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $step The step definition.
	 */
	public function __construct(
		public readonly array $step,
	) {
	}//end __construct()

	/**
	 * The step's order.
	 *
	 * @return integer
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function order(): int {
		return (int)($this->step['order'] ?? 0);
	}//end order()

	/**
	 * The step's type.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function type(): string {
		return (string)($this->step['type'] ?? '');
	}//end type()

	/**
	 * The step's config block.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function config(): array {
		return (array)($this->step['config'] ?? []);
	}//end config()

	/**
	 * The entity the step references, trimmed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function configRef(): string {
		return trim((string)($this->step['configRef'] ?? ''));
	}//end configRef()
}//end class
