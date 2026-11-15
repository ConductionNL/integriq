<?php

/**
 * Integriq CaseOperation.
 *
 * One case-system operation a source call asks for: its name and its body.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

/**
 * Value object for one case-system operation (ADR-011).
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
 */
final class CaseOperation {

	/**
	 * Constructor.
	 *
	 * @param string               $name The operation, one of CaseSystemOperations::OPERATIONS.
	 * @param array<string, mixed> $body Its JSON body.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $body,
	) {
	}//end __construct()
}//end class
