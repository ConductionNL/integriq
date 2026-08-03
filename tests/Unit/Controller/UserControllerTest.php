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
 * @link       https://github.com/ConductionNL/openconnector
 */

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\UserController;
use OCA\OpenConnector\Service\UserService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the UserController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 */
class UserControllerTest extends TestCase
{
    /** @var UserController */
    private UserController $controller;

    /** @var MockObject&IRequest */
    private MockObject $request;

    /** @var MockObject&IUserManager */
    private MockObject $userManager;

    /** @var MockObject&IUserSession */
    private MockObject $userSession;

    /** @var MockObject&ICacheFactory */
    private MockObject $cacheFactory;

    /** @var MockObject&LoggerInterface */
    private MockObject $logger;

    /** @var MockObject&UserService */
    private MockObject $userService;

    /** @var MockObject&IL10N */
    private MockObject $l10n;

    /** @var MockObject&IUser */
    private MockObject $user;

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(IRequest::class);
        $this->userManager = $this->createMock(IUserManager::class);
        // IUserSession does not declare createSessionToken() in its interface,
        // but UserController calls it at runtime (it exists on the concrete NC
        // implementation). Use getMockBuilder + addMethods so PHPUnit allows
        // ->method('createSessionToken') without raising
        // MethodCannotBeConfiguredException.
        $this->userSession = $this->getMockBuilder(IUserSession::class)
            ->addMethods(['createSessionToken'])
            ->getMockForAbstractClass();
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->userService = $this->createMock(UserService::class);
        $this->l10n        = $this->createMock(IL10N::class);
        $this->user        = $this->createMock(IUser::class);

        // SecurityService is instantiated inside UserController's constructor
        // via `new SecurityService($cacheFactory, $logger)`. It calls
        // $cacheFactory->createDistributed() so we must stub that to return
        // a usable ICache mock.
        $cache              = $this->createMock(ICache::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        // IL10N::t() must return the string so error messages are populated.
        $this->l10n->method('t')->willReturnArgument(0);

        $this->controller = new UserController(
            'openconnector',
            $this->request,
            $this->userManager,
            $this->userSession,
            $this->cacheFactory,
            $this->logger,
            $this->userService,
            $this->l10n
        );
    }

    // -----------------------------------------------------------------------
    // me() tests
    // -----------------------------------------------------------------------

    /**
     * Test successful retrieval of current user information via me().
     *
     * @return void
     */
    public function testMeSuccessful(): void
    {
        $userData = [
            'uid'         => 'testuser',
            'displayName' => 'Test User',
            'email'       => 'test@example.com',
            'enabled'     => true,
        ];

        $this->userService->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($this->user);

        $this->userService->expects($this->once())
            ->method('buildUserDataArray')
            ->with($this->user)
            ->willReturn($userData);

        $response = $this->controller->me();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertEquals('testuser', $data['uid']);
        $this->assertEquals('Test User', $data['displayName']);
        $this->assertEquals('test@example.com', $data['email']);
        $this->assertTrue($data['enabled']);
    }

    /**
     * Test me() returns 401 when no user is authenticated.
     *
     * The controller returns `['message' => ...]` (not `['error' => ...]`) for
     * the unauthenticated case in me().
     *
     * @return void
     */
    public function testMeUnauthenticated(): void
    {
        // No Authorization header, getCurrentUser returns null.
        $this->request->method('getHeader')->willReturn('');
        $this->userService->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn(null);

        $response = $this->controller->me();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());

        $data = $response->getData();
        $this->assertArrayHasKey('message', $data);
    }

    /**
     * Test me() returns 500 when an unexpected exception is thrown.
     *
     * @return void
     */
    public function testMeException(): void
    {
        $this->userService->expects($this->once())
            ->method('getCurrentUser')
            ->willThrowException(new \Exception('Unexpected error'));

        $response = $this->controller->me();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContainsString('Failed to retrieve user information', $response->getData()['error']);
    }

    // -----------------------------------------------------------------------
    // updateMe() tests
    // -----------------------------------------------------------------------

    /**
     * Test successful user information update via updateMe().
     *
     * @return void
     */
    public function testUpdateMeSuccessful(): void
    {
        $updateData = ['displayName' => 'Updated User'];

        $this->userService->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($this->user);

        $this->request->method('getParams')->willReturn($updateData);

        $this->userService->expects($this->once())
            ->method('updateUserProperties')
            ->with($this->user, $updateData)
            ->willReturn(['organisation_updated' => false, 'organisation_message' => '']);

        $this->userService->expects($this->once())
            ->method('buildUserDataArray')
            ->with($this->user)
            ->willReturn(['uid' => 'testuser', 'displayName' => 'Updated User']);

        $response = $this->controller->updateMe();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test updateMe() returns 401 when no user is authenticated.
     *
     * @return void
     */
    public function testUpdateMeUnauthenticated(): void
    {
        $this->userService->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn(null);

        $response = $this->controller->updateMe();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());

        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test updateMe() returns 500 when an unexpected exception is thrown.
     *
     * @return void
     */
    public function testUpdateMeException(): void
    {
        $this->userService->expects($this->once())
            ->method('getCurrentUser')
            ->willThrowException(new \Exception('Unexpected error'));

        $response = $this->controller->updateMe();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContainsString('Failed to update user information', $response->getData()['error']);
    }

    // -----------------------------------------------------------------------
    // login() tests
    // -----------------------------------------------------------------------

    /**
     * Test successful user login.
     *
     * SecurityService::validateLoginCredentials() runs inside login(). We
     * supply valid credentials in the request so it passes, then stub
     * checkLoginRateLimit via ICache::get() returning null (no lockout).
     *
     * @return void
     */
    public function testLoginSuccessful(): void
    {
        $this->user->method('getUID')->willReturn('testuser');
        $this->user->method('isEnabled')->willReturn(true);

        $loginData = ['username' => 'testuser', 'password' => 'ValidPass1!'];
        $this->request->method('getParams')->willReturn($loginData);
        $this->request->method('getHeader')->willReturn('');

        $this->userManager->expects($this->once())
            ->method('checkPassword')
            ->with('testuser', 'ValidPass1!')
            ->willReturn($this->user);

        // After successful auth the controller calls createSessionToken() + getUser()
        // to verify the session was established.
        $this->userSession->method('createSessionToken')->willReturn(null);
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->userService->method('buildUserDataArray')
            ->willReturn(['uid' => 'testuser']);

        $response = $this->controller->login();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertArrayHasKey('message', $data);
    }

    /**
     * Test login with invalid credentials returns 401.
     *
     * The request is well formed; it is the *authentication* that failed, so the
     * status is HTTP 401 Unauthorized. 400 Bad Request is reserved for
     * `validateLoginCredentials()` — a missing, too-short or illegal username, or
     * an over-long password — see testLoginMissingCredentials() below.
     *
     * Anti-enumeration is carried by the *message* ("Invalid username or
     * password", identical for an unknown user and a wrong password), not by the
     * status code; this test's previous docblock conflated the two and asserted
     * 400 even after UserController::login() was corrected to 401 in 8da2b46c.
     *
     * @spec openspec/specs/user-management-and-login/spec.md#scenario-failed-login-is-rate-limited-and-anti-enumeration
     *
     * @return void
     */
    public function testLoginInvalidCredentials(): void
    {
        $loginData = ['username' => 'testuser', 'password' => 'WrongPass1!'];
        $this->request->method('getParams')->willReturn($loginData);
        $this->request->method('getHeader')->willReturn('');

        $this->userManager->expects($this->once())
            ->method('checkPassword')
            ->with('testuser', 'WrongPass1!')
            ->willReturn(false);

        $response = $this->controller->login();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());

        // The message must not reveal whether the username exists.
        $this->assertSame(
            'Invalid username or password',
            $response->getData()['error']
        );
    }

    /**
     * Test login with missing credentials returns 400.
     *
     * @return void
     */
    public function testLoginMissingCredentials(): void
    {
        $loginData = ['username' => 'testuser'];
        $this->request->method('getParams')->willReturn($loginData);
        $this->request->method('getHeader')->willReturn('');

        $response = $this->controller->login();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Test login with empty credentials returns 400.
     *
     * @return void
     */
    public function testLoginEmptyCredentials(): void
    {
        $loginData = ['username' => '', 'password' => ''];
        $this->request->method('getParams')->willReturn($loginData);
        $this->request->method('getHeader')->willReturn('');

        $response = $this->controller->login();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Test login exception handling returns 500.
     *
     * @return void
     */
    public function testLoginException(): void
    {
        $loginData = ['username' => 'testuser', 'password' => 'ValidPass1!'];
        $this->request->method('getParams')->willReturn($loginData);
        $this->request->method('getHeader')->willReturn('');

        $this->userManager->expects($this->once())
            ->method('checkPassword')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->login();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }
}
