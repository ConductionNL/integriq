<?php

/**
 * Stub for OCA\OpenRegister\Service\Integration\IntegrationProvider.
 *
 * Only the metadata methods CatalogRegistryService actually calls
 * (getId/getLabel/getIcon/getGroup/isEnabled) need to exist for unit tests;
 * the sub-resource CRUD methods are declared too so a test double built
 * against this interface satisfies PHP's type system.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Minimal stub mirroring OCA\OpenRegister\Service\Integration\IntegrationProvider.
 */
interface IntegrationProvider {
	public function getId(): string;

	public function getLabel(): string;

	public function getIcon(): string;

	public function getGroup(): ?string;

	public function getRequiredApp(): ?string;

	public function getStorageStrategy(): string;

	public function getIntegriqSource(): ?string;

	public function isEnabled(): bool;

	public function requiresPermission(): ?string;

	public function authRequirements(): array;

	public function list(string $register, string $schema, string $objectId, array $filters = []): array;

	public function get(string $register, string $schema, string $objectId, string $entityId): array;

	public function create(string $register, string $schema, string $objectId, array $payload): array;

	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array;

	public function delete(string $register, string $schema, string $objectId, string $entityId): void;

	public function health(): array;
}
