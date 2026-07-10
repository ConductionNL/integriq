<?php
/**
 * OpenConnector Brokered Call Configuration Exception.
 *
 * Raised when a Source carrying a `credentialRef` is misconfigured or cannot
 * be dispatched through the OpenRegister credential broker: sibling embedded
 * secret fields, an ambiguous or unresolvable `credentialName`, a missing
 * credential, v1 scope violations (SOAP / asynchronous / TLS client certs),
 * an unavailable broker, or a sessionless call against a broker without
 * acting-user support. The engine maps every instance to a synthetic 409
 * config-error CallLog via `CallService::saveEarlyErrorLog()` — there is NO
 * fallback to embedded authentication. Messages MUST stay secret-free: they
 * may name configuration KEYS and references, never credential values or
 * request payloads.
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
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
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Signals a hard config error on a brokered (credentialRef) source call.
 *
 * Mapped to a synthetic 409 config-error CallLog; never falls back to
 * embedded secrets (REQ-SBC-001 / REQ-SBC-004).
 *
 * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
 */
class BrokeredCallConfigurationException extends Exception
{
}//end class
