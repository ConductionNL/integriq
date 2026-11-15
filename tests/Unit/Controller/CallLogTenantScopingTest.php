<?php

/**
 * Cross-tenant refusal tests for the outbound call log: a user reads, previews
 * and replays only their own organisation's calls.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\CallLogController;
use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Outbound\Call\CallReplayServiceInterface;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ForeignOrganisation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Another organisation's call answers as an unknown one, and nothing replays.
 *
 * @covers \OCA\Integriq\Outbound\Call\CallRecorder
 * @covers \OCA\Integriq\Controller\CallLogController
 * @uses   \OCA\Integriq\Outbound\BodyRedactor
 * @uses   \OCA\Integriq\Outbound\Call\BodyCapturePolicy
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class CallLogTenantScopingTest extends TestCase {

	/**
	 * The replay service double.
	 *
	 * @var CallReplayServiceInterface&MockObject
	 */
	private CallReplayServiceInterface $replay;

	/**
	 * The recorder over a store holding one call of another organisation.
	 *
	 * @var CallRecorder
	 */
	private CallRecorder $recorder;

	/**
	 * Build the recorder over another organisation's call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$call = new ObjectEntity();
		$call->setUuid('call-theirs');
		$call->setObject(['target' => 'https://partner.example', 'statusCode' => 500, 'attempts' => [['statusCode' => 500]]]);
		$call->setOrganisation('other-organisation');

		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(ForeignOrganisation::find($call));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$this->recorder = new CallRecorder($objects, new BodyRedactor(), new BodyCapturePolicy($appConfig), new SensitiveFieldRegistry());
		$this->replay   = $this->createMock(CallReplayServiceInterface::class);
	}//end setUp()

	/**
	 * The controller for one request by a user allowed every call-log action.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return CallLogController
	 */
	private function controller(array $params = []): CallLogController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$auth = $this->createMock(ActionAuthServiceInterface::class);
		$auth->method('can')->willReturn(true);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new CallLogController('integriq', $request, $session, $auth, $this->recorder, $this->replay, $l);
	}//end controller()

	/**
	 * The user read keeps the organisation filter on; the system read does not.
	 *
	 * @return void
	 */
	public function testOnlyTheSystemReadReachesAnotherOrganisationsCall(): void {
		$this->assertSame(500, $this->recorder->read(uuid: 'call-theirs')['statusCode']);

		$this->expectException(DoesNotExistException::class);
		$this->recorder->readInOrganisation(uuid: 'call-theirs');
	}//end testOnlyTheSystemReadReachesAnotherOrganisationsCall()

	/**
	 * Showing another organisation's call answers 404.
	 *
	 * @return void
	 */
	public function testShowingAnotherOrganisationsCallIs404(): void {
		$response = $this->controller()->show('call-theirs');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such call.'], $response->getData());
	}//end testShowingAnotherOrganisationsCallIs404()

	/**
	 * Previewing a replay of another organisation's call answers 404 and previews nothing.
	 *
	 * @return void
	 */
	public function testPreviewingAnotherOrganisationsCallIs404(): void {
		$this->replay->expects($this->never())->method('preview');

		$response = $this->controller()->preview('call-theirs');

		$this->assertSame(404, $response->getStatus());
	}//end testPreviewingAnotherOrganisationsCallIs404()

	/**
	 * A bulk replay naming another organisation's call answers 404 and replays nothing.
	 *
	 * @return void
	 */
	public function testReplayingAnotherOrganisationsCallIs404AndNothingRuns(): void {
		$this->replay->expects($this->never())->method('replayAll');

		$response = $this->controller(['calls' => ['call-theirs']])->replay();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such call.'], $response->getData());
	}//end testReplayingAnotherOrganisationsCallIs404AndNothingRuns()

}//end class
