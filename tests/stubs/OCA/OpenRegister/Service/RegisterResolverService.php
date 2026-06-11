<?php

/**
 * Stub for OCA\OpenRegister\Service\RegisterResolverService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for OCA\OpenRegister\Service\RegisterResolverService.
 *
 * Mirrors the live signatures so unit tests can mock the service without
 * pulling the full OpenRegister tree. The bodies are intentionally
 * inert — the production class is provided by openregister at runtime.
 */
class RegisterResolverService
{
    public function resolveRegisterId(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): string {
        return $default ?? '';
    }

    public function resolveSchemaId(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): string {
        return $default ?? '';
    }

    public function resolvePropertyId(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): string {
        return $default ?? '';
    }

    public function resolveRegister(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): mixed {
        return null;
    }

    public function resolveSchema(
        string $appId,
        string $configKey,
        ?string $default=null,
        ?string $organisationUuid=null,
    ): mixed {
        return null;
    }

    public function resolveProperty(
        string $appId,
        string $schemaConfigKey,
        string $propertyConfigKey,
        ?string $propertyDefault=null,
        ?string $schemaDefault=null,
        ?string $organisationUuid=null,
    ): array {
        return ['', []];
    }

    public function resolvePair(
        string $appId,
        string $registerKey,
        string $schemaKey,
        ?string $organisationUuid=null,
    ): mixed {
        return null;
    }

    public function enumerateAppConfigs(string $appId): array
    {
        return [];
    }

    public function clearCache(): void
    {
    }
}
