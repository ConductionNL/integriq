<?php

/**
 * Contract of the PaymentSettlementService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Payment
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
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

/**
 * Public operations of {@see PaymentSettlementService} that other components call.
 */
interface PaymentSettlementServiceInterface {
	/**
	 * Pull every payment source whose provider is not `log`.
	 *
	 * A source that fails is logged and counted; the others still run.
	 *
	 * @return array{sources: int, reported: int, failed: int}
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function pullAll(): array;
}
