<?php

/**
 * Stub for OCA\OpenRegister\Db\RegisterMapper.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for OCA\OpenRegister\Db\RegisterMapper.
 */
class RegisterMapper {
	/**
	 * Mirrors the real signature, which is `string|int $id` — registers are
	 * looked up by slug as well as by id.
	 */
	public function find(string|int $id): ?object {
		return null;
	}

	public function findAll(int $limit = 50, int $offset = 0, array $filters = []): array {
		return [];
	}

	public function findBySlug(string $slug): ?object {
		return null;
	}
}
