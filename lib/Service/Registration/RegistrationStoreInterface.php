<?php

/**
 * Contract of the RegistrationStore.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

/**
 * Public operations of {@see RegistrationStore} that other components call.
 */
interface RegistrationStoreInterface {
	/**
	 * Every registration target.
	 *
	 * @return array<int,array<string,mixed>> The targets' data.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	public function findTargets(): array;
}
