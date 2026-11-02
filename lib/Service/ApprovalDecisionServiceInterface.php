<?php

/**
 * Contract of the ApprovalDecisionService.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\ApprovalStateException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;

/**
 * Public operations of {@see ApprovalDecisionService} that other components call.
 */
interface ApprovalDecisionServiceInterface {
	/**
	 * Approve an authorized, actionable request and resume its run.
	 *
	 * The caller has already checked the action matrix, the approver group
	 * and that the request is actionable.
	 *
	 * @param ObjectEntity $approvalRequest The pending request.
	 * @param IUser $user The approving user.
	 * @param string|null $comment Optional approve comment.
	 *
	 * @return JSONResponse The resumed run's result, envelope-wrapped with `_approval`.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
	 */
	public function approve(ObjectEntity $approvalRequest, IUser $user, ?string $comment): JSONResponse;

	/**
	 * Reject an authorized, actionable request and let its run reflect it.
	 *
	 * @param ObjectEntity $approvalRequest The pending request.
	 * @param IUser $user The rejecting user.
	 * @param string $comment The mandatory rejection comment.
	 *
	 * @return ObjectEntity The rejected (or dead-lettered) request.
	 *
	 * @throws ApprovalStateException (400) When the comment is empty.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
	 */
	public function reject(ObjectEntity $approvalRequest, IUser $user, string $comment): ObjectEntity;
}
