<?php

/**
 * One event-emit step run, shared by every item of the page.
 *
 * Kept beside `EventEmitNode` so the per-item emit takes the run and the
 * item's record instead of the six values the step resolves once.
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

use OCA\Integriq\Service\EventServiceInterface;

/**
 * The values one event-emit step resolves once and every item uses.
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
	 * Constructor.
	 *
	 * @param EventServiceInterface $eventService The resolved event pipeline.
	 * @param array                 $config       The step's authored configuration.
	 */
	public function __construct(
		public readonly EventServiceInterface $eventService,
		public readonly array $config,
	) {

	}//end __construct()
}//end class
