<?php

/**
 * Integriq Engine Signal Service.
 *
 * Delivers a resolved approval decision to a suspended OpenRegister engine
 * flow run, guarded (retire-integriq-flow-schema Task 1). One small service
 * rather than a controller method, because the guard and the payload shape
 * are a contract — the ApprovalsController's approve and reject paths, and
 * any later caller (the expiry sweep, say), must deliver the identical
 * signal or the approval node starts meaning different things per caller.
 *
 * Delivery is BEST-EFFORT by design. The approval_request record is the
 * system of record and the node's heartbeat re-reads it, so a failed
 * delivery — OpenRegister mid-upgrade, the run already woken, the signal
 * service predating this integriq — costs one heartbeat, never the decision.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/retire-integriq-flow-schema/specs/flow-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guarded `FlowRunSignalService::signalAs()` delivery for approval decisions.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
 */
class EngineSignalService {

	/**
	 * The engine's signal service, referenced by NAME only.
	 *
	 * The `BrokeredCallService::BROKER_CLASS` idiom: a string keeps the
	 * compile-time reference out of this app, so Integriq keeps working
	 * against an OpenRegister that predates the signal service.
	 *
	 * @var string
	 */
	private const SIGNAL_SERVICE_CLASS = 'OCA\\OpenRegister\\Service\\Flow\\FlowRunSignalService';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Delivery diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Deliver a decision to the suspended engine run an approval_request gates.
	 *
	 * Uses `FlowRunSignalService::signalAs()` so the engine's own assignee
	 * guard applies — the same audience check Integriq already made through
	 * `isAuthorizedApprover()`, enforced a second time by the engine against
	 * the resume slot's recorded approver group.
	 *
	 * @param array $data The approval_request's object data (`engineRunUuid`/`signalNodeId`).
	 * @param string $decision `approved` or `rejected`.
	 * @param IUser $user The deciding user.
	 * @param string|null $comment Optional decision comment.
	 *
	 * @return boolean True when the signal was delivered.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function deliver(array $data, string $decision, IUser $user, ?string $comment): bool {
		if (class_exists(self::SIGNAL_SERVICE_CLASS) === false) {
			$this->logger->warning(
				'EngineSignalService: OpenRegister has no FlowRunSignalService; the engine run resumes on its next heartbeat instead',
				['engineRunUuid' => ($data['engineRunUuid'] ?? '')]
			);
			return false;
		}

		$nodeId = trim((string)($data['signalNodeId'] ?? ''));
		if ($nodeId === '') {
			$nodeId = null;
		}

		try {
			\OCP\Server::get(self::SIGNAL_SERVICE_CLASS)->signalAs(
				runUuid: (string)($data['engineRunUuid'] ?? ''),
				payload: [
					'decision' => $decision,
					'decidedBy' => $user->getUID(),
					'comment' => (string)($comment ?? ''),
				],
				actorUid: $user->getUID(),
				nodeId: $nodeId
			);

			return true;
		} catch (Throwable $e) {
			// NOT_SUSPENDED and RUN_NOT_FOUND included: the record already
			// carries the decision, so the node's heartbeat picks it up.
			$this->logger->warning(
				'EngineSignalService: could not signal engine run, it will resume on its heartbeat: ' . $e->getMessage(),
				['engineRunUuid' => ($data['engineRunUuid'] ?? ''), 'exception' => $e]
			);

			return false;
		}//end try

	}//end deliver()
}//end class
