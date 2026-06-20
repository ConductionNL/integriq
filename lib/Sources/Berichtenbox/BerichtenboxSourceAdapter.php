<?php

/**
 * OpenConnector Logius Berichtenbox Source Adapter (dormant).
 *
 * Source-pattern facade over the lower-level abstract
 * {@see \OCA\OpenConnector\Adapters\Berichtenbox\BerichtenboxClient}
 * family. Ships dormant: every call routes through the mock
 * subclass and is logged at DEBUG so downstream consumers
 * (pipelinq burgerportaal-mijnoverheid-bridge, procest
 * berichtenbox-integration spec) can develop and test against a
 * stable surface without contacting Logius.
 *
 * Lives under `lib/Sources/Berichtenbox/` so it can be discovered
 * by the openconnector Source registry (Source row
 * `logius-berichtenbox`, category=`overheid-messaging`). The
 * active HTTPS implementation lives next to
 * {@see \OCA\OpenConnector\Adapters\Berichtenbox\BerichtenboxClient}
 * and is loaded once `logius.berichtenbox.feature_flag` is set to
 * `1` AND the per-tenant PKIoverheid Services-server cert + BBK 1.7
 * client-credentials token issuer have been provisioned.
 *
 * Per AVG / WBP article 9 the BSN values flowing through this
 * facade are NEVER passed to the structured logger — the
 * dispatch/check methods accept them as opaque arguments and the
 * mock implementation ignores them.
 *
 * @category Source
 * @package  OCA\OpenConnector\Sources\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.openconnector.nl
 * @link https://www.logius.nl/diensten/berichtenbox
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Sources\Berichtenbox;

use OCA\OpenConnector\Adapters\Berichtenbox\BerichtenboxClient;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Dormant source adapter for the Logius Berichtenbox (BBK 1.7).
 *
 * Registered as openconnector Source row id `logius-berichtenbox`,
 * category `overheid-messaging`. Until
 * `logius.berichtenbox.feature_flag` is flipped to `1`, every
 * method routes to the canned mock response below and logs a
 * single debug entry so operators can verify the wiring without
 * contacting Logius.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
final class BerichtenboxSourceAdapter
{
    /**
     * App id used for IAppConfig look-ups.
     */
    public const APP_ID = 'openconnector';

    /**
     * App-config key for the dormant-flag toggle.
     */
    public const FLAG_KEY = 'logius.berichtenbox.feature_flag';

    /**
     * Canonical Source row id this adapter is registered under.
     */
    public const SOURCE_ID = 'logius-berichtenbox';

    /**
     * Source category — `overheid-messaging` per the
     * connector-categories taxonomy.
     */
    public const SOURCE_CATEGORY = 'overheid-messaging';

    /**
     * Constructor.
     *
     * @param IAppConfig         $config             App-config service
     *                                               (feature-flag
     *                                               check).
     * @param LoggerInterface    $logger             Structured logger.
     * @param BerichtenboxClient $berichtenboxClient Resolved client
     *                                               (mock or http).
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly LoggerInterface $logger,
        private readonly BerichtenboxClient $berichtenboxClient
    ) {
    }//end __construct()

    /**
     * Whether the live Logius Berichtenbox transport is enabled by
     * the operator.
     *
     * @return bool True when `logius.berichtenbox.feature_flag` is
     *              `1` / `true`.
     */
    public function isActive(): bool
    {
        $raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
        return ($raw === '1' || strtolower($raw) === 'true');
    }//end isActive()

    /**
     * Dispatch a BBK 1.7 message envelope.
     *
     * @param array<string,mixed> $message BBK 1.7-shaped envelope.
     * @param string              $pkiCert PEM-encoded PKIoverheid
     *                                     Services-server cert.
     * @param string              $pkiKey  PEM-encoded private key.
     *
     * @return array<string,mixed> Logius response envelope.
     */
    public function dispatch(array $message, string $pkiCert, string $pkiKey): array
    {
        // Compute a non-PII-bearing summary of the message for the
        // debug log — never log the recipientBsn, body, or
        // attachment bytes.
        $summary = [
            'conversationId'  => (string) ($message['conversationId'] ?? ''),
            'priority'        => (string) ($message['priority'] ?? ''),
            'attachmentCount' => is_array($message['attachments'] ?? null) === true ? count($message['attachments']) : 0,
        ];

        $this->logger->debug(
            'logius-berichtenbox.dispatch',
            [
                'source'   => self::SOURCE_ID,
                'category' => self::SOURCE_CATEGORY,
                'summary'  => $summary,
                'active'   => $this->isActive(),
                'flavour'  => $this->berichtenboxClient->flavour(),
            ]
        );

        return $this->berichtenboxClient->dispatch($message, $pkiCert, $pkiKey);
    }//end dispatch()

    /**
     * Verify an inbound delivery-receipt webhook.
     *
     * @param string               $rawBody Raw inbound body bytes.
     * @param array<string,string> $headers Inbound headers.
     *
     * @return array<string,mixed> Verified envelope.
     */
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        // Body is never logged — may contain delivery PII; only the
        // length + signature-presence boolean go through.
        $this->logger->debug(
            'logius-berichtenbox.verifyWebhook',
            [
                'source'           => self::SOURCE_ID,
                'category'         => self::SOURCE_CATEGORY,
                'bodyLength'       => strlen($rawBody),
                'signaturePresent' => isset($headers['X-Logius-Signature']) || isset($headers['x-logius-signature']),
                'active'           => $this->isActive(),
                'flavour'          => $this->berichtenboxClient->flavour(),
            ]
        );

        return $this->berichtenboxClient->verifyWebhook($rawBody, $headers);
    }//end verifyWebhook()

    /**
     * Check whether a BSN has an active Berichtenbox mailbox.
     *
     * The BSN itself is NEVER passed to the logger — only a
     * `bsn_length_check` boolean goes through.
     *
     * @param string $bsn 9-digit Burgerservicenummer.
     *
     * @return array<string,mixed> Mailbox-status envelope.
     */
    public function checkMailbox(string $bsn): array
    {
        $this->logger->debug(
            'logius-berichtenbox.checkMailbox',
            [
                'source'           => self::SOURCE_ID,
                'category'         => self::SOURCE_CATEGORY,
                'bsn_length_check' => (strlen($bsn) === 9),
                'active'           => $this->isActive(),
                'flavour'          => $this->berichtenboxClient->flavour(),
            ]
        );

        return $this->berichtenboxClient->checkMailbox($bsn);
    }//end checkMailbox()
}//end class
