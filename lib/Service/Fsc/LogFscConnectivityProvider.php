<?php

/**
 * OpenConnector Log FSC Connectivity Provider.
 *
 * Sandbox/mock binding for {@see FscConnectivityProviderInterface}:
 * performs no real network call for either resolution or dispatch. Instead
 * of querying a live FSC Directory (none exists in this environment —
 * stated explicitly, see design.md "FSC concept mapping"), it resolves
 * against a source-configured `directory.knownServices` stand-in list, so
 * the found/unknown-organisation/unknown-service contract is fully
 * demonstrable and testable without a live federation. It MUST NOT read
 * any secret. It is the default for dev/CI and mirrors the
 * LogIwmoIjwProvider / LogKlantinteractiesProvider sandbox convention.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Fsc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Fsc;

use OCA\OpenConnector\Exception\FscDirectoryException;

/**
 * Sandbox FSC provider: no network call, resolves against a static
 * `knownServices` stand-in, synthetic call refs.
 *
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class LogFscConnectivityProvider implements FscConnectivityProviderInterface
{

    /**
     * Per-process counter for synthetic references (`FSC-MOCK-<n>`).
     *
     * A per-process, in-memory counter is sufficient for a sandbox binding —
     * refs only need to be locally unique for the duration of one
     * request/job run (mirrors LogIwmoIjwProvider::$counter).
     *
     * @var integer
     */
    private static int $counter = 0;

    /**
     * {@inheritDoc}
     *
     * @return string The stable `log` provider identifier.
     *
     * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function getProviderId(): string
    {
        return 'log';

    }//end getProviderId()

    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed> A schema describing the `knownServices` stand-in directory.
     *
     * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function getConfigSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'knownServices' => [
                    'type'        => 'object',
                    'description' => 'Sandbox-only stand-in for a live FSC Directory: '
                        .'{organisation: {service: {endpoint?, grantRequired?}}}. Only used by the log provider.',
                ],
            ],
        ];

    }//end getConfigSchema()

    /**
     * {@inheritDoc}
     *
     * Resolves against `directoryConfig['knownServices']` — a
     * `{organisation: {service: {endpoint?, grantRequired?}}}` stand-in for
     * a live directory. No network call is made.
     *
     * @param array  $directoryConfig The FSC source's `configuration.directory` object.
     * @param string $organisation    The target organisation identifier.
     * @param string $service         The target service identifier.
     *
     * @return array{organisation: string, service: string, endpoint: string, grantRequired: bool, authContext: array<string, mixed>}
     *
     * @throws FscDirectoryException When the organisation or service is not in `knownServices`.
     *
     * @spec openspec/specs/fsc-connectivity/spec.md#scenario-an-unknown-organisation-is-rejected-before-any-call-is-attempted
     */
    public function resolveService(array $directoryConfig, string $organisation, string $service): array
    {
        $knownServices = ($directoryConfig['knownServices'] ?? []);

        if (isset($knownServices[$organisation]) === false || is_array($knownServices[$organisation]) === false) {
            throw new FscDirectoryException(
                message: 'Unknown organisation "'.$organisation.'" — not present in the configured '
                    .'directory.knownServices (sandbox log provider).'
            );
        }

        $services = $knownServices[$organisation];
        if (isset($services[$service]) === false) {
            throw new FscDirectoryException(
                message: 'Unknown service "'.$service.'" for organisation "'.$organisation.'" — not present '
                    .'in the configured directory.knownServices (sandbox log provider).'
            );
        }

        $entry = [];
        if (is_array($services[$service]) === true) {
            $entry = $services[$service];
        }

        return [
            'organisation'  => $organisation,
            'service'       => $service,
            'endpoint'      => (string) ($entry['endpoint'] ?? ('log://'.$organisation.'/'.$service)),
            'grantRequired' => (bool) ($entry['grantRequired'] ?? false),
            'authContext'   => [],
        ];

    }//end resolveService()

    /**
     * {@inheritDoc}
     *
     * @param array  $directoryConfig Unused — the log provider needs no configuration.
     * @param array  $resolution      The resolution returned by {@see resolveService()}.
     * @param string $method          Unused.
     * @param array  $payload         Echoed back verbatim as the synthetic response body.
     *
     * @return array{ref: string, statusCode: int, body: mixed} The synthetic `FSC-MOCK-<n>` outcome.
     *
     * @spec openspec/specs/fsc-connectivity/spec.md#scenario-the-log-provider-performs-no-network-call
     */
    public function call(array $directoryConfig, array $resolution, string $method, array $payload): array
    {
        self::$counter++;

        return [
            'ref'        => 'FSC-MOCK-'.self::$counter,
            'statusCode' => 200,
            'body'       => $payload,
        ];

    }//end call()
}//end class
