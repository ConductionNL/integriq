<?php

/**
 * Integriq ZgwRegistrationRun.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One registration at a ZGW target: the target's settings and the steps done,
 * so far and by earlier hand-overs of the same submission.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

/**
 * The settings and steps of one ZGW registration.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
 */
final class ZgwRegistrationRun {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>            $settings The target's ZGW settings.
	 * @param array<int,array<string,mixed>> $steps    The steps done, oldest first.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function __construct(
		public readonly array $settings,
		private array $steps,
	) {
	}//end __construct()

	/**
	 * The step of that name already done, or null.
	 *
	 * @param string $step The step name.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function done(string $step): ?array {
		foreach ($this->steps as $done) {
			if (($done['step'] ?? null) === $step) {
				return $done;
			}
		}

		return null;
	}//end done()

	/**
	 * Record a step as done.
	 *
	 * @param array<string,mixed> $step The step, with its `step` name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function record(array $step): void {
		$this->steps[] = $step;
	}//end record()

	/**
	 * Every step done, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function steps(): array {
		return $this->steps;
	}//end steps()
}//end class
