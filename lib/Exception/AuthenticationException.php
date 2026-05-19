<?php

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Exception for storing authentication exceptions with details.
 */
class AuthenticationException extends Exception
{

    private array $details;

    /**
     * @inheritDoc
     *
     * @param array $details The details describing why an authentication failed.
     */
    public function __construct(string $message, array $details)
    {
        $this->details = $details;
        parent::__construct($message);
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
