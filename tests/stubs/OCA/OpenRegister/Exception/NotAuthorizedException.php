<?php

/**
 * Stub for OCA\OpenRegister\Exception\NotAuthorizedException.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub satisfies unit tests constructing or
 * catching this exception without a full Nextcloud server.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Minimal stub for OCA\OpenRegister\Exception\NotAuthorizedException.
 */
class NotAuthorizedException extends Exception {
	public function __construct(
		string $message = 'You are not authorized to perform this action',
		int $code = 403,
		?Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}
}
