<?php

/**
 * Contract of the ServiceFetchCatalogue.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\ServiceFetch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ServiceFetch;

/**
 * Public operations of {@see ServiceFetchCatalogue} that other components call.
 */
interface ServiceFetchCatalogueInterface {
	/**
	 * The enabled fetches an app may call, without source, path, query or mappings.
	 *
	 * @param string $app The app id.
	 *
	 * @return list<array{slug: string, title: string, description: string, inputs: array, outputs: array}>
	 *
	 * @spec openspec/changes/archive/2026-11-24-forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-a-form-designer-can-list-the-fetches-an-app-may-call-req-sf-003
	 */
	public function listFor(string $app): array;
}
