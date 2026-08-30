<?php

/**
 * Integriq mTLS Configuration Exception.
 *
 * Raised by {@see \OCA\Integriq\Service\Mtls\MtlsConfigResolver} when
 * certificate material is missing, undecryptable, malformed, expired, or
 * protected by a passphrase that does not unlock it — always BEFORE any
 * network call is attempted (fail-closed, pre-flight).
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
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

/**
 * Thrown on any mTLS certificate/key configuration failure.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
 */
class MtlsConfigurationException extends MtlsTransportException {
}//end class
