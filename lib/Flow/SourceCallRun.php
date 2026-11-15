<?php

/**
 * One source-call step run, shared by every item of the page.
 *
 * Kept beside `SourceCallNode` so its passes and the per-item policy take
 * the run instead of the step's resolved values one by one. Never leaves
 * that class.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
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
 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The values one source-call step resolves once, and the item it is applying its policy to.
 *
 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
 */
final class SourceCallRun {

	/**
	 * The input items, re-indexed from zero.
	 *
	 * @var array<int, array>
	 */
	public array $items = [];

	/**
	 * The authored source reference.
	 *
	 * @var string
	 */
	public string $reference = '';

	/**
	 * The HTTP method.
	 *
	 * @var string
	 */
	public string $method = '';

	/**
	 * The item key the call outcome lands under.
	 *
	 * @var string
	 */
	public string $outputKey = '';

	/**
	 * The step's error policy.
	 *
	 * @var string
	 */
	public string $onError = '';

	/**
	 * The step id, for the error state and the log.
	 *
	 * @var string
	 */
	public string $stepId = '';

	/**
	 * The statuses the author accepts as a success.
	 *
	 * @var array
	 */
	public array $accepted = [];

	/**
	 * The response decode mode.
	 *
	 * @var string
	 */
	public string $decode = '';

	/**
	 * The rendered endpoint per item.
	 *
	 * @var array<int, string>
	 */
	public array $endpoints = [];

	/**
	 * The decoded record per item, the same one its endpoint was guarded with.
	 *
	 * @var array<int, array>
	 */
	public array $records = [];

	/**
	 * The settled call per item.
	 *
	 * @var array<int, mixed>
	 */
	public array $settled = [];

	/**
	 * The index of the item the policy is being applied to.
	 *
	 * @var int
	 */
	public int $index = 0;

	/**
	 * The source reference each item is sent through, rendered per item when templated.
	 *
	 * @var array<int, string>
	 */
	public array $references = [];

	/**
	 * The resolved Source per item, absent for an item whose reference did not resolve.
	 *
	 * @var array<int, ObjectEntity>
	 */
	public array $sources = [];

	/**
	 * Why an item's reference did not resolve, per item.
	 *
	 * @var array<int, FlowNodeException>
	 */
	public array $unresolved = [];

	/**
	 * Constructor.
	 *
	 * @param array             $config The step's authored configuration.
	 * @param ObjectEntity|null $source The Source a plain reference resolved to, or null when the
	 *                                  reference is a template resolved per item.
	 */
	public function __construct(
		public readonly array $config,
		public readonly ?ObjectEntity $source,
	) {

	}//end __construct()
}//end class
