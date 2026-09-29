<?php

/**
 * Unit tests for RunSummaryController: GET /api/sources/{id}/run-summary.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\RunSummaryController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\RunSummaryService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The route answers per-day rows, refuses a long window with 400 and requires source.logs.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 */
final class RunSummaryControllerTest extends TestCase {

	/**
	 * Build the controller for one request.
	 *
	 * @param array<string, string> $params The query parameters.
	 * @param ActionAuthService $actionAuth The action guard.
	 *
	 * @return RunSummaryController
	 */
	private function makeController(array $params, ActionAuthService $actionAuth): RunSummaryController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $args = []): string => vsprintf($text, $args)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn([]);

		return new RunSummaryController(
			$request,
			new RunSummaryService($objects),
			$session,
			$actionAuth,
			$l
		);
	}//end makeController()

	/**
	 * A week's window answers seven rows.
	 *
	 * @return void
	 */
	public function testAWeekAnswersSevenRows(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())->method('requireAction')->with($this->anything(), 'source.logs');

		$response = $this->makeController(['from' => '2026-09-22', 'to' => '2026-09-28'], $actionAuth)->show('source-kvk');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(7, $response->getData()['days']);
		$this->assertSame('source-kvk', $response->getData()['sourceId']);
	}//end testAWeekAnswersSevenRows()

	/**
	 * January to June is refused with 400 naming the 31-day limit.
	 *
	 * @return void
	 */
	public function testJanuaryToJuneIsRefusedNamingTheLimit(): void {
		$response = $this->makeController(['from' => '2026-01-01', 'to' => '2026-06-30'], $this->createMock(ActionAuthService::class))->show('source-kvk');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('31', $response->getData()['error']);
	}//end testJanuaryToJuneIsRefusedNamingTheLimit()

	/**
	 * A date that is not a date is refused with 400.
	 *
	 * @return void
	 */
	public function testANonDateIsRefused(): void {
		$response = $this->makeController(['from' => 'last tuesday; drop', 'to' => '2026-06-30'], $this->createMock(ActionAuthService::class))->show('source-kvk');

		$this->assertSame(400, $response->getStatus());
	}//end testANonDateIsRefused()

	/**
	 * Without source.logs the guard refuses before anything is read.
	 *
	 * @return void
	 */
	public function testWithoutSourceLogsTheReadIsRefused(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('no'));

		$this->expectException(OCSForbiddenException::class);
		$this->makeController([], $actionAuth)->show('source-kvk');
	}//end testWithoutSourceLogsTheReadIsRefused()
}//end class
