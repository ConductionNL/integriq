<?php

/**
 * Integriq mTLS Handshake Exception.
 *
 * Raised by {@see \OCA\Integriq\Service\Mtls\MtlsTransportService} when
 * the wrapped Guzzle dispatch fails while mTLS is configured (TLS handshake
 * failure, connection reset, certificate rejected by the peer, etc.). Never
 * a signal to retry without a client certificate — mTLS never fails open.
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
 * Thrown when an mTLS-configured HTTP dispatch fails.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
 */
class MtlsHandshakeException extends MtlsTransportException {
}//end class
