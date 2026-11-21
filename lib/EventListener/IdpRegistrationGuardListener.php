<?php

/**
 * Integriq IdpRegistrationGuardListener.
 *
 * Applies IdpRegistrationRule on OpenRegister's own save path, because the
 * Authentication settings screen writes registrations through OpenRegister's
 * object API and not through IdpRegistrationService::save().
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

use OCA\Integriq\Auth\Idp\IdpRegistrationRule;
use OCA\Integriq\Auth\Idp\IdpRegistrationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuse an idpRegistration that breaks a save rule, with 400.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
class IdpRegistrationGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IdpRegistrationRule $rule           The rules a registration's own fields must meet.
	 * @param SchemaMapper        $schemaMapper   Resolves the saved object's schema slug.
	 * @param RegisterMapper      $registerMapper Resolves the saved object's register slug.
	 * @param IL10N               $l10n           Translates the refusal.
	 * @param LoggerInterface     $logger         Diagnostics.
	 */
	public function __construct(
		private readonly IdpRegistrationRule $rule,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
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
		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, registration: $event->getObject());
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->check(event: $event, registration: $event->getNewObject());
		}

	}//end handle()

	/**
	 * Stop the save of an idpRegistration the rule refuses, with 400.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event        The event.
	 * @param ObjectEntity                            $registration The object being saved.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $registration): void {
		if ($this->isRegistration(object: $registration) === false) {
			return;
		}

		$refusal = $this->rule->refusal(data: (array)$registration->getObject());
		if ($refusal === null) {
			return;
		}

		$event->setErrors(
			[
				'code'    => $refusal['code'],
				'message' => $this->l10n->t(
					'%s is reached through an OIDC bridge. Save this registration with the protocol OIDC Relying Party.',
					[ucfirst(string: $refusal['provider'])]
				),
				'status'  => 400,
			]
		);
		$event->stopPropagation();

	}//end check()

	/**
	 * Whether the object is an idpRegistration in the integriq register.
	 *
	 * @param ObjectEntity $object The object being saved.
	 *
	 * @return bool
	 */
	private function isRegistration(ObjectEntity $object): bool {
		try {
			$schema = $this->schemaMapper->find($object->getSchema())->getSlug();
			$register = $this->registerMapper->find($object->getRegister())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] idp registration check: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $schema === IdpRegistrationService::SCHEMA && $register === IdpRegistrationService::REGISTER;

	}//end isRegistration()
}//end class
