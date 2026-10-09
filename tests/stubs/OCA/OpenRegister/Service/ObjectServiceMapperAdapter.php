<?php

/**
 * Test stub: the find(), getSchema() and findAllPaginated() part of
 * OpenRegister's ObjectServiceMapperAdapter.
 *
 * Copied from openregister development lib/Service/ObjectServiceMapperAdapter.php
 * (signatures verbatim) so EndpointService's mapper union type accepts a
 * double of it. The real class delegates to ObjectService::find with the bound
 * register and schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Adapter that exposes a mapper-like API over ObjectService.
 */
class ObjectServiceMapperAdapter {

	/**
	 * Find a single object by its ID or UUID.
	 *
	 * @param int|string $identifier Object ID or UUID.
	 * @param array|null $extend     Relations to expand inline.
	 *
	 * @return ObjectEntity|null
	 */
	public function find(int|string $identifier, ?array $extend = null): ?ObjectEntity {
		return null;
	}

	/**
	 * The schema id this adapter is scoped to, or null for unconstrained.
	 *
	 * @return int|null
	 */
	public function getSchema(): ?int {
		return null;
	}

	/**
	 * Search objects with pagination, scoped to the adapter's register and schema.
	 *
	 * @param array $requestParams The query.
	 *
	 * @return array{results: array, total: int, page: int, pages: int}
	 */
	public function findAllPaginated(array $requestParams = []): array {
		return ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
	}
}
