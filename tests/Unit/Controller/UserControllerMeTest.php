<?php

declare(strict_types=1);

/**
 * Characterisation tests for UserController::me()'s Basic-auth fallback and
 * updateMe()'s data shaping.
 *
 * @category  Test
 * @package   OCA\Integriq\Tests\Unit\Controller
 * @author    Conduction.nl <info@conduction.nl>
 * @copyright Conduction.nl 2026
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\UserController;
use OCA\Integriq\Service\SecurityService;
use OCA\Integriq\Service\UserService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which Authorization headers authenticate /me, which are refused, that
 * a session user is never re-checked, and the headers every answer carries.
 */
class UserControllerMeTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * The mocked user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * The mocked user service.
	 *
	 * @var UserService&MockObject
	 */
	private $userService;

	/**
	 * Every checkPassword() call.
	 *
	 * @var list<array{0:string,1:string}>
	 */
	private array $checked = [];

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userService = $this->createMock(UserService::class);
		$this->userService->method('buildUserDataArray')->willReturnCallback(
			static fn (IUser $user): array => ['uid' => $user->getUID()]
		);
		$this->checked = [];
		$this->userManager->method('checkPassword')->willReturnCallback(
			function (string $username, string $password) {
				$this->checked[] = [$username, $password];
				if ($username === 'jan' && $password === 'geheim:met:dubbelepunt') {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn('jan');
					return $user;
				}

				return false;
			}
		);

	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return UserController
	 */
	private function controller(): UserController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new UserController(
			'integriq',
			$this->request,
			$this->userManager,
			$this->createMock(IUserSession::class),
			new SecurityService(cacheFactory: $cache, logger: $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
			$this->userService,
			$l10n
		);

	}//end controller()

	/**
	 * Answer /me with the given Authorization header and no session user.
	 *
	 * @param string $authorization The header.
	 *
	 * @return array{0:int,1:mixed,2:array} Status, data and headers.
	 */
	private function meWith(string $authorization): array {
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Authorization' ? $authorization : '')
		);
		$response = $this->controller()->me();

		return [$response->getStatus(), $response->getData(), $this->rawHeaders(response: $response)];

	}//end meWith()

	/**
	 * The headers a response set itself, without the framework defaults
	 * getHeaders() adds from a running server.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array<string,string>
	 */
	private function rawHeaders(JSONResponse $response): array {
		$property = new \ReflectionProperty(Response::class, 'headers');
		$property->setAccessible(true);

		return $property->getValue($response);

	}//end rawHeaders()

	/**
	 * Valid Basic credentials authenticate, splitting on the first colon.
	 *
	 * @return void
	 */
	public function testValidBasicCredentialsAuthenticate(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);

		[$status, $data, $headers] = $this->meWith('Basic ' . base64_encode('jan:geheim:met:dubbelepunt'));

		$this->assertSame(200, $status);
		$this->assertSame(['uid' => 'jan'], $data);
		$this->assertSame([['jan', 'geheim:met:dubbelepunt']], $this->checked);
		$this->assertSame('DENY', $headers['X-Frame-Options']);
		$this->assertSame('http://localhost:3000', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('true', $headers['Access-Control-Allow-Credentials']);

	}//end testValidBasicCredentialsAuthenticate()

	/**
	 * Every other header is refused with 401; only a well-formed pair is
	 * ever checked.
	 *
	 * @return void
	 */
	public function testEveryOtherAuthorizationIsRefused(): void {
		$this->userService->method('getCurrentUser')->willReturn(null);
		$cases = [
			''                                        => [],
			'Bearer abc'                              => [],
			'basic ' . base64_encode('jan:x')         => [],
			'Basic !!!not-base64'                     => [],
			'Basic ' . base64_encode('no-colon')      => [],
			'Basic ' . base64_encode('jan:wrong')     => [['jan', 'wrong']],
			'Basic ' . base64_encode(':')             => [['', '']],
		];
		foreach ($cases as $header => $expectedChecks) {
			$this->checked = [];
			$this->request = $this->createMock(IRequest::class);
			[$status, $data, $headers] = $this->meWith($header);
			$this->assertSame(401, $status, $header);
			$this->assertSame(['message' => 'Current user is not logged in'], $data, $header);
			$this->assertSame($expectedChecks, $this->checked, $header);
			$this->assertSame('DENY', $headers['X-Frame-Options'], $header);
			$this->assertSame('true', $headers['Access-Control-Allow-Credentials'], $header);
		}

	}//end testEveryOtherAuthorizationIsRefused()

	/**
	 * A session user answers without the header being checked; a failure
	 * answers 500 with the same headers.
	 *
	 * @return void
	 */
	public function testASessionUserIsNeverRecheckedAndAFailureAnswersFiveHundred(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('piet');
		$this->userService->method('getCurrentUser')->willReturnOnConsecutiveCalls($user, $this->throwException(new \RuntimeException('x')));

		[$status, $data] = $this->meWith('Basic ' . base64_encode('jan:wrong'));
		$this->assertSame([200, ['uid' => 'piet']], [$status, $data]);
		$this->assertSame([], $this->checked);

		$response = $this->controller()->me();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve user information'], $response->getData());
		$this->assertSame('true', $this->rawHeaders(response: $response)['Access-Control-Allow-Credentials']);

	}//end testASessionUserIsNeverRecheckedAndAFailureAnswersFiveHundred()

	/**
	 * updateMe() drops underscore parameters, passes the rest sanitised, and
	 * adds the organisation message only when the organisation changed.
	 *
	 * @return void
	 */
	public function testUpdateMeDropsSystemParametersAndReportsTheOrganisation(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$this->userService->method('getCurrentUser')->willReturn($user);
		$this->request->method('getParams')->willReturn(['_route' => 'x', 'displayName' => '<b>Jan</b>', '_' => 1, 'role' => 'r']);
		$this->userService->expects($this->exactly(2))->method('updateUserProperties')
			->with($user, ['displayName' => '&lt;b&gt;Jan&lt;/b&gt;', 'role' => 'r'])
			->willReturnOnConsecutiveCalls(
				['organisation_updated' => true, 'organisation_message' => 'Switched'],
				['organisation_updated' => false, 'organisation_message' => 'ignored']
			);

		$first = $this->controller()->updateMe();
		$this->assertSame(['uid' => 'jan', 'update_message' => 'Switched'], $first->getData());
		$this->assertSame('DENY', $this->rawHeaders(response: $first)['X-Frame-Options']);
		$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $this->rawHeaders(response: $first));

		$this->assertSame(['uid' => 'jan'], $this->controller()->updateMe()->getData());

	}//end testUpdateMeDropsSystemParametersAndReportsTheOrganisation()

}//end class
