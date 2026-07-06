<?php
/**
 * Test stub for OCA\OpenRegister\Service\Credential\CredentialBrokerService.
 *
 * Signature verified against openregister origin/development
 * (lib/Service/Credential/CredentialBrokerService.php): request() takes
 * credentialId, appId, method, path, headers, body — and deliberately does
 * NOT take an acting-user parameter, mirroring the currently shipped broker.
 * BrokeredCallService's reflection-based acting-user feature detection is
 * exercised against this exact shape (the acting-user parameter ships with
 * OR change `credential-doriath-leaf`).
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Stub of the constrained, host-locked, secret-injecting outbound broker.
 */
class CredentialBrokerService
{

    /**
     * Register slug holding `credential` metadata objects.
     *
     * @var string
     */
    public const REGISTER = 'credential-broker';

    /**
     * Schema slug for `credential` metadata objects.
     *
     * @var string
     */
    public const SCHEMA = 'brokeredcredential';


    /**
     * Broker a single constrained outbound call on a credential's behalf.
     *
     * @param string                $credentialId The `credential` object UUID.
     * @param string                $appId        The authenticated calling app id.
     * @param string                $method       The HTTP method (e.g. `GET`).
     * @param string                $path         The provider-relative path.
     * @param array<string, string> $headers      Optional extra request headers.
     * @param string|null           $body         Optional raw request body.
     *
     * @return array{status: int, headers: array<string, mixed>, body: string} The upstream response.
     */
    public function request(
        string $credentialId,
        string $appId,
        string $method,
        string $path,
        array $headers=[],
        ?string $body=null
    ): array {
        return [
            'status'  => 200,
            'headers' => [],
            'body'    => '',
        ];

    }//end request()


}//end class
