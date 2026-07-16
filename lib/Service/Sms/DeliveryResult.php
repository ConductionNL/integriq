<?php

/**
 * OpenConnector SMS Delivery Result.
 *
 * Immutable value object returned by every {@see SmsProviderInterface}
 * method: the provider-assigned message id plus the normalised lifecycle
 * status every binding (log/notifynl, and future messagebird/twilio
 * adapters) maps its own vendor-specific status vocabulary onto, so
 * {@see \OCA\OpenConnector\Service\SmsDispatchService} never needs to know a
 * provider's native status strings.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Sms
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
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Sms;

/**
 * The normalised outcome of a send/status-lookup call against an SMS provider.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */
final class DeliveryResult
{

    /**
     * Recognised normalised lifecycle statuses (mirrors `sms_message.status`).
     *
     * @var string[]
     */
    public const STATUSES = ['queued', 'sent', 'delivered', 'failed'];

    /**
     * Constructor.
     *
     * @param string      $providerMessageId The provider-assigned message id (never empty on success).
     * @param string      $status            One of {@see self::STATUSES}.
     * @param string|null $detail            Optional human-readable detail (provider status text, error message).
     */
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $status,
        public readonly ?string $detail=null,
    ) {

    }//end __construct()

    /**
     * Serialise to the plain array shape persisted on an `sms_message` object.
     *
     * @return array{providerMessageId: string, status: string, detail: string|null} The array shape.
     *
     * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-generic-sms-provider-contract-req-001
     */
    public function toArray(): array
    {
        return [
            'providerMessageId' => $this->providerMessageId,
            'status'            => $this->status,
            'detail'            => $this->detail,
        ];

    }//end toArray()
}//end class
