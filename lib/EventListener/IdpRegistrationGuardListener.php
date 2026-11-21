<?php

/**
 * Integriq IdpRegistrationGuardListener.
 *
 * Refuses an identity provider registration whose own fields cannot work, on
 * OpenRegister's own save path: the Authentication settings screen writes
 * registrations through OpenRegister's object API.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stop the save of an idpRegistration that IdpRegistrationSaveCheck refuses.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
class IdpRegistrationGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IdpRegistrationSaveCheck $check Judges the object being saved.
	 */
	public function __construct(
		private readonly IdpRegistrationSaveCheck $check,
	) {

	}//end __construct()

	/**
	 * Refuse a registration that breaks a save rule, such as Yivi over SAML.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
	 */
	public function handle(Event $event): void {
		$refusal = null;
		if ($event instanceof ObjectCreatingEvent) {
			$refusal = $this->check->refusal(object: $event->getObject());
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$refusal = $this->check->refusal(object: $event->getNewObject());
		}

		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();

	}//end handle()
}//end class
