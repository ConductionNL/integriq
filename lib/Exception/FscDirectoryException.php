<?php

/**
 * Integriq FSC Directory Exception.
 *
 * A specialisation of {@see FscConnectivityException} raised specifically
 * when directory resolution fails to find the requested organisation or
 * service — as opposed to a transport/configuration failure of the
 * directory or downstream call itself. Kept as a subclass (not a sibling)
 * so a caller that only wants "any FSC failure" can still catch
 * `FscConnectivityException`, while `FscController` differentiates the
 * unknown-organisation/unknown-service case (HTTP 404) from every other
 * failure (HTTP 502/503).
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
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

/**
 * Thrown when an organisation or service cannot be resolved via the directory.
 *
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
 */
class FscDirectoryException extends FscConnectivityException {
}//end class
