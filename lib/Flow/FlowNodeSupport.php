<?php
/**
 * OpenConnector Flow Node Support.
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
 * @package  OCA\OpenConnector\Flow
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
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Flow;

/**
 * Reads the step id and the `onError` policy a node needs but is not handed.
 *
 * @spec openspec/changes/openconnector-flow-nodes/tasks.md#task-1-flow-node-scaffolding-guarded-registration-shared-helpers
 */
final class FlowNodeSupport
{

    /**
     * The `onError` policies a step may declare.
     *
     * Mirrors `FlowEngine`'s `ON_ERROR_STOP` / `ON_ERROR_CONTINUE` /
     * `ON_ERROR_DEAD_LETTER`. Declared here rather than on a node class so
     * reading it never pulls `IFlowNode` into scope — the whole point of the
     * guarded registration is that OpenConnector's flow classes are only ever
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
     * @param array  $config  The step's authored configuration.
     * @param array  $context The run context.
     * @param string $nodeId  The node type id, used when nothing names the step.
     *
     * @return string The step id, or the node id when the step is unnamed.
     *
     * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
     */
    public static function stepId(array $config, array $context, string $nodeId): string
    {
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
     * @param array $config  The step's authored configuration.
     * @param array $context The run context.
     *
     * @return string One of `stop`, `continue`, `dead_letter`.
     *
     * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
     */
    public static function onErrorPolicy(array $config, array $context): string
    {
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
}//end class
