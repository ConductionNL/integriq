<?php

/**
 * Read contract of the SynchronizationService: resolve a synchronization or a rule.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The lookups of {@see SynchronizationService} for callers that only read a
 * synchronization or a rule and never run one.
 */
interface SynchronizationLookupInterface {
	/**
	 * Resolve one rule by id, for callers outside this service.
	 *
	 * `SynchronizationFlowGenerator` has to know what a synchronization's
	 * `actions` ARE before it can decide whether they are expressible as flow
	 * steps — refusing every synchronization that declares any is what kept 74
	 * of them unmigratable. It needs the rule's `type` and `timing`, nothing
	 * more, and re-implementing the lookup against register `openconnector` /
	 * schema `rule` in a second place is how the two drift.
	 *
	 * ⚠️ THE READ IS DELIBERATELY UNSCOPED, and delegating to `getRuleById()`
	 * was wrong for exactly that reason. That method reads WITH rbac and
	 * multitenancy, which is right for the legacy engine's in-request rule
	 * pipeline and wrong here: the generator runs under `occ`, where there is
	 * no user session, so a scoped read filters every rule away and returns
	 * null. The generator then cannot establish what the rule does and refuses
	 * the synchronization — which is not "this rule is unsupported", it is "I
	 * could not look".
	 *
	 * MEASURED, and this is why the flag matters rather than being defensive:
	 * after `openconnector.fetch-file` shipped, a full sweep of all 240
	 * synchronizations still refused the same 74, and ALL 74 gave
	 * `actions: rule "…" could not be resolved`. Not one of them was refused
	 * for its rule's TYPE. The feature was complete and unreachable.
	 *
	 * `MigrationEntityReader` already reads this way and says why in its own
	 * docblock; this is the same rule applied to the one entity that did not go
	 * through it.
	 *
	 * @param string $id The rule's OpenRegister uuid or slug.
	 *
	 * @return array|null The rule payload, or null when there is no such rule.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function findRule(string $id): ?array;

	/**
	 * Fetch a synchronization OpenRegister object by id or other characteristics.
	 *
	 * Post-cutover, synchronizations are OpenRegister objects (register
	 * `openconnector`, schema `synchronization`); this resolves them without any
	 * caller needing to touch the OpenRegister object service directly.
	 *
	 * @param string|int|null $id The OpenRegister id (UUID) of the synchronization.
	 * @param array $filters Other filters to find the synchronization by.
	 *
	 * @return ObjectEntity The resulting synchronization object.
	 *
	 * @throws DoesNotExistException Thrown if the synchronization does not exist.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationService::getSynchronization()
	 */
	public function getSynchronization(null|string|int $id = null, array $filters = []) :ObjectEntity;
}
