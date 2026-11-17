<?php

/**
 * Integriq — the job schedule preview endpoint.
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
 *
 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-job-form-previews-the-next-five-run-times-req-jcal-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\JobsController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\JobCalendarSchedule;
use OCA\Integriq\Service\JobServiceInterface;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The preview answers five run times, for those allowed to ask.
 */
#[CoversClass(JobsController::class)]
#[UsesClass(JobCalendarSchedule::class)]
class JobsSchedulePreviewTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The action authorization.
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
		$this->session    = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$l10n             = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->controller = new JobsController(
			'integriq',
			$this->request,
			$this->createMock(OrObjectService::class),
			$this->createMock(JobServiceInterface::class),
			$l10n,
			$this->session,
			$this->actionAuth,
			new JobCalendarSchedule()
		);
	}//end setUp()

	/**
	 * Sign a user in.
	 *
	 * @return IUser The user.
	 */
	private function signIn(): IUser {
		$user = $this->createMock(IUser::class);
		$this->session->method('getUser')->willReturn($user);
		return $user;
	}//end signIn()

	/**
	 * Serve a request parameter.
	 *
	 * @param mixed $schedule The `schedule` parameter.
	 *
	 * @return void
	 */
	private function asking(mixed $schedule): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'schedule' ? $schedule : $default
		);
	}//end asking()

	/**
	 * Five run times, ascending, all in the future, from the calendar.
	 *
	 * @return void
	 */
	public function testAnAllowedUserGetsFiveRunTimes(): void {
		$user = $this->signIn();
		$this->actionAuth->expects($this->once())->method('requireAction')->with($user, 'job.schedule-preview');
		$this->asking(['type' => 'calendar', 'weekdays' => [1, 2, 3, 4, 5], 'times' => ['07:00'], 'timeZone' => 'Europe/Amsterdam']);

		$response = $this->controller->schedulePreview();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$runs = $response->getData()['runs'];
		$this->assertCount(5, $runs);
		$previous = time();
		foreach ($runs as $run) {
			$moment = new \DateTimeImmutable($run);
			$this->assertGreaterThan($previous, $moment->getTimestamp());
			$this->assertSame('07:00', $moment->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('H:i'));
			$this->assertLessThanOrEqual(5, (int)$moment->format('N'));
			$previous = $moment->getTimestamp();
		}
	}//end testAnAllowedUserGetsFiveRunTimes()

	/**
	 * A user without job.schedule-preview is refused with 403 before the calendar is read.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheActionIsForbidden(): void {
		$user = $this->signIn();
		$this->actionAuth->expects($this->once())->method('requireAction')->with($user, 'job.schedule-preview')
			->willThrowException(new OCSForbiddenException("Action 'job.schedule-preview' requires admin rights"));
		$this->request->expects($this->never())->method('getParam');

		try {
			$this->controller->schedulePreview();
			$this->fail('The preview answered a user without the action.');
		} catch (OCSForbiddenException $e) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $e->getCode());
		}
	}//end testAUserWithoutTheActionIsForbidden()

	/**
	 * Without a signed-in user the preview is unauthorised and no action is checked.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorised(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->actionAuth->expects($this->never())->method('requireAction');

		$response = $this->controller->schedulePreview();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testAnonymousIsUnauthorised()

	/**
	 * A missing calendar is a bad request.
	 *
	 * @return void
	 */
	public function testAMissingCalendarIsABadRequest(): void {
		$this->signIn();
		$this->asking(null);

		$response = $this->controller->schedulePreview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A calendar schedule is required.'], $response->getData());
	}//end testAMissingCalendarIsABadRequest()

	/**
	 * A malformed calendar is a bad request that names the field.
	 *
	 * @return void
	 */
	public function testAMalformedCalendarNamesTheField(): void {
		$this->signIn();
		$this->asking(['type' => 'calendar', 'weekdays' => [1], 'times' => ['07:00'], 'timeZone' => 'Europe/Amsterdam', 'dayOfMonth' => 'secondTuesday']);

		$response = $this->controller->schedulePreview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringStartsWith('dayOfMonth:', $response->getData()['error']);
	}//end testAMalformedCalendarNamesTheField()
}//end class
