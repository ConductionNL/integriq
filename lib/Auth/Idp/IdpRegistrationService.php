<?php

/**
 * Integriq IdpRegistrationService.
 *
 * Reads and saves the idpRegistration objects (one per organisation and
 * provider), and answers whether one is ready to serve logins: the one
 * answer the settings screen shows and the adapter registry acts on.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpRegistrationConflictException;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;

/**
 * The idpRegistration objects and their readiness (design D2, D6).
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */
class IdpRegistrationService {

	/**
	 * The register the objects live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'idpRegistration';

	/**
	 * Constructor.
	 *
	 * @param ObjectService            $objectService OpenRegister object read/write.
	 * @param IdpSecretSeam            $secretSeam    Reads a credential reference, failing closed.
	 * @param IdpBrokerConfigInterface $brokerConfig  The broker flag.
	 * @param IAppManager              $appManager    Answers whether Keepiq is enabled.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IdpSecretSeam $secretSeam,
		private readonly IdpBrokerConfigInterface $brokerConfig,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * Every registration of one organisation and provider (there should be at most one).
	 *
	 * The read is system-level: the login path runs without an admin session,
	 * and the schema is admin-only for every user.
	 *
	 * @param string $organisation The organisation.
	 * @param string $provider     The provider.
	 *
	 * @return list<IdpRegistration>
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function findAll(string $organisation, string $provider): array {
		if (trim($organisation) === '' || trim($provider) === '') {
			return [];
		}

		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA,
					'organisation' => $organisation,
					'provider' => $provider,
				],
				'limit' => 10,
			],
			_rbac: false,
			_multitenancy: false
		);

		$registrations = [];
		foreach ($objects as $object) {
			$registration = self::fromObject(object: $object);
			// The filter is re-checked: an engine that ignores one must not hand
			// another organisation's registration to this login.
			if ($registration !== null && $registration->organisation() === $organisation && $registration->provider() === $provider) {
				$registrations[] = $registration;
			}
		}

		return $registrations;

	}//end findAll()

	/**
	 * The registration of one organisation and provider, or null.
	 *
	 * @param string $organisation The organisation.
	 * @param string $provider     The provider.
	 *
	 * @return IdpRegistration|null
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function find(string $organisation, string $provider): ?IdpRegistration {
		return ($this->findAll(organisation: $organisation, provider: $provider)[0] ?? null);

	}//end find()

	/**
	 * Save a registration, refusing a second one for the same organisation and provider.
	 *
	 * @param array<string, mixed> $data The object data.
	 * @param string|null          $uuid The registration being edited, or null for a new one.
	 *
	 * @return IdpRegistration The saved registration.
	 *
	 * @throws IdpRegistrationConflictException When another registration exists for the organisation and provider.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function save(array $data, ?string $uuid = null): IdpRegistration {
		$candidate = new IdpRegistration(id: (string)$uuid, data: $data);
		foreach ($this->findAll(organisation: $candidate->organisation(), provider: $candidate->provider()) as $existing) {
			if ($existing->id !== (string)$uuid) {
				throw new IdpRegistrationConflictException(
					existing: $existing->id,
					message: sprintf(
						'Organisation %s already has a %s registration: %s. Edit that one instead.',
						$candidate->organisation(),
						$candidate->provider(),
						$existing->name()
					)
				);
			}
		}

		$saved = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid
		);

		return new IdpRegistration(id: (string)$saved->getUuid(), data: (array)$saved->getObject());

	}//end save()

	/**
	 * Whether a registration can serve logins, and why not (design D6).
	 *
	 * Checked in order: disabled, incomplete, Keepiq missing, a required
	 * secret missing, the broker flag off. Reasons name properties and
	 * credential roles, never values.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return IdpReadiness
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
	 */
	public function readiness(IdpRegistration $registration): IdpReadiness {
		if ($registration->isEnabled() === false) {
			return new IdpReadiness(status: IdpReadiness::DISABLED, reasons: ['The registration is not enabled.']);
		}

		$missing = $registration->missingProperties();
		if ($missing !== []) {
			return new IdpReadiness(
				status: IdpReadiness::INCOMPLETE,
				reasons: array_map(static fn (string $key): string => 'Missing: ' . $key . '.', $missing)
			);
		}

		if ($this->appManager->isEnabledForAnyone(IdpSecretSeam::KEEPIQ_APP_ID) === false) {
			return new IdpReadiness(status: IdpReadiness::KEEPIQ_MISSING, reasons: ['Keepiq is not installed or not enabled.']);
		}

		$unresolved = [];
		foreach ($registration->requiredCredentialRoles() as $role) {
			try {
				$this->secretSeam->read(
					registration: $registration->id,
					organisation: $registration->organisation(),
					role: $role,
					reference: $registration->credential(role: $role)
				);
			} catch (IdpSecretRefusedException $refusal) {
				if ($refusal->reason === IdpSecretSeam::REASON_KEEPIQ_MISSING) {
					return new IdpReadiness(status: IdpReadiness::KEEPIQ_MISSING, reasons: ['Keepiq is not installed or not enabled.']);
				}

				$unresolved[] = 'The ' . $role . ' credential cannot be read.';
			}
		}

		if ($unresolved !== []) {
			return new IdpReadiness(status: IdpReadiness::SECRET_MISSING, reasons: $unresolved);
		}

		if ($this->brokerConfig->isEnabled() === false) {
			return new IdpReadiness(status: IdpReadiness::FLAG_OFF, reasons: ['The identity provider broker is switched off.']);
		}

		return new IdpReadiness(status: IdpReadiness::READY);

	}//end readiness()

	/**
	 * A registration from an OpenRegister object, or null for anything else.
	 *
	 * @param mixed $object The object.
	 *
	 * @return IdpRegistration|null
	 */
	private static function fromObject(mixed $object): ?IdpRegistration {
		if ($object instanceof ObjectEntity === false) {
			return null;
		}

		return new IdpRegistration(id: (string)$object->getUuid(), data: (array)$object->getObject());

	}//end fromObject()
}//end class
