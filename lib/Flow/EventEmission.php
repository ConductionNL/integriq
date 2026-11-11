<?php

/**
 * One event-emit step run, and the item it is emitting.
 *
 * Kept beside `EventEmitNode` so the per-item emit takes the run instead of
 * the configuration, step id, error policy, output key and the item's record
 * one by one. Never leaves that class; the event pipeline stays with the node.
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
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

/**
 * The values one event-emit step resolves once, and the item being emitted.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
 */
final class EventEmission {

	/**
	 * The step id, for error messages.
	 *
	 * @var string
	 */
	public string $stepId = '';

	/**
	 * The step's error policy.
	 *
	 * @var string
	 */
	public string $onError = '';

	/**
	 * The key the emit summary lands under.
	 *
	 * @var string
	 */
	public string $outputKey = 'eventResult';

	/**
	 * The input item being emitted.
	 *
	 * @var mixed
	 */
	public mixed $item = null;

	/**
	 * The input item's position.
	 *
	 * @var int|string
	 */
	public int|string $index = 0;

	/**
	 * The record of the item being emitted.
	 *
	 * @var array
	 */
	public array $json = [];

	/**
	 * Constructor.
	 *
	 * @param array $config The step's authored configuration.
	 */
	public function __construct(
		public readonly array $config,
	) {

	}//end __construct()
}//end class
