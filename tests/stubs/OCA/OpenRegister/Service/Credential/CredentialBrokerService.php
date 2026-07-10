<?php

/**
 * Stub for OCA\OpenRegister\Service\Credential\CredentialBrokerService.
 *
 * Mirrors only the public `request()` signature that openconnector's
 * category adapters call — the real implementation (guards, secret
 * injection, HTTP client) lives in the peer OpenRegister app, not in vendor.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Minimal stub for OCA\OpenRegister\Service\Credential\CredentialBrokerService.
 */
class CredentialBrokerService
{
    /**
     * Broker a single constrained outbound call on a credential's behalf.
     *
     * @param string                $credentialId The `credential` object UUID.
     * @param string                $appId        The authenticated calling app id.
     * @param string                $method       The HTTP method.
     * @param string                $path         The provider-relative path.
     * @param array<string, string> $headers      Optional extra request headers.
     * @param string|null           $body         Optional raw request body.
     * @param string|null           $actingUserId Optional asserted user for sessionless callers.
     *
     * @return array{status: int, headers: array<string, mixed>, body: string}
     */
    public function request(
        string $credentialId,
        string $appId,
        string $method,
        string $path,
        array $headers=[],
        ?string $body=null,
        ?string $actingUserId=null
    ): array {
        return ['status' => 200, 'headers' => [], 'body' => ''];
    }
}
