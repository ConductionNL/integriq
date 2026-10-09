<?php

/**
 * Test stub: the part of Hermiq's ApprovalService its verdict service uses.
 *
 * Copied from ConductionNL/hermiq development on 1 Oct 2026 (after PR #1048): the
 * constant's value and loadApproval()'s signature. The verdict service and
 * signer beside this file are Hermiq's real classes, unchanged but for their
 * spec tags.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Hermiq's approval service, reduced to what ApprovalVerdictService calls.
 */
class ApprovalService {

	/**
	 * How long a decided toolcall approval holds, as Hermiq defines it.
	 */
	public const TOOLCALL_APPROVAL_TTL_SECONDS = 3600;

	/**
	 * Load one approval by uuid.
	 *
	 * @param string $uuid The approval uuid.
	 *
	 * @return ObjectEntity|null
	 */
	public function loadApproval(string $uuid): ?ObjectEntity {
		return null;
	}//end loadApproval()
}//end class
