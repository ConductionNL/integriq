<?php

/**
 * OpenConnector Logius Berichtenbox client (mock).
 *
 * Deterministic, no-network implementation of
 * {@see BerichtenboxClient}. Ships dormant — DI returns this class
 * until `logius.berichtenbox.feature_flag` is set to `1`. The
 * canned envelopes match the BBK 1.7 response shape documented in
 * the `hydra/shared-logius-berichtenbox-via-openconnector` design
 * so downstream code can exercise its branches off real-shaped
 * data.
 *
 * @category Adapter
 * @package  OCA\OpenConnector\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.openconnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Adapters\Berichtenbox;

/**
 * Mock Berichtenbox client — dormant default.
 *
 * AVG-safe by construction: BSN values are NEVER passed through;
 * `dispatch()` and `checkMailbox()` accept them but never echo them
 * back nor log them.
 */
final class BerichtenboxClientMock extends BerichtenboxClient
{
    /**
     * @inheritDoc
     */
    public function flavour(): string
    {
        return 'mock';
    }//end flavour()

    /**
     * Dormant dispatch — returns a synthetic Logius envelope.
     *
     * @param array<string,mixed> $message Message (ignored).
     * @param string              $pkiCert PKIoverheid cert (ignored).
     * @param string              $pkiKey  Private key (ignored).
     *
     * @return array<string,mixed>
     */
    public function dispatch(array $message, string $pkiCert, string $pkiKey): array
    {
        unset($message, $pkiCert, $pkiKey);
        return [
            'logiusKenmerk'  => 'bbk-mock-'.bin2hex(random_bytes(8)),
            'deliveryStatus' => 'queued',
            'receivedAt'     => gmdate('c'),
            'flavour'        => 'mock',
        ];
    }//end dispatch()

    /**
     * Dormant verify — returns signatureValid=false so a downstream
     * caller never treats a mock-verified webhook as authentic.
     *
     * @param string              $rawBody Raw body (ignored).
     * @param array<string,string> $headers Headers (ignored).
     *
     * @return array<string,mixed>
     */
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        unset($rawBody, $headers);
        return [
            'signatureValid' => false,
            'logiusKenmerk'  => 'bbk-mock-verify-'.bin2hex(random_bytes(6)),
            'deliveryStatus' => 'mock',
            'deliveredAt'    => gmdate('c'),
            'flavour'        => 'mock',
        ];
    }//end verifyWebhook()

    /**
     * Dormant mailbox-check — returns active=false regardless of BSN.
     *
     * BSN itself is never inspected nor echoed (AVG / WBP art. 9).
     *
     * @param string $bsn BSN (ignored).
     *
     * @return array<string,mixed>
     */
    public function checkMailbox(string $bsn): array
    {
        unset($bsn);
        return [
            'active'      => false,
            'lastUsedAt'  => null,
            'flavour'     => 'mock',
        ];
    }//end checkMailbox()
}//end class
