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
 * A provider that is only reached over OIDC (Yivi, through an OIDC bridge) may
 * not be saved with the SAML kind: no SAML adapter can serve it, so the
 * registration would look configured and never log anyone in.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
class IdpRegistrationGuardListener implements IEventListener {

	/**
	 * The providers that are only reached as an OIDC Relying Party.
	 *
	 * @var list<string>
	 */
	public const OIDC_ONLY_PROVIDERS = ['yivi'];

	/**
	 * The register and schema of a registration, as IdpRegistrationService names them.
	 *
	 * @var array{register: string, schema: string}
	 */
	private const REGISTRATION = ['register' => 'integriq', 'schema' => 'idpRegistration'];

	/**
	 * The OIDC Relying Party kind, as IdpRegistration::KIND_OIDC names it.
	 *
	 * @var string
	 */
	private const KIND_OIDC = 'oidc-rp';

	/**
	 * The refusal code for an OIDC-only provider saved with another kind.
	 *
	 * @var string
	 */
	public const CODE_NEEDS_OIDC = 'idp_registration_needs_oidc';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper    $schemaMapper   Resolves the saved object's schema slug.
	 * @param RegisterMapper  $registerMapper Resolves the saved object's register slug.
	 * @param IL10N           $l10n           Translates the refusal.
	 * @param LoggerInterface $logger         Diagnostics.
	 */
	public function __construct(
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

		$data = (array)$registration->getObject();
		$provider = trim((string)($data['provider'] ?? ''));
		$kind = trim((string)($data['kind'] ?? ''));
		if (in_array(needle: $provider, haystack: self::OIDC_ONLY_PROVIDERS, strict: true) === false || $kind === self::KIND_OIDC) {
			return;
		}

		$event->setErrors(
			[
				'code'    => self::CODE_NEEDS_OIDC,
				'message' => $this->l10n->t(
					'%s is reached through an OIDC bridge. Save this registration with the protocol OIDC Relying Party.',
					[ucfirst(string: $provider)]
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

		return $schema === self::REGISTRATION['schema'] && $register === self::REGISTRATION['register'];

	}//end isRegistration()
}//end class
