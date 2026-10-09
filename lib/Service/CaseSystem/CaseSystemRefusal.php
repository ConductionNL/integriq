<?php

/**
 * A case-system operation refused, with the status the caller receives.
 *
 * @category Service
 * @package  OCA\Integriq\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

use RuntimeException;

/**
 * A refusal: its message is shown to the person who asked, so it names what
 * to fix and never carries a remote body.
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
 */
class CaseSystemRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param integer $status  The HTTP status the operation answers.
	 * @param string  $message The message the caller shows.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	public function __construct(
		private readonly int $status,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status the operation answers.
	 *
	 * @return integer
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
