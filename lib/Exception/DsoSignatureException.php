<?php

/**
 * Integriq DSO Signature Exception.
 *
 * Raised when a STAM push carries an `X-DSO-Signature` that does not verify
 * against the trust configuration of the instance's `dso-stam` consumer.
 * The controller answers 401: the caller is not who it claims to be.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/consumer-management/spec.md#requirement-a-consumer-can-authenticate-by-dso-stam-signature-req-con-dso-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a STAM push signature does not verify.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/consumer-management/spec.md#requirement-a-consumer-can-authenticate-by-dso-stam-signature-req-con-dso-001
 */
class DsoSignatureException extends Exception {
}//end class
