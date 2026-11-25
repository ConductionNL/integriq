<?php

/**
 * Integriq OpenApiPreviewPage.
 *
 * Which slice of a large document's operations a preview lists.
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
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenApi;

/**
 * One page of the operations a preview lists.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
final class OpenApiPreviewPage {

	/**
	 * Constructor.
	 *
	 * @param int         $offset The first operation listed.
	 * @param int|null    $limit How many operations are listed; all when null.
	 */
	public function __construct(
		public readonly int $offset = 0,
		public readonly int|null $limit = null,
	) {
	}//end __construct()
}//end class
