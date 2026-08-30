<?php

/**
 * Integriq Digikoppeling Exception.
 *
 * Raised when a Digikoppeling transport operation cannot proceed: a WS-Security
 * signature that is missing or fails to verify on a WUS response, a Grote
 * Berichten payload whose checksum does not match, or — most importantly — a
 * PKIoverheid `certificateRef` whose signing key material cannot be supplied
 * for in-process WS-Security signing. In the last case the adapter fails closed
 * (never falling back to a plaintext on-disk key) per REQ-DK-005 / ADR-007.
 *
 * Messages MUST stay secret-free: they may name configuration KEYS and
 * references, never key material.
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
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown on any Digikoppeling transport or configuration failure.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class DigikoppelingException extends Exception {
}//end class
