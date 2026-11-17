<?php

/**
 * Contract of the IdpRegistrationAdmin.
 *
 * @category Contract
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

/**
 * What the Beheer authenticatie screen reads: the registrations with their readiness, and one registration's checks.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
interface IdpRegistrationAdminInterface {

	/**
	 * Every registration with its status, the reasons for it and its metadata address,
	 * and whether the broker flag is on and Keepiq is there. Never a credential.
	 *
	 * @return array{brokerEnabled: bool, keepiqAvailable: bool, registrations: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
	 */
	public function overview(): array;

	/**
	 * The checks of one registration: the provider document and each required credential.
	 *
	 * @param string $id The registration's uuid.
	 *
	 * @return array<int|string, mixed>|null The checks, or null for an unknown registration.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
	 */
	public function checks(string $id): ?array;
}//end interface
