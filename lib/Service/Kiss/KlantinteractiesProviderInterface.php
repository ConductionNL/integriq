<?php

/**
 * Integriq KISS Klantinteracties Provider Interface.
 *
 * Narrow domain seam through which every KISS (Klantinteractie
 * Servicesysteem) klantcontact list/fetch, create, and onderwerpobject-link
 * call occurs. A new KISS deployment or a compatible alternative (any VNG
 * Klantinteracties API v0.8/0.9-shaped backend — the OpenKlant 2.x reference
 * implementation, or KISS itself) is added by implementing this interface,
 * never by editing KissSyncService or KissController — mirrors
 * PeppolAccessPointProviderInterface / Psd2AggregatorProviderInterface /
 * SmsProviderInterface (see design.md "Provider seam vs category
 * IntegrationProvider").
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\Integriq\Exception\KissProviderException;

/**
 * A KISS/Klantinteracties binding: klantcontact list/fetch, create, and
 * onderwerpobject linking (the mechanism that ties a klantcontact to a
 * case/zaak identifier).
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */
interface KlantinteractiesProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `rest`).
	 *
	 * Selected at runtime via the KISS source's `configuration.provider`
	 * field — see {@see \OCA\Integriq\Service\KissSyncService::resolveProvider()}.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function getProviderId(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function getConfigSchema(): array;

	/**
	 * List klantcontacten changed since a cursor, with betrokkenen and
	 * onderwerpobjecten expanded inline (VNG `expand=` convention).
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 * @param string|null $since ISO 8601 timestamp: only klantcontacten with
	 *                           `registratiedatum` strictly after this value are
	 *                           returned. Null pulls the provider's default window
	 *                           (the first sync / full backfill).
	 * @param integer $pageSize Maximum number of klantcontacten to return in one call.
	 *
	 * Returns the page of klantcontacten (each carrying `betrokkenen` and
	 * `onderwerpobjecten`) plus the `registratiedatum` of the most recent item
	 * in the page, or null for that cursor when the page is empty.
	 *
	 * @return array{items: array<int, array<string, mixed>>, nextCursor: string|null}
	 *
	 * @throws KissProviderException When the KISS instance is unreachable, errors, or is misconfigured.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function listCustomerContacts(array $sourceConfiguration, ?string $since, int $pageSize): array;

	/**
	 * Create one klantcontact in KISS.
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 * @param array $payload The klantcontact fields (`onderwerp`, `kanaal`, `tekst`,
	 *                       `plaatsgevondenOp`, `indicatieContactGelukt`, `taal`, ...) plus an
	 *                       optional `betrokkene` object (`rol`, `partijIdentificator`, ...).
	 *
	 * @return string The KISS-assigned klantcontact id (uuid).
	 *
	 * @throws KissProviderException When KISS is unreachable, rejects the request, or is misconfigured.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function createCustomerContact(array $sourceConfiguration, array $payload): string;

	/**
	 * Link a klantcontact to a case/zaak by creating an onderwerpobject.
	 *
	 * @param array $sourceConfiguration The KISS source's `configuration` object.
	 * @param string $customerContactId The KISS klantcontact id to attach the link to.
	 * @param string $caseReference The case identifier (bare UUID or zaak identificatie).
	 * @param string $caseObjectType The onderwerpobjectidentificator `codeObjecttype` (default `zaak`).
	 *
	 * @return string The KISS-assigned onderwerpobject id (uuid).
	 *
	 * @throws KissProviderException When KISS is unreachable, rejects the request, or is misconfigured.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function linkOnderwerpobject(
		array $sourceConfiguration,
		string $customerContactId,
		string $caseReference,
		string $caseObjectType,
	): string;
}//end interface
