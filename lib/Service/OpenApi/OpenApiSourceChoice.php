<?php

/**
 * Integriq OpenApiSourceChoice.
 *
 * How the source of an import is made: which of the document's servers it calls, and its name.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenApi;

/**
 * The administrator's choices for an imported source.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
final class OpenApiSourceChoice {

	/**
	 * Constructor.
	 *
	 * @param int|null    $serverIndex The server the source calls; the first when null.
	 * @param string|null $name The source name; derived from the document when empty.
	 */
	public function __construct(
		public readonly int|null $serverIndex = null,
		public readonly string|null $name = null,
	) {
	}//end __construct()
}//end class
