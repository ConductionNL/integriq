<?php

/**
 * Integriq SynchronizationStepRun.
 *
 * State of one execution of the `openconnector.synchronization-run` step: the
 * authored settings it reads once, the resolved synchronization, the input
 * item in hand and the items emitted so far.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Run object built by {@see SynchronizationRunNode}; never leaves that class.
 */
final class SynchronizationStepRun {

	/**
	 * The authored synchronization reference, trimmed.
	 *
	 * @var string
	 */
	public string $reference = '';

	/**
	 * The author-named output key.
	 *
	 * @var string
	 */
	public string $outputKey = '';

	/**
	 * Whether the unchanged-object skip is bypassed.
	 *
	 * @var boolean
	 */
	public bool $force = false;

	/**
	 * The fan-out ceiling.
	 *
	 * @var integer
	 */
	public int $maxItems = 0;

	/**
	 * The step's error policy.
	 *
	 * @var string
	 */
	public string $onError = '';

	/**
	 * The step id.
	 *
	 * @var string
	 */
	public string $stepId = '';

	/**
	 * The resolved synchronization.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $synchronization = null;

	/**
	 * The input item being processed.
	 *
	 * @var array
	 */
	public array $item = [];

	/**
	 * The index of the input item being processed.
	 *
	 * @var integer
	 */
	public int $index = 0;

	/**
	 * The counts of the synchronisation run for the item in hand.
	 *
	 * @var array
	 */
	public array $summary = [];

	/**
	 * Every output item emitted so far, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $emitted = [];
}//end class
