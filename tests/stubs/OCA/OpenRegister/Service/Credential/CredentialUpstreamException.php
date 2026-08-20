<?php

/**
 * Test stub for OCA\OpenRegister\Service\Credential\CredentialUpstreamException.
 *
 * Mirrors openregister origin/development: a RuntimeException thrown when the
 * brokered outbound call fails at the transport level AFTER all guards passed
 * (mapped to a static 502). A non-2xx upstream HTTP status is NOT this
 * exception — that is a completed call returned verbatim.
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
 * Stub: signals a transport-level failure of the brokered call (maps to a static 502).
 */
class CredentialUpstreamException extends RuntimeException {
}//end class
