<?php

/**
 * Integriq Flow Node Support.
 *
 * The two small run-time questions both contributed nodes have to answer, and
 * the honest answer to each given what OpenRegister's engine hands a node.
 *
 * WHY THIS EXISTS AT ALL — an upstream gap, stated rather than hidden.
 * `RegistryStepDispatcher` calls `IFlowNode::execute($items, $step['config'],
 * $context)`. It passes the step's CONFIG, not the step. So a node cannot see
 * two things it is specified to react to:
 *
 *  - the step's `id`, which every error message here is required to name; and
 *  - the step's `onError` policy, which decides whether a per-item failure
 *    should be carried on the item (`continue`) or raised (`stop` /
 *    `dead_letter`).
 *
 * Both are read here from the best source available, in order: the run context
 * first (so the moment OpenRegister starts supplying either, it wins with no
 * change here), then a node-config mirror the author may write. The config
 * mirror is documented, validated, and is the ONLY way an author can get
 * per-item error state today — a mirror that disagrees with the step's real
 * `onError` is an authoring mistake, not a security boundary, because the
 * engine still applies its own policy to anything this node raises.
 *
 * Raised upstream so this can be deleted: `IFlowNode::execute()` should receive
 * the step, or the dispatcher should put `stepId` / `onError` into `$context`.
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

use OCP\IL10N;
use UnexpectedValueException;

/**
 * Reads the step id and the `onError` policy a node needs but is not handed.
 *
 * @spec openspec/changes/integriq-flow-nodes/tasks.md#task-1-flow-node-scaffolding-guarded-registration-shared-helpers
 */
final class FlowNodeSupport {

	/**
	 * The `onError` policies a step may declare.
	 *
	 * Mirrors `FlowEngine`'s `ON_ERROR_STOP` / `ON_ERROR_CONTINUE` /
	 * `ON_ERROR_DEAD_LETTER`. Declared here rather than on a node class so
	 * reading it never pulls `IFlowNode` into scope — the whole point of the
	 * guarded registration is that Integriq's flow classes are only ever
	 * loaded on an instance whose OpenRegister actually has a flow engine.
	 *
	 * @var array<int, string>
	 */
	public const ON_ERROR_POLICIES = [
		'stop',
		'continue',
		'dead_letter',
	];

	/**
	 * The item key carrying explicit error state under `onError: continue`.
	 *
	 * Deliberately NOT the author's output key: an item that failed must be
	 * structurally distinguishable from one that succeeded, so a downstream
	 * step branching on the output key finds nothing there rather than
	 * something empty-but-plausible.
	 *
	 * @var string
	 */
	public const ERROR_KEY = '__error';

	/**
	 * The step's id, for error messages and item-borne error state.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $context The run context.
	 * @param string $nodeId The node type id, used when nothing names the step.
	 *
	 * @return string The step id, or the node id when the step is unnamed.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function stepId(array $config, array $context, string $nodeId): string {
		$candidates = [
			($context['stepId'] ?? null),
			($context['step']['id'] ?? null),
			($config['stepId'] ?? null),
			($config['id'] ?? null),
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return $nodeId;
	}//end stepId()

	/**
	 * The step's `onError` policy as the node can see it.
	 *
	 * Defaults to `stop`: a failure that nobody has explicitly asked to be
	 * survivable must not be survived quietly.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $context The run context.
	 *
	 * @return string One of `stop`, `continue`, `dead_letter`.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function onErrorPolicy(array $config, array $context): string {
		$candidates = [
			($context['step']['onError'] ?? null),
			($context['stepOnError'] ?? null),
			($config['onError'] ?? null),
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === false) {
				continue;
			}

			$policy = strtolower(trim($candidate));
			if (in_array($policy, self::ON_ERROR_POLICIES, true) === true) {
				return $policy;
			}
		}

		return 'stop';
	}//end onErrorPolicy()

	/**
	 * Reject an `onError` value outside the known policies, at flow-save time.
	 *
	 * Shared by every node that mirrors the step's `onError` policy into its
	 * config vocabulary, so the accepted spellings cannot drift between nodes.
	 *
	 * @param array $config The step's authored configuration.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value is not a known policy.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public static function assertOnError(array $config, IL10N $l10n): void {
		if (array_key_exists('onError', $config) === false) {
			return;
		}

		$policy = strtolower(trim((string)$config['onError']));
		if (in_array($policy, self::ON_ERROR_POLICIES, true) === false) {
			throw new UnexpectedValueException(
				$l10n->t(
					'The "onError" field must be one of %1$s.',
					[implode(', ', self::ON_ERROR_POLICIES)]
				)
			);
		}

	}//end assertOnError()

	/**
	 * Reject a reference field that is inline or empty, at flow-save time.
	 *
	 * Every page-level sync node names an ALREADY-CONFIGURED object — a
	 * mapping, a synchronization — and never accepts an inline definition:
	 * an object edited through its own surface is versioned, reviewable and
	 * shared; one buried in a flow document is none of those.
	 *
	 * @param array $config The step's authored configuration.
	 * @param string $key The config key carrying the reference.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the reference is inline or empty.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public static function assertReference(array $config, string $key, IL10N $l10n): void {
		$reference = ($config[$key] ?? null);
		if (is_array($reference) === true) {
			throw new UnexpectedValueException(
				$l10n->t(
					'The "%1$s" field must reference an existing %1$s; an inline definition '
					. 'is not accepted and this step never creates one.',
					[$key]
				)
			);
		}

		if (trim((string)($reference ?? '')) === '') {
			throw new UnexpectedValueException(
				$l10n->t(
					'The "%1$s" field must name a configured %1$s (its uuid, slug or reference).',
					[$key]
				)
			);
		}

	}//end assertReference()
}//end class
