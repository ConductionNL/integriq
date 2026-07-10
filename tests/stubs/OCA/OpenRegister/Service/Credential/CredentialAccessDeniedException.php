<?php
/**
 * Test stub for OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException.
 *
 * Mirrors openregister origin/development: a RuntimeException thrown when any
 * of the broker's four ordered guards fails closed (mapped to a static 403).
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use RuntimeException;

/**
 * Stub: signals a fail-closed broker guard rejection (maps to a static 403).
 */
class CredentialAccessDeniedException extends RuntimeException
{
}//end class
