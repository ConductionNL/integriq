<?php

/**
 * Lists the registrations with their readiness, and tests one.
 *
 * @category Service
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCP\App\IAppManager;

/**
 * The registrations as the Beheer authenticatie screen lists them, and one registration's checks.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
final class IdpRegistrationAdmin implements IdpRegistrationAdminInterface {

	/**
	 * Constructor.
	 *
	 * @param IdpRegistrationService   $registrations The registrations and their readiness.
	 * @param IdpRegistrationTester    $tester        Runs a registration's checks.
	 * @param IdpMetadataService       $metadata      Builds each registration's metadata address.
	 * @param IdpBrokerConfigInterface $brokerConfig  The broker flag.
	 * @param IAppManager              $appManager    Answers whether Keepiq is enabled.
	 */
	public function __construct(
		private readonly IdpRegistrationService $registrations,
		private readonly IdpRegistrationTester $tester,
		private readonly IdpMetadataService $metadata,
		private readonly IdpBrokerConfigInterface $brokerConfig,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * Every registration with its status, the reasons for it and its metadata address,
	 * and whether the broker flag is on and Keepiq is there. Never a credential.
	 *
	 * @return array{brokerEnabled: bool, keepiqAvailable: bool, registrations: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
	 */
	public function overview(): array {
		$rows = array_map(fn (IdpRegistration $registration): array => $this->row(registration: $registration), $this->registrations->all());

		return [
			'brokerEnabled' => $this->brokerConfig->isEnabled(),
			'keepiqAvailable' => $this->appManager->isEnabledForAnyone(IdpSecretSeam::KEEPIQ_APP_ID),
			'registrations' => $rows,
		];

	}//end overview()

	/**
	 * The checks of one registration: the provider document and each required credential.
	 *
	 * @param string $id The registration's uuid.
	 *
	 * @return array<int|string, mixed>|null The checks, or null for an unknown registration.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
	 */
	public function checks(string $id): ?array {
		foreach ($this->registrations->all() as $registration) {
			if ($registration->id === $id) {
				return $this->tester->run(registration: $registration);
			}
		}

		return null;

	}//end checks()

	/**
	 * One registration as the screen lists it: configuration and readiness, never a credential.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return array<string, mixed>
	 */
	private function row(IdpRegistration $registration): array {
		$readiness = $this->registrations->readiness(registration: $registration)->toArray();

		return [
			'id' => $registration->id,
			'label' => $registration->text(key: 'label'),
			'organisation' => $registration->organisation(),
			'provider' => $registration->provider(),
			'kind' => $registration->kind(),
			'enabled' => $registration->isEnabled(),
			'status' => $readiness['status'],
			'reasons' => $readiness['reasons'],
			'metadataUrl' => $this->metadata->metadataUrl(registration: $registration),
		];

	}//end row()
}//end class
