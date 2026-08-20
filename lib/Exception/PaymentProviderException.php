<?php

/**
 * OpenConnector Payment Provider Exception.
 *
 * Raised when a payment-provider operation cannot proceed: a malformed
 * create-payment request, an unreachable/erroring PSP, a `mollie` provider
 * whose `credentialRef` cannot be brokered, or a provider configuration
 * error (e.g. no active payment source configured). Per REQ-LPP-006 /
 * ADR-007 messages MUST stay secret-free — they may name configuration keys
 * and references, never key material.
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
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
 * @spec openspec/specs/live-payment-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any payment-provider or configuration failure.
 *
 * @spec openspec/specs/live-payment-providers/spec.md
 */
class PaymentProviderException extends Exception {
}//end class
