<?php

declare(strict_types=1);

/**
 * UserControllerTest
 * 
 * Unit tests for the UserController
 *
 * @category   Test
 * @package    OCA\OpenConnector\Tests\Unit\Controller
 * @author     Conduction.nl <info@conduction.nl>
 * @copyright  Conduction.nl 2024
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version    1.0.0
 * @link       https://codeberg.org/Conduction/openconnector
 */

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\UserController;
use OCA\OpenConnector\Service\AuthorizationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the UserController
 *
 * This test class covers all functionality of the UserController
 * including authentication, user data retrieval, and user updates.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 */
class UserControllerTest extends TestCase
{
    /**
     * The UserController instance being tested
     *
     * @var UserController
     */
    private UserController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock user manager
     *
     * @var MockObject|IUserManager
     */
    private MockObject $userManager;

    /**
     * Mock user session
     *
     * @var MockObject|IUserSession
     */
    private MockObject $userSession;

    /**
     * Mock authorization service
     *
     * @var MockObject|AuthorizationService
     */
    private MockObject $authorizationService;

    /**
     * Mock user object
     *
     * @var MockObject|IUser
     */
    private MockObject $user;

    /**
     * Set up test environment before each test
     *
     * This method initializes all mocks and the controller instance
     * for testing purposes.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects for all dependencies
        $this->request = $this->createMock(IRequest::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->authorizationService = $this->createMock(AuthorizationService::class);
        $this->user = $this->createMock(IUser::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new UserController(
            'openconnector',
            $this->request,
            $this->userManager,
            $this->userSession,
            $this->authorizationService
        );
    }

    /**
     * Test successful retrieval of current user information
     *
     * This test verifies that the me() method returns correct user data
     * when a user is authenticated.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testMeSuccessful(): void
    {
        // Setup mock user data
        $this->setupMockUserData();

        // Mock user session to return the authenticated user
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($this->user);

        // Execute the method
        $response = $this->controller->me();

        // Assert response is successful
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        // Assert response contains expected user data
        $data = $response->getData();
        $this->assertEquals('testuser', $data['uid']);
        $this->assertEquals('Test User', $data['displayName']);
        $this->assertEquals('test@example.com', $data['email']);
        $this->assertTrue($data['enabled']);
    }

    /**
     * Test me() method when user is not authenticated
     *
     * This test verifies that the me() method returns proper error
     * when no user is logged in.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testMeUnauthenticated(): void
    {
        // Mock user session to return null (no authenticated user)
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        // Execute the method
        $response = $this->controller->me();

        // Assert response shows authentication error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(401, $response->getStatus());
        $this->assertEquals(['error' => 'User not authenticated'], $response->getData());
    }

    /**
     * Test successful user information update
     *
     * This test verifies that the updateMe() method correctly updates
     * user information when valid data is provided.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testUpdateMeSuccessful(): void
    {
        // Setup mock user data
        $this->setupMockUserData();

        // Mock user session to return the authenticated user
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($this->user);

        // Mock request parameters with update data
        $updateData = [
            'displayName' => 'Updated User Name',
            'email' => 'updated@example.com',
            'language' => 'en'
        ];
        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($updateData);

        // Mock user update methods
        $this->user->expects($this->once())
            ->method('canChangeDisplayName')
            ->willReturn(true);
        $this->user->expects($this->once())
            ->method('setDisplayName')
            ->with('Updated User Name');

        $this->user->expects($this->once())
            ->method('canChangeMailAddress')
            ->willReturn(true);
        $this->user->expects($this->once())
            ->method('setEMailAddress')
            ->with('updated@example.com');

        $this->user->expects($this->once())
            ->method('setLanguage')
            ->with('en');

        // Execute the method
        $response = $this->controller->updateMe();

        // Assert response is successful
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test updateMe() method when user is not authenticated
     *
     * This test verifies that the updateMe() method returns proper error
     * when no user is logged in.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testUpdateMeUnauthenticated(): void
    {
        // Mock user session to return null (no authenticated user)
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        // Execute the method
        $response = $this->controller->updateMe();

        // Assert response shows authentication error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(401, $response->getStatus());
        $this->assertEquals(['error' => 'User not authenticated'], $response->getData());
    }

    /**
     * Test successful user login
     *
     * This test verifies that the login() method successfully authenticates
     * a user with valid credentials.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testLoginSuccessful(): void
    {
        // Setup mock user data
        $this->setupMockUserData();

        // Mock request parameters with login credentials
        $loginData = [
            'username' => 'testuser',
            'password' => 'testpassword'
        ];
        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($loginData);

        // Mock user manager to return authenticated user
        $this->userManager->expects($this->once())
            ->method('checkPassword')
            ->with('testuser', 'testpassword')
            ->willReturn($this->user);

        // Mock user session to set the authenticated user
        $this->userSession->expects($this->once())
            ->method('setUser')
            ->with($this->user);

        // Execute the method
        $response = $this->controller->login();

        // Assert response is successful
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        // Assert response contains success message and user data
        $data = $response->getData();
        $this->assertEquals('Login successful', $data['message']);
        $this->assertArrayHasKey('user', $data);
        $this->assertEquals('testuser', $data['user']['uid']);
    }

    /**
     * Test login with invalid credentials
     *
     * This test verifies that the login() method returns proper error
     * when invalid credentials are provided.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testLoginInvalidCredentials(): void
    {
        // Mock request parameters with invalid credentials
        $loginData = [
            'username' => 'testuser',
            'password' => 'wrongpassword'
        ];
        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($loginData);

        // Mock user manager to return false for invalid credentials
        $this->userManager->expects($this->once())
            ->method('checkPassword')
            ->with('testuser', 'wrongpassword')
            ->willReturn(false);

        // Execute the method
        $response = $this->controller->login();

        // Assert response shows authentication error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(401, $response->getStatus());
        $this->assertEquals(['error' => 'Invalid username or password'], $response->getData());
    }

    /**
     * Test login with missing credentials
     *
     * This test verifies that the login() method returns proper error
     * when required credentials are missing.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testLoginMissingCredentials(): void
    {
        // Mock request parameters with missing credentials
        $loginData = [
            'username' => 'testuser'
            // password is missing
        ];
        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($loginData);

        // Execute the method
        $response = $this->controller->login();

        // Assert response shows validation error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals(['error' => 'Username and password are required'], $response->getData());
    }

    /**
     * Test login with empty credentials
     *
     * This test verifies that the login() method returns proper error
     * when credentials are empty strings.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testLoginEmptyCredentials(): void
    {
        // Mock request parameters with empty credentials
        $loginData = [
            'username' => '',
            'password' => ''
        ];
        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($loginData);

        // Execute the method
        $response = $this->controller->login();

        // Assert response shows validation error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals(['error' => 'Username and password are required'], $response->getData());
    }

    /**
     * Test exception handling in me() method
     *
     * This test verifies that the me() method properly handles exceptions
     * and returns appropriate error responses.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testMeException(): void
    {
        // Mock user session to throw exception
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willThrowException(new \Exception('Test exception'));

        // Execute the method
        $response = $this->controller->me();

        // Assert response shows error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContains('Failed to retrieve user information', $response->getData()['error']);
    }

    /**
     * Test exception handling in updateMe() method
     *
     * This test verifies that the updateMe() method properly handles exceptions
     * and returns appropriate error responses.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testUpdateMeException(): void
    {
        // Mock user session to throw exception
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willThrowException(new \Exception('Test exception'));

        // Execute the method
        $response = $this->controller->updateMe();

        // Assert response shows error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContains('Failed to update user information', $response->getData()['error']);
    }

    /**
     * Test exception handling in login() method
     *
     * This test verifies that the login() method properly handles exceptions
     * and returns appropriate error responses.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    public function testLoginException(): void
    {
        // Mock request parameters
        $loginData = [
            'username' => 'testuser',
            'password' => 'testpassword'
        ];
        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($loginData);

        // Mock user manager to throw exception
        $this->userManager->expects($this->once())
            ->method('checkPassword')
            ->willThrowException(new \Exception('Test exception'));

        // Execute the method
        $response = $this->controller->login();

        // Assert response shows error
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContains('Login failed', $response->getData()['error']);
    }

    /**
     * Set up mock user data for testing
     *
     * This helper method configures the mock user object with realistic
     * test data for use in various test scenarios.
     *
     * @return void
     * 
     * @psalm-return void
     * @phpstan-return void
     */
    private function setupMockUserData(): void
    {
        // Configure mock user with test data
        $this->user->method('getUID')->willReturn('testuser');
        $this->user->method('getDisplayName')->willReturn('Test User');
        $this->user->method('getEMailAddress')->willReturn('test@example.com');
        $this->user->method('isEnabled')->willReturn(true);
        $this->user->method('getQuota')->willReturn('1 GB');
        $this->user->method('getUsedSpace')->willReturn(524288000); // 500 MB in bytes
        $this->user->method('getAvatarScope')->willReturn('contacts');
        $this->user->method('getLastLogin')->willReturn(1640995200); // Unix timestamp
        $this->user->method('getBackendClassName')->willReturn('Database');
        $this->user->method('getLanguage')->willReturn('en');
        $this->user->method('getLocale')->willReturn('en_US');
        
        // Configure capability methods
        $this->user->method('canChangeDisplayName')->willReturn(true);
        $this->user->method('canChangeMailAddress')->willReturn(true);
        $this->user->method('canChangePassword')->willReturn(true);
        $this->user->method('canChangeAvatar')->willReturn(true);
    }
} 