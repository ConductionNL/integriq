<?php

/**
 * Integriq Payment Provider Probe Interface.
 *
 * An optional, read-only check a payment binding offers so an administrator
 * can see whether a source's key is accepted, and whether it is a test or a
 * live key, before the source goes live. Kept apart from
 * {@see PaymentProviderInterface} so a binding written against that interface
 * keeps compiling.
 *
 * @category Service
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
 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

/**
 * A payment binding that can check a source configuration without charging anyone.
 *
 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
 */
interface PaymentProviderProbeInterface {
	/**
	 * Check the source configuration against the provider with a read-only call.
	 *
	 * The answer never carries the key: `ok` says whether the provider accepted
	 * it, `mode` is `log`, `test` or `live` (null when refused), `profile` names
	 * the provider account the key belongs to when the provider says so, and
	 * `message` explains a refusal.
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 *
	 * @return array{ok: bool, mode: ?string, profile: ?string, message: ?string} The check result.
	 *
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
	 */
	public function probe(array $sourceConfiguration): array;
}//end interface
