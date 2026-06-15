<?php

/**
 * Stub for OCA\OpenRegister\Service\OrganisationService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for OCA\OpenRegister\Service\OrganisationService.
 */
class OrganisationService
{
    public function getOrganisation(string $userId): ?array
    {
        return null;
    }

    public function setOrganisation(string $userId, array $data): bool
    {
        return true;
    }
}
