<?php

/**
 * Integriq PaymentSourceMissingException.
 *
 * Raised when no payment source matches a request. A PaymentProviderException, so every
 * caller that already catches that keeps working; the payment command answers it with
 * refusal code `no-source`.
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
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

/**
 * Thrown when no (enabled) payment source matches.
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
 */
class PaymentSourceMissingException extends PaymentProviderException {
}//end class
