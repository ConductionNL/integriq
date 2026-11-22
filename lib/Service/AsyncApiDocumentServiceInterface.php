<?php

/**
 * Contract of the AsyncApiDocumentService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see AsyncApiDocumentService} that other components call.
 */
interface AsyncApiDocumentServiceInterface {
	/**
	 * The product version a document describes: the highest active version, else the highest version.
	 *
	 * @param array<int,ObjectEntity> $versions The api_product rows sharing one productSlug.
	 *
	 * @return ObjectEntity|null The row, or null when there is none.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
	 */
	public function latestVersion(array $versions): ?ObjectEntity;

	/**
	 * The AsyncAPI 3.0 document of a product version's channels.
	 *
	 * @param ObjectEntity $product The api_product row.
	 * @param string       $baseUrl The absolute URL of the product's routes, e.g. `https://host/index.php/apps/integriq/api/products/woo-publications`.
	 *
	 * @return array<string,mixed> The document.
	 *
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-the-product-publishes-an-asyncapi-document-of-its-channels-req-aapi-004
	 */
	public function build(ObjectEntity $product, string $baseUrl): array;
}
