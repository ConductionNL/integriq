<?php

/**
 * Raised when a registry source cannot be reached for a read.
 *
 * @category Exception
 * @package  OCA\Integriq\PropertySource\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\PropertySource\Exception;

/**
 * The source was configured and tried, and it did not answer.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-an-unreachable-source-degrades-to-a-labelled-last-value-req-rfs-005
 */
class SourceUnreachableException extends PropertySourceException {
}//end class
