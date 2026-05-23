<?php
/**
 * OpenConnector Authentication Exception.
 *
 * Exception type that carries additional details describing why an
 * authentication attempt failed.
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Exception for storing authentication exceptions with details.
 */
class AuthenticationException extends Exception
{

    /**
     * Detail payload describing why the authentication failed.
     *
     * @var array
     */
    private array $details;

    /**
     * Constructor.
     *
     * @param string $message The exception message.
     * @param array  $details The details describing why an authentication failed.
     */
    public function __construct(string $message, array $details)
    {
        $this->details = $details;
        parent::__construct(message: $message);

    }//end __construct()

    /**
     * Retrieves the details to display them.
     *
     * @return array The details array.
     */
    public function getDetails(): array
    {
        return $this->details;

    }//end getDetails()
}//end class
