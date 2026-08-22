<?php

/**
 * Example: How other NextCloud apps can access custom name fields
 * 
 * This example shows how other apps can read and write the custom name fields
 * (firstName, lastName, middleName) that are stored by the Integriq UserService.
 */

// In any other NextCloud app, you can access the custom name fields like this:

use OCP\IConfig;
use OCP\IUser;

class ExampleOtherAppService 
{
    private IConfig $config;

    public function __construct(IConfig $config) 
    {
        $this->config = $config;
    }

    /**
     * Get custom name fields for a user (accessible from any app)
     * 
     * @param IUser $user The user object
     * @return array Array with firstName, lastName, middleName
     */
    public function getUserNameFields(IUser $user): array 
    {
        $userId = $user->getUID();
        
        return [
            'firstName' => $this->config->getUserValue($userId, 'core', 'firstName', '') ?: null,
            'lastName' => $this->config->getUserValue($userId, 'core', 'lastName', '') ?: null,  
            'middleName' => $this->config->getUserValue($userId, 'core', 'middleName', '') ?: null
        ];
    }

    /**
     * Set custom name fields for a user (accessible from any app)
     * 
     * @param IUser $user The user object
     * @param array $nameData Array with name fields to update
     */
    public function setUserNameFields(IUser $user, array $nameData): void 
    {
        $userId = $user->getUID();
        $allowedFields = ['firstName', 'lastName', 'middleName'];
        
        foreach ($allowedFields as $field) {
            if (isset($nameData[$field])) {
                $value = (string)$nameData[$field];
                $this->config->setUserValue($userId, 'core', $field, $value);
            }
        }
    }

    /**
     * Example: Use the Integriq UserService directly (if available)
     * 
     * @param IUser $user The user object
     * @return array Complete user data including name fields
     */
    public function getUserDataViaIntegriq(IUser $user): array 
    {
        // Get the Integriq UserService if available
        $userService = \OC::$server->get(\OCA\Integriq\Service\UserService::class);
        
        // This returns complete user data including firstName, lastName, middleName
        return $userService->buildUserDataArray($user);
    }

    /**
     * Example: Get just the custom name fields via Integriq UserService
     * 
     * @param IUser $user The user object
     * @return array Name fields only
     */
    public function getNameFieldsViaIntegriq(IUser $user): array 
    {
        $userService = \OC::$server->get(\OCA\Integriq\Service\UserService::class);
        
        // This returns just the name fields
        return $userService->getCustomNameFields($user);
    }

    /**
     * Example: Set name fields via Integriq UserService
     * 
     * @param IUser $user The user object
     * @param array $nameFields Array with name field values
     */
    public function setNameFieldsViaIntegriq(IUser $user, array $nameFields): void 
    {
        $userService = \OC::$server->get(\OCA\Integriq\Service\UserService::class);
        
        // This sets the name fields using the UserService
        $userService->setCustomNameFields($user, $nameFields);
    }
}

/*
 * Usage Examples:
 * 
 * 1. Direct IConfig access (available in any app):
 *    $nameFields = $exampleService->getUserNameFields($user);
 *    $exampleService->setUserNameFields($user, [
 *        'firstName' => 'John',
 *        'lastName' => 'Doe', 
 *        'middleName' => 'William'
 *    ]);
 * 
 * 2. Via Integriq UserService (if app is installed):
 *    $userData = $exampleService->getUserDataViaIntegriq($user);
 *    $nameFields = $exampleService->getNameFieldsViaIntegriq($user);
 *    $exampleService->setNameFieldsViaIntegriq($user, $nameFields);
 * 
 * The fields are stored in the 'core' namespace making them accessible 
 * to all NextCloud apps, not just Integriq.
 */ 