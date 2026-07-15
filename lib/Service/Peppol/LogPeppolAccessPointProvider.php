<?php

/**
 * OpenConnector Log Peppol Access Point Provider.
 *
 * Sandbox/mock binding for {@see PeppolAccessPointProviderInterface}: performs
 * no real network call, answers lookups from `configuration.mockParticipants`,
 * and returns a synthetic `MOCK-PEPPOL-<n>` transmission id from
 * `submitDocument`. It MUST NOT read any secret. It is the default for dev/CI
 * and mirrors the `source-management` mock-mode convention.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Peppol
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
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#scenario-the-log-provider-transmits-without-a-network-call-or-secret
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Peppol;

/**
 * Sandbox Peppol Access Point provider: canned lookups, synthetic transmission ids.
 *
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#scenario-the-log-provider-transmits-without-a-network-call-or-secret
 */
class LogPeppolAccessPointProvider implements PeppolAccessPointProviderInterface
{

    /**
     * Document types returned for a recognised mock participant.
     *
     * @var string[]
     */
    private const MOCK_SUPPORTED_DOC_TYPES = ['ubl-invoice-2.1', 'ubl-order-3.0'];

    /**
     * Per-process counter for synthetic transmission ids (`MOCK-PEPPOL-<n>`).
     *
     * A per-process, in-memory counter is sufficient for a sandbox binding:
     * ids only need to be locally unique for the duration of one request/job
     * run so submissions within it are visibly distinct; global uniqueness
     * across processes is not required for a mock id.
     *
     * @var integer
     */
    private static int $counter = 0;

    /**
     * {@inheritDoc}
     *
     * @param array  $sourceConfiguration The Peppol source's `configuration` object (`mockParticipants`).
     * @param string $peppolId            The participant identifier, `scheme:identifier`.
     *
     * @return array{exists: bool, supportedDocTypes: string[]} The lookup result.
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-peppol-participant--smp-lookup-endpoint-req-001
     */
    public function lookupParticipant(array $sourceConfiguration, string $peppolId): array
    {
        $mockParticipants = ($sourceConfiguration['mockParticipants'] ?? []);
        if (is_array($mockParticipants) === false) {
            $mockParticipants = [];
        }

        if (in_array($peppolId, $mockParticipants, true) === true) {
            return [
                'exists'            => true,
                'supportedDocTypes' => self::MOCK_SUPPORTED_DOC_TYPES,
            ];
        }

        return [
            'exists'            => false,
            'supportedDocTypes' => [],
        ];

    }//end lookupParticipant()

    /**
     * {@inheritDoc}
     *
     * @param array  $sourceConfiguration The Peppol source's `configuration` object (unused — the log provider needs no secret).
     * @param string $recipientPeppolId   The recipient participant identifier, `scheme:identifier`.
     * @param string $documentType        The UBL document type slug.
     * @param string $payload             The UBL payload (or a reference to it).
     *
     * @return string The synthetic `MOCK-PEPPOL-<n>` transmission id.
     *
     * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#scenario-the-log-provider-transmits-without-a-network-call-or-secret
     */
    public function submitDocument(
        array $sourceConfiguration,
        string $recipientPeppolId,
        string $documentType,
        string $payload
    ): string {
        self::$counter++;
        return 'MOCK-PEPPOL-'.self::$counter;

    }//end submitDocument()
}//end class
