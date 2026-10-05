<?php

/**
 * Integriq Call Event Not Found Exception.
 *
 * Raised when a contact moment names a call that has no single ended event on
 * the call event log: an unknown callId, a call that has not ended, one swept
 * after 30 days, or one callId ended on two sources without a sourceId to tell
 * them apart. The message names no phone number.
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a contact moment names a call with no single ended event.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-contact-moment-is-written-only-when-the-agent-asks-req-007
 */
class CallEventNotFoundException extends Exception {
}//end class
