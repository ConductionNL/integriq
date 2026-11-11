<?php

/**
 * Integriq retour envelope refusal.
 *
 * Raised by RetourAcknowledgementReader when a retour envelope is empty,
 * malformed or carries no `stuurgegevens.kenmerk`. Each acknowledgement
 * translator catches it and raises its own domain exception with the same
 * message, so callers keep seeing the exception type they handle today.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Stuf
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
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Stuf;

use RuntimeException;

/**
 * A retour envelope that cannot be turned into a status update.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */
final class RetourEnvelopeRefusedException extends RuntimeException {
}//end class
