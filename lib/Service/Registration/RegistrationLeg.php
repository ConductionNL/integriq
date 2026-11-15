<?php

/**
 * Integriq RegistrationLeg.
 *
 * How one kind of outside system registers a submission.
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
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

/**
 * One kind of registration target (design D4).
 *
 * A leg is handed the steps an earlier, unfinished hand-over of the same
 * submission already did, and does not do them again (design D5).
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
interface RegistrationLeg {

	/**
	 * The target kind this leg registers at.
	 *
	 * @return string One of zgw, objects-api, stuf-zds, json.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function kind(): string;

	/**
	 * Register one submission.
	 *
	 * @param array<string,mixed>            $target   The registration target.
	 * @param array<string,mixed>            $mapped   The hand-over in the outside shape, from the target's mapping.
	 * @param array<string,mixed>            $handover The hand-over as the app gave it.
	 * @param array<int,array<string,mixed>> $steps    What an earlier hand-over of this submission already did.
	 *
	 * @return array{externalReference:string,externalUrl:string,steps:array<int,array<string,mixed>>} The result and every step done.
	 *
	 * @throws RegistrationRefusal When the submission is not registered.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function register(array $target, array $mapped, array $handover, array $steps): array;
}//end interface
