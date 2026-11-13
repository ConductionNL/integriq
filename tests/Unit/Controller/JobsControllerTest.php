<?php

/**
 * Integriq — jobs controller tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\JobsController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\JobServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Manual job run and test run: authentication, the action check, the lookup and the result codes.
 *
 * @covers \OCA\Integriq\Controller\JobsController
 */
class JobsControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked object service.
	 *
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService $objects;

	/**
	 * The mocked job service.
	 *
	 * @var JobServiceInterface&MockObject
	 */
	private JobServiceInterface $jobs;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The mocked action authorisation.
	 *
	 * @var ActionAuthServiceInterface&MockObject
	 */
	private ActionAuthServiceInterface $actionAuth;

	/**
	 * The controller under test.
	 *
	 * @var JobsController
	 */
	private JobsController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request    = $this->createMock(IRequest::class);
		$this->objects    = $this->createMock(OrObjectService::class);
		$this->jobs       = $this->createMock(JobServiceInterface::class);
		$this->session    = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$l10n             = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []): string {
				return vsprintf($text, $params);
			}
		);
		$this->controller = new JobsController(
			'integriq',
			$this->request,
			$this->objects,
			$this->jobs,
			$l10n,
			$this->session,
			$this->actionAuth
		);
	}//end setUp()

	/**
	 * Sign a user in.
	 *
	 * @return IUser The signed-in user.
	 */
	private function signIn(): IUser {
		$user = $this->createMock(IUser::class);
		$this->session->method('getUser')->willReturn($user);
		return $user;
	}//end signIn()

	/**
	 * A job log entry whose serialisation is the given data.
	 *
	 * @param array<string, mixed> $data The serialised log.
	 *
	 * @return ObjectEntity The entry.
	 */
	private function log(array $data): ObjectEntity {
		$entry = $this->createMock(ObjectEntity::class);
		$entry->method('jsonSerialize')->willReturn($data);
		return $entry;
	}//end log()

	/**
	 * Both endpoints with the action each checks.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function endpoints(): array {
		return [
			'run'  => ['run', 'job.run'],
			'test' => ['test', 'job.test'],
		];
	}//end endpoints()

	/**
	 * Without a signed-in user the endpoint is unauthorised and nothing is looked up.
	 *
	 * @param string $method The controller method.
	 * @param string $action The action it checks.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testAnonymousIsUnauthorised(string $method, string $action): void {
		$this->session->method('getUser')->willReturn(null);
		$this->actionAuth->expects($this->never())->method('requireAction');
		$this->objects->expects($this->never())->method('find');

		$response = $this->controller->$method('job-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testAnonymousIsUnauthorised()

	/**
	 * A refused action stops the request before the job is looked up.
	 *
	 * @param string $method The controller method.
	 * @param string $action The action it checks.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testARefusedActionStopsTheRequest(string $method, string $action): void {
		$user = $this->signIn();
		$this->actionAuth->expects($this->once())->method('requireAction')->with($user, $action)
			->willThrowException(new RuntimeException('forbidden'));
		$this->objects->expects($this->never())->method('find');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('forbidden');
		$this->controller->$method('job-1');
	}//end testARefusedActionStopsTheRequest()

	/**
	 * An unknown job is not found.
	 *
	 * @param string $method The controller method.
	 * @param string $action The action it checks.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testUnknownJobIsNotFound(string $method, string $action): void {
		$this->signIn();
		$this->objects->expects($this->once())->method('find')
			->with('job-9', 'integriq', 'job', false, false)
			->willThrowException(new DoesNotExistException('no job'));
		$this->jobs->expects($this->never())->method('executeJob');

		$response = $this->controller->$method('job-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Job not found'], $response->getData());
	}//end testUnknownJobIsNotFound()

	/**
	 * Run parameters and the force flag each endpoint passes on.
	 *
	 * @return array<string, array{string, array<string, mixed>, bool}>
	 */
	public static function forceFlags(): array {
		return [
			'run without forceRun'  => ['run', ['_route' => 'x'], false],
			'run with forceRun yes' => ['run', ['forceRun' => 'yes'], true],
			'run with forceRun 0'   => ['run', ['forceRun' => '0'], false],
			'test always forces'    => ['test', [], true],
			'test ignores forceRun' => ['test', ['forceRun' => 'false'], true],
		];
	}//end forceFlags()

	/**
	 * The job is executed with the expected force flag and its log is returned.
	 *
	 * @param string               $method The controller method.
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $force  The expected force flag.
	 *
	 * @return void
	 */
	#[DataProvider('forceFlags')]
	public function testTheJobRunsWithTheForceFlag(string $method, array $params, bool $force): void {
		$this->signIn();
		$job = new ObjectEntity();
		$this->objects->method('find')->willReturn($job);
		$this->request->method('getParams')->willReturn($params);
		$this->jobs->expects($this->once())->method('executeJob')->with($job, $force)
			->willReturn($this->log(['level' => 'INFO', 'message' => 'done']));

		$response = $this->controller->$method('job-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['level' => 'INFO', 'message' => 'done'], $response->getData());
	}//end testTheJobRunsWithTheForceFlag()

	/**
	 * A job that did not run answers null; an ERROR log is a 500; a throw is a 500 with the message.
	 *
	 * @param string $method The controller method.
	 * @param string $action The action it checks.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testResultCodes(string $method, string $action): void {
		$this->signIn();
		$this->objects->method('find')->willReturn(new ObjectEntity());
		$this->request->method('getParams')->willReturn([]);
		$this->jobs->method('executeJob')->willReturnOnConsecutiveCalls(
			null,
			$this->log(['level' => 'ERROR', 'message' => 'boom']),
			$this->throwException(new RuntimeException('source down'))
		);

		$skipped = $this->controller->$method('job-1');
		$this->assertSame(Http::STATUS_OK, $skipped->getStatus());
		$this->assertNull($skipped->getData());

		$failed = $this->controller->$method('job-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['level' => 'ERROR', 'message' => 'boom'], $failed->getData());

		$thrown = $this->controller->$method('job-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $thrown->getStatus());
		$this->assertSame(['error' => 'Failed to execute job: source down'], $thrown->getData());
	}//end testResultCodes()
}//end class
