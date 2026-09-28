<?php

/**
 * Stub for OCA\OpenRegister\Db\RegisterMapper.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
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
	 * looked up by slug as well as by id. The `$_rbac` / `$_multitenancy` flags
	 * mirror the real signature too, so a caller that passes them by name works
	 * against the stub exactly as it does against OpenRegister.
	 */
	public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): ?object {
		return null;
	}

	public function findAll(int $limit = 50, int $offset = 0, array $filters = []): array {
		return [];
	}

	public function findBySlug(string $slug): ?object {
		return null;
	}
}
