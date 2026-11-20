<?php

/**
 * Why a database source refused or failed a call.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\Database
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\Database;

use RuntimeException;

/**
 * A database source refused a call (an undeclared statement, a missing
 * driver, a password stored inline) or the database failed it.
 *
 * The message is safe to show an administrator: it never carries the
 * password or a bound value.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
class DatabaseSourceException extends RuntimeException {
}//end class
