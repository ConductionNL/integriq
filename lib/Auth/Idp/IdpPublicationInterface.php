<?php

/**
 * Contract of the IdpPublication.
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

use OCA\Integriq\Exception\IdpMetadataUnavailableException;

/**
 * The published metadata of the registrations, as the public metadata endpoints serve it.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 */
interface IdpPublicationInterface {

	/**
	 * The document is SAML metadata XML (a string body).
	 */
	public const DOCUMENT_SAML = 'saml';

	/**
	 * The document is the OIDC client document (an array body).
	 */
	public const DOCUMENT_OIDC = 'oidc';

	/**
	 * The metadata document of a published registration.
	 *
	 * @param string $organisation The organisation slug.
	 * @param string $provider     The provider slug.
	 *
	 * @return array{type: string, body: string|array<string, mixed>}|null The document, or null when nothing is published there.
	 *
	 * @throws IdpMetadataUnavailableException When a certificate cannot be read.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function document(string $organisation, string $provider): ?array;

	/**
	 * The JWKS of a published OIDC registration that authenticates with a key.
	 *
	 * @param string $organisation The organisation slug.
	 * @param string $provider     The provider slug.
	 *
	 * @return array<string, mixed>|null The key set, or null when nothing is published there.
	 *
	 * @throws IdpMetadataUnavailableException When a certificate cannot be read.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function jwks(string $organisation, string $provider): ?array;
}//end interface
