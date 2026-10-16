<?php

/**
 * Unit tests for CallLogController's call detail.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\CallLogController;
use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Outbound\Call\CallReplayService;
use OCA\Integriq\Service\ActionAuthService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The replay request is readable only with the replay permission, inside a window.
 */
class CallLogControllerTest extends TestCase {

	/**
	 * Build the controller over one stored call.
	 *
	 * @param array<string,mixed> $call The stored call.
	 * @param bool $mayReplay Whether the principal holds the replay permission.
	 *
	 * @return CallLogController
	 */
	private function controller(array $call, bool $mayReplay): CallLogController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('lezer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('can')->willReturnCallback(
			static fn (IUser $who, string $action): bool => ($action === CallLogController::ACTION_REPLAY && $mayReplay)
		);

		$recorder = $this->createMock(CallRecorder::class);
		$recorder->method('read')->willReturn($call);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CallLogController(
			'integriq',
			$this->createMock(IRequest::class),
			$session,
			$auth,
			$recorder,
			$this->createMock(CallReplayService::class),
			$l10n
		);
	}//end controller()

	/**
	 * A failed call kept outside a window.
	 *
	 * @param bool $captured Whether it was captured inside a window.
	 *
	 * @return array<string,mixed>
	 */
	private function call(bool $captured): array {
		return [
			'statusCode' => 500,
			'request' => ['method' => 'POST'],
			'response' => ['statusCode' => 500],
			'bodyCaptured' => $captured,
			'replayRequest' => ['method' => 'POST', 'json' => ['bsn' => '999993653']],
		];
	}//end call()

	/**
	 * A reader without the replay permission never sees the replay request.
	 *
	 * @return void
	 */
	public function testTheReplayRequestIsHiddenFromAReader(): void {
		$data = $this->controller($this->call(true), false)->show('call-1')->getData();

		$this->assertArrayNotHasKey('replayRequest', $data['call']);
		$this->assertSame(500, $data['call']['statusCode']);
	}//end testTheReplayRequestIsHiddenFromAReader()

	/**
	 * Outside a window the replay request stays hidden, even with the replay permission.
	 *
	 * @return void
	 */
	public function testTheReplayRequestIsHiddenOutsideAWindow(): void {
		$data = $this->controller($this->call(false), true)->show('call-1')->getData();

		$this->assertArrayNotHasKey('replayRequest', $data['call']);
	}//end testTheReplayRequestIsHiddenOutsideAWindow()

	/**
	 * With the replay permission, a captured call shows its replay request.
	 *
	 * @return void
	 */
	public function testAReplayerSeesTheReplayRequestOfACapturedCall(): void {
		$data = $this->controller($this->call(true), true)->show('call-1')->getData();

		$this->assertSame('999993653', $data['call']['replayRequest']['json']['bsn']);
	}//end testAReplayerSeesTheReplayRequestOfACapturedCall()
}//end class
