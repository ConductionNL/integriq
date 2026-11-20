<?php

/**
 * Serves the published metadata of the registrations.
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

/**
 * Resolves a published registration and builds the document the public endpoint serves.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 */
final class IdpPublication implements IdpPublicationInterface {

	/**
	 * Constructor.
	 *
	 * @param IdpMetadataService $metadata Finds the published registration and builds its documents.
	 */
	public function __construct(
		private readonly IdpMetadataService $metadata,
	) {

	}//end __construct()

	/**
	 * The metadata document of a published registration.
	 *
	 * @param string $organisation The organisation slug.
	 * @param string $provider     The provider slug.
	 *
	 * @return array{type: string, body: string|array<string, mixed>}|null The document, or null when nothing is published there.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function document(string $organisation, string $provider): ?array {
		$registration = $this->metadata->published(organisation: $organisation, provider: $provider);
		if ($registration === null) {
			return null;
		}

		if ($registration->kind() === IdpRegistration::KIND_SAML) {
			return ['type' => self::DOCUMENT_SAML, 'body' => $this->metadata->samlMetadata(registration: $registration)];
		}

		if ($registration->kind() === IdpRegistration::KIND_OIDC) {
			return ['type' => self::DOCUMENT_OIDC, 'body' => $this->metadata->oidcClient(registration: $registration)];
		}

		return null;

	}//end document()

	/**
	 * The JWKS of a published OIDC registration that authenticates with a key.
	 *
	 * @param string $organisation The organisation slug.
	 * @param string $provider     The provider slug.
	 *
	 * @return array<string, mixed>|null The key set, or null when nothing is published there.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function jwks(string $organisation, string $provider): ?array {
		$registration = $this->metadata->published(organisation: $organisation, provider: $provider);
		if ($registration === null
			|| $registration->kind() !== IdpRegistration::KIND_OIDC
			|| $registration->text(key: 'clientAuth') === 'client_secret_basic'
		) {
			return null;
		}

		return $this->metadata->jwks(registration: $registration);

	}//end jwks()
}//end class
