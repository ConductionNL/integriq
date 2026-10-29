<?php

/**
 * The working state of one deleteInvalidObjects() pass.
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

use OCA\Integriq\Service\Ownership\DisappearanceApplier;

/**
 * Carries the inputs, the disappearance policy and the guard outcome of one
 * deletion pass between the stages of SynchronizationService::deleteInvalidObjects().
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One field per value the pass's stages share.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
 */
class DeletionRun {

	/**
	 * The synchronization being cleaned up.
	 *
	 * @var array
	 */
	public array $synchronization = [];

	/**
	 * The synchronization id, falling back to its uuid.
	 *
	 * @var mixed
	 */
	public mixed $synchronizationId = null;

	/**
	 * The dot-expanded source config of the synchronization.
	 *
	 * @var array
	 */
	public array $sourceConfig = [];

	/**
	 * Target ids that are still valid in the source.
	 *
	 * @var array|null
	 */
	public ?array $synchronizedTargetIds = [];

	/**
	 * Whether deletion is restricted to the identifiers in $data.
	 *
	 * @var boolean
	 */
	public bool $deleteRestriction = false;

	/**
	 * The data checked when deletion is restricted.
	 *
	 * @var array
	 */
	public array $data = [];

	/**
	 * Whether the deletion-ratio guard is overridden.
	 *
	 * @var boolean
	 */
	public bool $forceDeletion = false;

	/**
	 * The guard outcome reported to the caller.
	 *
	 * @var array|null
	 */
	public ?array $guardInfo = null;

	/**
	 * The declared disappearance policy.
	 *
	 * @var string
	 */
	public string $policy = '';

	/**
	 * The applier of the disappearance policy.
	 *
	 * @var DisappearanceApplier
	 */
	public DisappearanceApplier $applier;

	/**
	 * The values a non-deleting policy writes onto a retired object.
	 *
	 * @var array
	 */
	public array $values = [];

	/**
	 * ISO timestamp of this pass.
	 *
	 * @var string
	 */
	public string $runAt = '';

	/**
	 * The target register id.
	 *
	 * @var string
	 */
	public string $registerId = '';

	/**
	 * The target schema id.
	 *
	 * @var string
	 */
	public string $schemaId = '';

	/**
	 * Objects ended and flagged by a non-deleting policy.
	 *
	 * @var array<string,int>
	 */
	public array $policyCounts = ['ended' => 0, 'flagged' => 0];

	/**
	 * The purge outcome under the `purge` policy.
	 *
	 * @var array
	 */
	public array $purgeInfo = ['purgedCount' => 0, 'purgeRefusals' => []];
}//end class
