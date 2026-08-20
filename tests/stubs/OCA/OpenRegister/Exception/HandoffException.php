<?php

/**
 * Stub for OCA\OpenRegister\Exception\HandoffException.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub mirrors the real exception's error-code
 * discriminator so unit tests can construct/catch it without a full
 * Nextcloud server.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Minimal stub for OCA\OpenRegister\Exception\HandoffException.
 */
class HandoffException extends \RuntimeException {
	public const NOT_DECLARED = 'handoff-not-declared';

	public const PROVIDER_UNAVAILABLE = 'handoff-provider-unavailable';

	private string $errorCode;

	public function __construct(string $errorCode, string $message, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
		$this->errorCode = $errorCode;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}
}
