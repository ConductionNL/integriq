<?php

/**
 * OpenConnector iWMO/iJW Provider Interface.
 *
 * Narrow domain seam through which every outbound iWMO/iJW (StUF
 * iStandaarden Wmo 3.0 / Jeugdwet 3.0) berichttype envelope is dispatched.
 * A new GGk/VECOZO-fronted endpoint, or a compatible alternative transport
 * (e.g. one adding client-certificate auth), is added by implementing this
 * interface, never by editing IwmoIjwSyncService or IwmoIjwController —
 * mirrors KlantinteractiesProviderInterface / SmsProviderInterface /
 * PeppolAccessPointProviderInterface (see design.md "Provider seam,
 * credential storage, feature gating").
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
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\IwmoIjw;

use OCA\OpenConnector\Exception\IwmoIjwProviderException;

/**
 * An iWMO/iJW transport binding: dispatch one already-translated berichttype
 * envelope and report the transport-assigned reference.
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
 */
interface IwmoIjwProviderInterface
{
    /**
     * Stable machine identifier for this binding (e.g. `log`, `rest`).
     *
     * Selected at runtime via the iWMO/iJW source's `configuration.provider`
     * field — see {@see \OCA\OpenConnector\Service\IwmoIjwSyncService::resolveProvider()}.
     *
     * @return string The provider identifier.
     *
     * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function getProviderId(): string;

    /**
     * The JSON Schema describing this provider's `configuration` object.
     *
     * @return array<string, mixed> A JSON Schema (object) fragment.
     *
     * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function getConfigSchema(): array;

    /**
     * Dispatch one already-translated berichttype envelope.
     *
     * @param array  $sourceConfiguration The iWMO/iJW source's `configuration` object.
     * @param string $berichttype         The berichtcode being sent (e.g. `Wmo303`, `Jw321`).
     * @param string $envelopeXml         The fully rendered envelope XML — the transport MUST send
     *                                    this verbatim as the request body, never re-serialize it.
     *
     * @return string The transport-assigned reference (or the echoed `referentienummer` when the
     *                transport assigns none of its own).
     *
     * @throws IwmoIjwProviderException When the endpoint is unreachable, errors, or is misconfigured.
     *
     * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
     */
    public function send(array $sourceConfiguration, string $berichttype, string $envelopeXml): string;
}//end interface
