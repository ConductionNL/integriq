<?php

/**
 * Stub for OCA\OpenRegister\Service\Handoff\HandoffService.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub satisfies PHPUnit mock-builder calls for
 * HandoffService so unit tests can run without a full Nextcloud server.
 *
 * Only the methods actually called by openconnector's lib/ are declared here.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * Minimal stub for OCA\OpenRegister\Service\Handoff\HandoffService.
 */
class HandoffService
{
    /**
     * Execute a declared handoff on a source object (or park it, queue mode).
     *
     * @param  string      $register
     * @param  string      $schema
     * @param  string      $id
     * @param  string      $handoffId
     * @param  bool        $deferred
     * @param  string|null $correlationId
     * @return array<string, mixed>
     */
    public function execute(
        string $register,
        string $schema,
        string $id,
        string $handoffId,
        bool $deferred = false,
        ?string $correlationId = null
    ): array {
        return ['status' => 'executed', 'target' => [], 'correlationId' => ''];
    }

    /**
     * Report handoff availability for one object.
     *
     * @param  string $register
     * @param  string $schema
     * @param  string $id
     * @return array<int, array<string, mixed>>
     */
    public function listAvailability(string $register, string $schema, string $id): array
    {
        return [];
    }
}
