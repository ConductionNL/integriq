<?php

/**
 * Contract of the EgressDestinationService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Residency
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Residency;

use InvalidArgumentException;

/**
 * Public operations of {@see EgressDestinationService} that other components call.
 */
interface EgressDestinationServiceInterface {
	/**
	 * Every destination with its location, the verdict and the counts per zone.
	 *
	 * @return array{destinations: array<int,array<string,mixed>>, verdict: string, counts: array<string,int>, euOnly: bool}
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
	 */
	public function list(): array;

	/**
	 * Switch EU-only mode. Switching it on is refused while any destination is not in the EU.
	 *
	 * @param bool $enabled The wanted state.
	 *
	 * @return array{euOnly: bool, blocking: array<int,array<string,mixed>>} The stored state and,
	 *     when refused, the destinations that block it.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
	 */
	public function setEuOnly(bool $enabled): array;

	/**
	 * Record an administrator's residency on a source.
	 *
	 * @param string $sourceId The source uuid.
	 * @param string $country  The ISO 3166-1 alpha-2 code.
	 * @param string $evidence A URL or a short text.
	 * @param string $userId   The administrator.
	 *
	 * @return array<string,string>|null The stored residency, or null when the source does not exist.
	 *
	 * @throws InvalidArgumentException When the country is not a valid code.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
	 */
	public function setSourceResidency(string $sourceId, string $country, string $evidence, string $userId): ?array;
}
