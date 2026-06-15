<?php

/**
 * Stub for OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider.
 *
 * Only declares the abstract methods that SynchronizationContractProvider
 * overrides. Concrete non-abstract methods are intentionally omitted so the
 * override signatures in the production class drive the interface shape.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Minimal stub for OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider.
 */
abstract class AbstractIntegrationProvider
{
    /**
     * Return the identifier of this integration provider.
     *
     * @return string
     */
    abstract public function getId(): string;
}
