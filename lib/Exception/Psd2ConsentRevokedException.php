<?php

/**
 * Integriq PSD2 Consent Revoked Exception.
 *
 * Raised when the aggregator signals that a previously granted PSD2 AIS
 * consent is no longer usable (revoked at the bank or aggregator side, e.g. a
 * 401/403 on a consent-scoped account/transaction call). The sync machinery
 * catches this to move the `bankfeed_connection` to `revoked` and emit
 * `nl.conduction.bankfeed.consent.revoked` (REQ-005) instead of retrying a
 * dead consent.
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
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-consent-lifecycle-cloudevents-for-consumer-state-transitions-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

/**
 * Thrown when the aggregator reports a revoked/unusable PSD2 consent.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-consent-lifecycle-cloudevents-for-consumer-state-transitions-req-005
 */
class Psd2ConsentRevokedException extends Psd2ProviderException {
}//end class
