<?php

/**
 * OpenConnector mTLS Transport Exception.
 *
 * Base exception for the shared mutual-TLS (mTLS) client-certificate
 * transport ({@see \OCA\OpenConnector\Service\Mtls\MtlsTransportService}).
 * Every failure mode (missing/invalid certificate material, expired
 * certificate, wrong passphrase, handshake failure) is raised as this type
 * or a subclass, carrying a stable `errorCode` constant so callers can
 * branch on failure kind without parsing messages. Messages MUST stay
 * secret-free — they may name configuration keys, errorCodes, and temp
 * file paths, NEVER certificate/key contents or passphrase values.
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
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;
use Throwable;

/**
 * Thrown on any mTLS transport configuration or dispatch failure.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
 */
class MtlsTransportException extends Exception {

	/**
	 * Certificate or private key material is missing from configuration.
	 *
	 * @var string
	 */
	public const ERROR_MATERIAL_MISSING = 'MTLS_MATERIAL_MISSING';

	/**
	 * Stored (encrypted) material could not be decrypted.
	 *
	 * @var string
	 */
	public const ERROR_DECRYPTION_FAILED = 'MTLS_DECRYPTION_FAILED';

	/**
	 * The certificate PEM is not well-formed.
	 *
	 * @var string
	 */
	public const ERROR_INVALID_CERTIFICATE = 'MTLS_INVALID_CERTIFICATE';

	/**
	 * The private key PEM is not well-formed.
	 *
	 * @var string
	 */
	public const ERROR_INVALID_PRIVATE_KEY = 'MTLS_INVALID_PRIVATE_KEY';

	/**
	 * The certificate's validity end date is in the past.
	 *
	 * @var string
	 */
	public const ERROR_CERTIFICATE_EXPIRED = 'MTLS_CERTIFICATE_EXPIRED';

	/**
	 * The configured passphrase does not unlock the private key.
	 *
	 * @var string
	 */
	public const ERROR_PASSPHRASE_INVALID = 'MTLS_PASSPHRASE_INVALID';

	/**
	 * The TLS handshake or underlying HTTP dispatch failed.
	 *
	 * @var string
	 */
	public const ERROR_HANDSHAKE_FAILED = 'MTLS_HANDSHAKE_FAILED';

	/**
	 * The stable machine-readable error code for this failure.
	 *
	 * @var string
	 */
	private string $errorCode;

	/**
	 * Constructor.
	 *
	 * @param string $message The exception message (never includes secret material).
	 * @param string $errorCode One of the `ERROR_*` constants above.
	 * @param Throwable|null $previous The previous throwable, if any.
	 */
	public function __construct(string $message, string $errorCode, ?Throwable $previous = null) {
		parent::__construct(message: $message, previous: $previous);
		$this->errorCode = $errorCode;

	}//end __construct()

	/**
	 * Get the stable machine-readable error code for this failure.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()
}//end class
