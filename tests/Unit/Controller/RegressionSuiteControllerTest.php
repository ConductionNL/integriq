<?php

/**
 * Unit tests: running a suite needs the regression-suite.run action.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-running-and-recording-suites-are-named-actions-req-irt-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\RegressionSuiteController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Regression\TraceCaseRecorder;
use OCA\Integriq\Service\RegressionSuiteService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The run route's gate and answers.
 */
class RegressionSuiteControllerTest extends TestCase {

	/**
	 * The suite runner double.
	 *
	 * @var RegressionSuiteService&MockObject
	 */
	private RegressionSuiteService $suites;

	/**
	 * The recorder double.
	 *
	 * @var TraceCaseRecorder&MockObject
	 */
	private TraceCaseRecorder $recorder;

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->suites = $this->createMock(RegressionSuiteService::class);
		$this->recorder = $this->createMock(TraceCaseRecorder::class);
		$this->request = $this->createMock(IRequest::class);
	}

	/**
	 * The controller for a signed-in user the action matrix allows or refuses, or for nobody.
	 *
	 * @param bool|null $allowed Whether the action is allowed; null for no user.
	 * @param string    $action  The action the controller must ask about.
	 *
	 * @return RegressionSuiteController
	 */
	private function controller(?bool $allowed, string $action = 'regression-suite.run'): RegressionSuiteController {
		$session = $this->createMock(IUserSession::class);
		$actions = $this->createMock(ActionAuthServiceInterface::class);
		if ($allowed !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
			$actions->method('can')->with($user, $action)->willReturn($allowed);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new RegressionSuiteController(
			appName: 'integriq',
			request: $this->request,
			suites: $this->suites,
			actionAuth: $actions,
			userSession: $session,
			l10n: $l10n,
			recorder: $this->recorder
		);
	}//end controller()

	/**
	 * A user without the action gets 403 and no run is recorded.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheActionIsRefused(): void {
		$this->suites->expects($this->never())->method('run');

		$response = $this->controller(allowed: false)->run(subjectType: 'mapping', subjectId: 'lowercase-keys');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'You are not allowed to run test cases.'], actual: $response->getData());
	}//end testAUserWithoutTheActionIsRefused()

	/**
	 * Without a signed-in user the answer is 401.
	 *
	 * @return void
	 */
	public function testNoUserIsUnauthorised(): void {
		$this->suites->expects($this->never())->method('run');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(allowed: null)->run(subjectType: 'mapping', subjectId: 'x')->getStatus());
	}//end testNoUserIsUnauthorised()

	/**
	 * An allowed user gets 200 with the run record.
	 *
	 * @return void
	 */
	public function testAnAllowedUserGetsTheRunRecord(): void {
		$run = ['id' => 'run-1', 'total' => 2, 'passed' => 2, 'failed' => 0];
		$this->suites->expects($this->once())->method('run')->with('mapping', 'lowercase-keys', 'alice')->willReturn($run);

		$response = $this->controller(allowed: true)->run(subjectType: 'mapping', subjectId: 'lowercase-keys');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $run, actual: $response->getData());
	}//end testAnAllowedUserGetsTheRunRecord()

	/**
	 * An unknown subject type answers 400.
	 *
	 * @return void
	 */
	public function testAnUnknownSubjectTypeIsABadRequest(): void {
		$this->suites->method('run')->willThrowException(new InvalidArgumentException('Unknown subject type rule.'));

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller(allowed: true)->run(subjectType: 'rule', subjectId: 'x')->getStatus());
	}//end testAnUnknownSubjectTypeIsABadRequest()

	/**
	 * A user without the record action gets 403 and no case is saved.
	 *
	 * @return void
	 */
	public function testRecordingWithoutTheActionIsRefused(): void {
		$this->recorder->expects($this->never())->method('record');

		$response = $this->controller(allowed: false, action: 'regression-suite.record')->record(id: 'trace-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'You are not allowed to save test cases.'], actual: $response->getData());
	}//end testRecordingWithoutTheActionIsRefused()

	/**
	 * An allowed user saves the named step with the edits the body carries.
	 *
	 * @return void
	 */
	public function testAnAllowedUserRecordsTheStepWithTheEdits(): void {
		$params = ['step' => '2', 'name' => 'Ada', 'input' => ['authorization' => 'Bearer test']];
		$this->request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
		$case = ['id' => 'case-1', 'redactedPaths' => []];
		$this->recorder->expects($this->once())->method('record')
			->with('trace-1', 2, ['name' => 'Ada', 'input' => ['authorization' => 'Bearer test']])
			->willReturn($case);

		$response = $this->controller(allowed: true, action: 'regression-suite.record')->record(id: 'trace-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $case, actual: $response->getData());
	}//end testAnAllowedUserRecordsTheStepWithTheEdits()

	/**
	 * Without a step the answer is 400 and nothing is recorded.
	 *
	 * @return void
	 */
	public function testRecordingWithoutAStepIsABadRequest(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->recorder->expects($this->never())->method('record');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller(allowed: true, action: 'regression-suite.record')->record(id: 'trace-1')->getStatus());
	}//end testRecordingWithoutAStepIsABadRequest()

	/**
	 * A missing trace or step answers 404; a step that is not recordable answers 400.
	 *
	 * @return void
	 */
	public function testRecorderRefusalsBecomeHttpAnswers(): void {
		$this->request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'step' ? 1 : null);
		$this->recorder->method('record')->willReturnOnConsecutiveCalls(
			$this->throwException(new OutOfBoundsException('The trace has no step 1.')),
			$this->throwException(new InvalidArgumentException('not from a rule step'))
		);
		$controller = $this->controller(allowed: true, action: 'regression-suite.record');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->record(id: 'trace-1')->getStatus());
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $controller->record(id: 'trace-1')->getStatus());
	}//end testRecorderRefusalsBecomeHttpAnswers()
}//end class
