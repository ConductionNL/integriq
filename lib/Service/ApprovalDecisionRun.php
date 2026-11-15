<?php

/**
 * Integriq approval decision run.
 *
 * One authorized approve or reject while ApprovalDecisionService carries it
 * through to the suspended run.
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IUser;

/**
 * The request, the deciding user and the comment of one decision, plus the
 * outcome of resuming its run. Built by ApprovalDecisionService and never
 * handed out.
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
 */
final class ApprovalDecisionRun {

	/**
	 * The approval_request's object data.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $data;

	/**
	 * The decision comment, optional on an approve.
	 *
	 * @var string|null
	 */
	public ?string $comment = null;

	/**
	 * How resuming the run went: `success` or `error`.
	 *
	 * @var string
	 */
	public string $resumeResult = 'success';

	/**
	 * The HTTP status the resumed run answers with.
	 *
	 * @var integer
	 */
	public int $statusCode = Http::STATUS_OK;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $approvalRequest The approval_request decided on.
	 * @param IUser        $user            The deciding user.
	 */
	public function __construct(
		public readonly ObjectEntity $approvalRequest,
		public readonly IUser $user,
	) {
		$this->data = $approvalRequest->getObject();

	}//end __construct()
}//end class
