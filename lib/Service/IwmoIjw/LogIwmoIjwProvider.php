<?php

/**
 * OpenConnector Log iWMO/iJW Provider.
 *
 * Sandbox/mock binding for {@see IwmoIjwProviderInterface}: performs no
 * real network call and returns a synthetic `MOCK-IWMO-<n>` reference. It
 * MUST NOT read any secret. It is the default for dev/CI and mirrors the
 * LogKlantinteractiesProvider / LogSmsProvider sandbox convention.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\IwmoIjw
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
 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\IwmoIjw;

/**
 * Sandbox iWMO/iJW provider: no network call, synthetic reference.
 *
 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class LogIwmoIjwProvider implements IwmoIjwProviderInterface
{

    /**
     * Per-process counter for synthetic references (`MOCK-IWMO-<n>`).
     *
     * A per-process, in-memory counter is sufficient for a sandbox binding —
     * refs only need to be locally unique for the duration of one
     * request/job run (mirrors LogKlantinteractiesProvider::$counter).
     *
     * @var integer
     */
    private static int $counter = 0;

    /**
     * {@inheritDoc}
     *
     * @return string The stable `log` provider identifier.
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function getProviderId(): string
    {
        return 'log';

    }//end getProviderId()

    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed> An empty schema — the log provider needs no configuration.
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function getConfigSchema(): array
    {
        return ['type' => 'object', 'properties' => []];

    }//end getConfigSchema()

    /**
     * {@inheritDoc}
     *
     * @param array  $sourceConfiguration Unused — the log provider needs no configuration.
     * @param string $berichttype         Unused.
     * @param string $envelopeXml         Unused.
     *
     * @return string The synthetic `MOCK-IWMO-<n>` reference.
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
     */
    public function send(array $sourceConfiguration, string $berichttype, string $envelopeXml): string
    {
        self::$counter++;
        return 'MOCK-IWMO-'.self::$counter;

    }//end send()
}//end class
