<?php

/**
 * Integriq SubmissionRegistrationListener.
 *
 * Answers a SubmissionRegistrationRequestedEvent through RegistrationService.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\SubmissionRegistrationRequestedEvent;
use OCA\Integriq\Service\Registration\RegistrationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Writes the registration's answer on the command.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
class SubmissionRegistrationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param RegistrationService $registrations Registers the submission; never throws.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly RegistrationService $registrations,
	) {
	}//end __construct()

	/**
	 * Answer the command.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof SubmissionRegistrationRequestedEvent) === false) {
			return;
		}

		$event->setResult($this->registrations->register(app: $event->getApp(), target: $event->getTarget(), handover: $event->getHandover()));
	}//end handle()
}//end class
