<?php

/**
 * Integriq ApprovalDecision: who may decide which approval request, once the
 * approvals gate has let them through.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * A caller cleared to decide one actionable approval request.
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
final class ApprovalDecision {
	/**
	 * Constructor.
	 *
	 * @param IUser        $user            The authorized approver.
	 * @param ObjectEntity $approvalRequest The actionable approval request.
	 */
	public function __construct(
		public readonly IUser $user,
		public readonly ObjectEntity $approvalRequest,
	) {
	}//end __construct()
}//end class
