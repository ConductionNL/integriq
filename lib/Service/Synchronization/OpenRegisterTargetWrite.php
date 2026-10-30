<?php

/**
 * One write of a synchronised object into an OpenRegister register and schema.
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

/**
 * Carries the contract, the synchronization, the target object and the target
 * register and schema between the stages of
 * SynchronizationService::updateTargetOpenRegister(), so each stage names the
 * write instead of every value it needs.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
class OpenRegisterTargetWrite {

	/**
	 * The dot-expanded source config.
	 *
	 * @var array
	 */
	public array $sourceConfig = [];

	/**
	 * The target register id.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The target schema id.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * Whether the contract pointed at a target before this write.
	 *
	 * @var bool
	 */
	public bool $hadTargetId = false;

	/**
	 * The mapped object to write, once its related origin ids are replaced.
	 *
	 * @var array
	 */
	public array $targetObject = [];

	/**
	 * Constructor.
	 *
	 * @param array $contract        The synchronization contract being updated.
	 * @param array $synchronization The synchronization being run.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function __construct(
		public array $contract,
		public readonly array $synchronization,
	) {
	}//end __construct()
}//end class
