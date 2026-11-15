<?php

/**
 * Unit tests for SourcesController::test(), the Test-connection action.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SourcesController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\SourceTestServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The call a connection test builds from the request, and how each test outcome is answered.
 *
 * @covers \OCA\Integriq\Controller\SourcesController
 */
final class SourcesControllerConnectionTestTest extends TestCase {

	/** @var OrObjectService&MockObject */
	private OrObjectService $objects;

	/** @var SourceTestServiceInterface&MockObject */
	private SourceTestServiceInterface $tester;

	/** @var ActionAuthServiceInterface&MockObject */
	private ActionAuthServiceInterface $actionAuth;

	/** @var IUser|null */
	private ?IUser $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects    = $this->createMock(OrObjectService::class);
		$this->tester     = $this->createMock(SourceTestServiceInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->user       = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The controller for one request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return SourcesController
	 */
	private function controller(array $params = []): SourcesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $vars = []): string => vsprintf($text, $vars));

		return new SourcesController('integriq', $request, $this->objects, $l, $session, $this->actionAuth);
	}//end controller()

	/**
	 * A full request becomes headers, query, body and a content type on the given method and endpoint.
	 *
	 * @return void
	 */
	public function testTheRequestBecomesTheCall(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://api'], 'src-1');
		$this->objects->method('find')->willReturn($source);
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'source.test');
		$this->tester->expects($this->once())->method('run')
			->with($source, '/zaken', 'POST', [
				'headers' => ['X-Trace' => '1', 'Content-Type' => 'application/json'],
				'query'   => ['page' => '2'],
				'body'    => '{"a":1}',
			])
			->willReturn(['outcome' => 'ok', 'result' => ['response' => ['statusCode' => 200]]]);

		$response = $this->controller([
			'headers'  => ['X-Trace' => '1'],
			'query'    => ['page' => '2'],
			'method'   => 'POST',
			'endpoint' => '/zaken',
			'body'     => '{"a":1}',
			'type'     => 'json',
		])->test($this->tester, 'src-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['response' => ['statusCode' => 200]], $response->getData());
	}//end testTheRequestBecomesTheCall()

	/**
	 * Without parameters the test is a GET of the source's root with no options.
	 *
	 * @return void
	 */
	public function testWithoutParametersItIsAPlainGet(): void {
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'src-1'));
		$this->tester->expects($this->once())->method('run')
			->with($this->anything(), '', 'GET', [])
			->willReturn(['outcome' => 'ok', 'result' => []]);

		$this->controller(['headers' => 'not a list', 'query' => 'nor this', 'type' => 'csv'])->test($this->tester, 'src-1');
	}//end testWithoutParametersItIsAPlainGet()

	/**
	 * The xml and yaml types set their content types.
	 *
	 * @return void
	 */
	public function testXmlAndYamlSetTheirContentTypes(): void {
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'src-1'));
		$seen = [];
		$this->tester->method('run')->willReturnCallback(function ($source, $endpoint, $method, $config) use (&$seen): array {
			$seen[] = $config['headers']['Content-Type'];
			return ['outcome' => 'ok', 'result' => []];
		});

		$this->controller(['type' => 'xml'])->test($this->tester, 'src-1');
		$this->controller(['type' => 'yaml'])->test($this->tester, 'src-1');

		$this->assertSame(['application/xml', 'application/x-yaml'], $seen);
	}//end testXmlAndYamlSetTheirContentTypes()

	/**
	 * A failed test answers 502 with the reason, a test without a response 502 saying so.
	 *
	 * @return void
	 */
	public function testFailedOutcomesAnswer502(): void {
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'src-1'));
		$this->tester->method('run')->willReturnOnConsecutiveCalls(
			['outcome' => SourceTestServiceInterface::OUTCOME_FAILED, 'error' => 'connection refused'],
			['outcome' => SourceTestServiceInterface::OUTCOME_NO_RESPONSE]
		);
		$controller = $this->controller();

		$failed = $controller->test($this->tester, 'src-1');
		$this->assertSame(502, $failed->getStatus());
		$this->assertSame(['error' => 'The source test could not be completed: connection refused'], $failed->getData());

		$empty = $controller->test($this->tester, 'src-1');
		$this->assertSame(502, $empty->getStatus());
		$this->assertSame(['error' => 'The source test returned no response data.'], $empty->getData());
	}//end testFailedOutcomesAnswer502()

	/**
	 * An unknown source answers 404, and no user 401, without a test.
	 *
	 * @return void
	 */
	public function testUnknownSourceAndNoUser(): void {
		$this->tester->expects($this->never())->method('run');
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$missing = $this->controller()->test($this->tester, 'nope');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Not Found'], $missing->getData());

		$this->user = null;
		$this->assertSame(401, $this->controller()->test($this->tester, 'src-1')->getStatus());
	}//end testUnknownSourceAndNoUser()
}//end class
