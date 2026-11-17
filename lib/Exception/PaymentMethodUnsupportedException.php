<?php

/**
 * Integriq PaymentMethodUnsupportedException.
 *
 * Raised when a provider binding has no such payment method, such as point of sale on the
 * `log` provider. A PaymentProviderException; the payment command answers it with refusal
 * code `unsupported`, which lets the caller fall back to a checkout QR code.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-payment-can-be-taken-on-a-registered-point-of-sale-device-req-lpp-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

/**
 * Thrown when the provider binding cannot take the requested method.
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-payment-can-be-taken-on-a-registered-point-of-sale-device-req-lpp-009
 */
class PaymentMethodUnsupportedException extends PaymentProviderException {
}//end class
