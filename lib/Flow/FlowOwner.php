<?php

/**
 * Integriq Flow Owner.
 *
 * Fail-closed identity resolution for the node types Integriq contributes
 * to OpenRegister's flow engine.
 *
 * Every outbound call and every synchronisation a flow performs executes in the
 * identity context of the FLOW RUN's owner, read from `context['triggeredBy']`.
 * When that value is absent, empty, or names a user that no longer resolves,
 * this class RAISES. It has no fallback to an administrator, to the Source's
 * creator, to a last-known user, or to no user at all — an anonymous
 * authenticated outbound call is strictly worse than a loud refusal, because it
 * is untraceable in the CallLog and unbounded by the run owner's permissions.
 *
 * There is deliberately NO owner override read from node configuration. A flow
 * document is authored by anyone who may edit flows; letting it name the
 * identity a call runs as would be an authoring-time privilege escalation.
 * `HermiqAgentNode` does exactly that (`$config['owner'] ?? $context['triggeredBy']`)
 * and is the anti-pattern this class exists to avoid, not the template.
 *
 * Agent-dispatched flow runs are currently unattributed upstream
 * (ConductionNL/openregister#2158). Until that lands, agent-triggered flows
 * using these nodes fail closed here, by design.
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
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Throwable;

/**
 * Resolves and applies the flow run's owner, or refuses.
 *
 * @spec openspec/changes/openconnector-flow-nodes/tasks.md#task-1-flow-node-scaffolding-guarded-registration-shared-helpers
 */
class FlowOwner {

	/**
	 * The run-context key carrying the run's owner.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'triggeredBy';

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Resolves a user id to a user.
	 * @param IUserSession $userSession Carries the acting identity for the call.
	 * @param IL10N $l10n Translations for the refusal messages.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Resolve the flow run's owner, or refuse.
	 *
	 * @param array $context The run context handed to the node.
	 * @param string $nodeId The node type id, for the refusal message.
	 *
	 * @return IUser The resolved run owner.
	 *
	 * @throws FlowNodeException When the run is unattributed or the user is gone.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function resolve(array $context, string $nodeId): IUser {
		$ownerId = trim((string)($context[self::CONTEXT_KEY] ?? ''));
		if ($ownerId === '') {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The "%1$s" step refuses to run: this flow run is unattributed — its context carries no '
					. '"%2$s" owner. No fallback identity is used.',
					[$nodeId, self::CONTEXT_KEY]
				),
				details: ['kind' => 'unattributed', 'node' => $nodeId]
			);
		}

		$user = $this->userManager->get($ownerId);
		if ($user === null) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The "%1$s" step refuses to run: the flow run owner "%2$s" cannot be resolved to an existing user.',
					[$nodeId, $ownerId]
				),
				details: ['kind' => 'unattributed', 'node' => $nodeId, 'owner' => $ownerId]
			);
		}

		return $user;
	}//end resolve()

	/**
	 * Run a callback in the resolved owner's identity context.
	 *
	 * The prior session user is captured and restored in a `finally`, so an
	 * identity never bleeds out of one flow step into the next one — the same
	 * hazard `JobService` fixed for cron passes (integriq#1006).
	 *
	 * @param IUser $user The identity to run as.
	 * @param callable $callback The work to perform.
	 *
	 * @return mixed Whatever the callback returned.
	 *
	 * @throws Throwable Whatever the callback threw, unchanged.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function runAs(IUser $user, callable $callback): mixed {
		$priorSessionUser = $this->userSession->getUser();
		$this->userSession->setUser($user);

		try {
			return $callback();
		} finally {
			$this->userSession->setUser($priorSessionUser);
		}

	}//end runAs()
}//end class
