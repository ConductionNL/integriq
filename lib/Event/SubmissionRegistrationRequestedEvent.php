<?php

/**
 * Integriq SubmissionRegistrationRequested Event.
 *
 * The typed cross-app command a sibling app (portaliq) dispatches to register a
 * form submission at a registration target. Integriq answers on the event.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Register this submission at that target" (design D2).
 *
 * The caller guards the dispatch with class_exists() and reads an event that
 * nothing answered (`isHandled()` false) as a retryable refusal. The answer is
 * {ok: true, externalReference, externalUrl} or {ok: false, code, retryable,
 * message, status}; the listener never throws to the caller.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
class SubmissionRegistrationRequestedEvent extends Event {

	/**
	 * The answer, once handled.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param string              $app      The calling app id (e.g. portaliq).
	 * @param string              $target   The registration target slug.
	 * @param array<string,mixed> $handover The submission: reference, form, submittedAt, language, applicant,
	 *                                      answers, fields, pdf and attachments as file ids, cosign.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly string $app,
		private readonly string $target,
		private readonly array $handover,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The calling app id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function getApp(): string {
		return $this->app;
	}//end getApp()

	/**
	 * The registration target slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function getTarget(): string {
		return $this->target;
	}//end getTarget()

	/**
	 * The hand-over.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function getHandover(): array {
		return $this->handover;
	}//end getHandover()

	/**
	 * Write the answer.
	 *
	 * @param array<string,mixed> $result The answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function setResult(array $result): void {
		$this->result = $result;
	}//end setResult()

	/**
	 * The answer, or null when nothing handled the command.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Whether integriq answered.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}//end isHandled()
}//end class
