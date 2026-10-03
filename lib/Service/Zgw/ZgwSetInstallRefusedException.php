<?php

/**
 * A packaged ZGW set that could not be installed, with the reason an operator reads.
 *
 * @category Exception
 * @package  OCA\Integriq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Zgw;

use RuntimeException;

/**
 * Thrown by ZgwSetInstaller before anything is saved; the message is the refusal.
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
 */
class ZgwSetInstallRefusedException extends RuntimeException {
}//end class
