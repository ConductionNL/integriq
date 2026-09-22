<?php

/**
 * Integriq IdpAssertion Exception.
 *
 * Raised when a government authentication cannot produce a subject envelope:
 * the broker is not configured, the assertion fails a check, the trust level
 * is unmapped, or an artefact has been used already.
 *
 * The message names the CHECK that refused, never the assertion. It is read
 * by an operator in a log and, abbreviated, by a person in a browser, and an
 * assertion pasted into either is a BSN pasted into either. Messages MUST
 * stay secret-free.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a government authentication cannot produce an envelope.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */
class IdpAssertionException extends Exception {
}//end class
