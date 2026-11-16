<?php

/**
 * Integriq RegistrationBodyCheck.
 *
 * A leg that can check a mapped body without sending it.
 *
 * @category Service
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
 * The check a leg runs on the mapped body before its first outside call.
 *
 * The admin dry run (design D6) asks it so a test shows the refusal the real
 * hand-over would meet, while still stopping before any write.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 */
interface RegistrationBodyCheck {

	/**
	 * Refuse a mapped body the target would not accept.
	 *
	 * @param array<string,mixed> $target The registration target.
	 * @param array<string,mixed> $mapped The hand-over in the outside shape.
	 *
	 * @return void
	 *
	 * @throws RegistrationRefusal When the body would be refused before sending.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	public function checkBody(array $target, array $mapped): void;
}//end interface
