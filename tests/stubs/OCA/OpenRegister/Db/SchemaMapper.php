<?php

/**
 * Stub for OCA\OpenRegister\Db\SchemaMapper.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for OCA\OpenRegister\Db\SchemaMapper.
 */
class SchemaMapper {
	public function find(int $id): ?object {
		return null;
	}

	public function findAll(int $limit = 50, int $offset = 0, array $filters = []): array {
		return [];
	}

	public function findBySlug(string $slug): ?object {
		return null;
	}
}
