<?php

/**
 * Stub for OCA\OpenRegister\Exception\ValidationException.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment, and it is not present in every leg of the CI
 * matrix either — the NC stable31 legs have no openregister checkout, which is
 * where ProductSubscriptionsControllerTest's two activation-failure cases
 * errored with "Class not found" while the stable32 legs passed.
 *
 * ProductSubscriptionsController catches this exception to translate a failed
 * subscription activation into a 400 instead of a 500; the tests construct one
 * to prove that. This stub keeps that catchable without a full Nextcloud
 * server, exactly as the NotAuthorizedException stub beside it does.
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
 * Minimal stub for OCA\OpenRegister\Exception\ValidationException.
 */
class ValidationException extends Exception
{
    public function __construct(
        string $message = 'Validation failed',
        int $code = 400,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
