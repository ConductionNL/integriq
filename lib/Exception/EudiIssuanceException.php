<?php

/**
 * OpenConnector EUDI Issuance Exception.
 *
 * Thrown by the OpenID4VCI EUDI wallet credential issuance services
 * (offer creation/resolution, token, credential, status-list, revocation)
 * on any hard-reject condition. Carries the HTTP status the controller
 * layer must surface, mirroring {@see \OCA\OpenConnector\Exception\LtiValidationException}'s
 * shape for the LTI adapter.
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Exception for EUDI wallet issuance protocol validation failures.
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
 */
class EudiIssuanceException extends Exception {

	/**
	 * The HTTP status code the controller layer MUST return for this failure.
	 *
	 * @var integer
	 */
	private int $httpStatus;

	/**
	 * OpenID4VCI/OAuth error code (e.g. `invalid_grant`, `invalid_request`), when applicable.
	 *
	 * @var string|null
	 */
	private ?string $errorCode;

	/**
	 * Detail payload describing why the request was rejected. MUST NEVER
	 * contain private key material, plaintext pre-authorized_code, or
	 * plaintext access_token values.
	 *
	 * @var array
	 */
	private array $details;

	/**
	 * Constructor.
	 *
	 * @param string $message The exception message (never includes secret material).
	 * @param integer $httpStatus The HTTP status code to surface.
	 * @param string|null $errorCode OpenID4VCI/OAuth error code, or null.
	 * @param array $details Details describing why the request was rejected.
	 */
	public function __construct(string $message, int $httpStatus = 400, ?string $errorCode = null, array $details = []) {
		parent::__construct(message: $message);
		$this->httpStatus = $httpStatus;
		$this->errorCode = $errorCode;
		$this->details = $details;

	}//end __construct()

	/**
	 * Get the HTTP status this failure MUST be surfaced as.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
	 */
	public function getHttpStatus(): int {
		return $this->httpStatus;
	}//end getHttpStatus()

	/**
	 * Get the OpenID4VCI/OAuth error code, if any.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
	 */
	public function getErrorCode(): ?string {
		return $this->errorCode;
	}//end getErrorCode()

	/**
	 * Retrieves the details to display them.
	 *
	 * @return array The details array.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
	 */
	public function getDetails(): array {
		return $this->details;
	}//end getDetails()
}//end class
