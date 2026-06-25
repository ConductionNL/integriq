<?php

/**
 * OpenConnector Logius Berichtenbox client (abstract base).
 *
 * Low-level abstract base for the BBK (Berichtenbox-koppelvlak) 1.7
 * REST API exposed by Logius. Concrete subclasses:
 *
 *   - {@see BerichtenboxClientMock} — deterministic mock; default.
 *     Returns canned envelopes + a synthetic logius-kenmerk so
 *     downstream apps (pipelinq burgerportaal-mijnoverheid-bridge,
 *     procest berichtenbox-integration spec) can develop against
 *     real-shaped payloads without ever contacting Logius.
 *   - {@see BerichtenboxClientHttp} — the live HTTPS client
 *     (delivered in a follow-up change paired with the Logius
 *     BBK 1.7 OAuth 2.0 client credentials + PKIoverheid
 *     Services-server cert). Verifies inbound delivery-receipt
 *     HMAC signatures and signs outbound message envelopes.
 *
 * @category Adapter
 * @package  OCA\OpenConnector\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.openconnector.nl
 * @link https://www.logius.nl/diensten/berichtenbox
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Adapters\Berichtenbox;

/**
 * Abstract Berichtenbox client.
 *
 * Subclasses MUST implement the three shape-preserving methods plus
 * a `flavour()` self-identifier so the structured logger can record
 * which binding actually handled the call.
 */
abstract class BerichtenboxClient
{
    /**
     * Mock or live flavour identifier — used in structured logs so
     * operators can verify which binding handled a call.
     *
     * @return string `mock` or `https`.
     */
    abstract public function flavour(): string;

    /**
     * Dispatch a BBK 1.7 message envelope to Logius.
     *
     * @param array<string,mixed> $message BBK 1.7-shaped envelope.
     * @param string              $pkiCert PEM-encoded
     *                                     PKIoverheid
     *                                     Services-server
     *                                     cert —
     *                                     required by
     *                                     live
     *                                     binding,
     *                                     ignored by
     *                                     mock.
     * @param string              $pkiKey  PEM-encoded private key.
     *
     * @return array<string,mixed> Logius response envelope —
     *                             logiusKenmerk, deliveryStatus,
     *                             receivedAt.
     */
    abstract public function dispatch(array $message, string $pkiCert, string $pkiKey): array;

    /**
     * Verify the HMAC signature on an inbound Logius delivery-receipt
     * webhook body + load the matching delivery record.
     *
     * @param string               $rawBody Raw inbound body bytes.
     * @param array<string,string> $headers Inbound headers (Logius
     *                                      signature in
     *                                      `X-Logius-Signature`).
     *
     * @return array<string,mixed> Verified envelope —
     *                             signatureValid, logiusKenmerk,
     *                             deliveryStatus, deliveredAt.
     */
    abstract public function verifyWebhook(string $rawBody, array $headers): array;

    /**
     * Check whether a BSN has an active Berichtenbox mailbox.
     *
     * @param string $bsn 9-digit Burgerservicenummer.
     *
     * @return array<string,mixed> Mailbox-status envelope —
     *                             active, lastUsedAt.
     */
    abstract public function checkMailbox(string $bsn): array;
}//end class
