<?php

/**
 * Unit tests for Run again: SynchronizationsController::run() with triggeredBy rerun.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-failed-pull-restarts-with-one-click-req-crun-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SynchronizationsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\SynchronizationRunProgressService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Run again starts the same synchronization, records `rerun`, and answers with the new run's id.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-failed-pull-restarts-with-one-click-req-crun-003
 */
final class SynchronizationsControllerRerunTest extends TestCase {

	/**
	 * Build the controller for one request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param SynchronizationService $service The synchronization service.
	 * @param SynchronizationRunProgressService $progress The run progress service.
	 *
	 * @return SynchronizationsController
	 */
	private function makeController(array $params, SynchronizationService $service, SynchronizationRunProgressService $progress): SynchronizationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objects = $this->createMock(OrObjectService::class);
		$objects->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'KVK pull', 'sourceId' => 'source-kvk'], 'sync-1')
		);

		return new SynchronizationsController(
			'integriq',
			$request,
			$objects,
			$service,
			$l,
			$this->createMock(LoggerInterface::class),
			$session,
			$this->createMock(ActionAuthService::class),
			$progress
		);
	}//end makeController()

	/**
	 * Run again passes `rerun` to the engine and answers with the new run's id.
	 *
	 * @return void
	 */
	public function testRunAgainRecordsRerunAndAnswersTheNewRunId(): void {
		$service = $this->createMock(SynchronizationService::class);
		$service->expects($this->once())
			->method('synchronize')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), 'rerun')
			->willReturn(['result' => ['objects' => ['found' => 3]]]);

		$progress = $this->createMock(SynchronizationRunProgressService::class);
		$progress->method('lastRunId')->willReturn('run-new');

		$response = $this->makeController(['triggeredBy' => 'rerun'], $service, $progress)->run('sync-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('run-new', $response->getData()['runId']);
	}//end testRunAgainRecordsRerunAndAnswersTheNewRunId()

	/**
	 * A run that fails again still names its run record.
	 *
	 * @return void
	 */
	public function testARunThatFailsAgainStillNamesItsRun(): void {
		$service = $this->createMock(SynchronizationService::class);
		$service->method('synchronize')->willThrowException(new \Exception('Source answered 500'));

		$progress = $this->createMock(SynchronizationRunProgressService::class);
		$progress->method('lastRunId')->willReturn('run-failed-again');

		$response = $this->makeController(['triggeredBy' => 'rerun'], $service, $progress)->run('sync-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('run-failed-again', $response->getData()['runId']);
	}//end testARunThatFailsAgainStillNamesItsRun()

	/**
	 * A plain Run now leaves the trigger to the engine, and a made-up trigger is not passed on.
	 *
	 * @return void
	 */
	public function testAnUnknownTriggerIsNotPassedOn(): void {
		$service = $this->createMock(SynchronizationService::class);
		$service->expects($this->once())
			->method('synchronize')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), null)
			->willReturn([]);

		$progress = $this->createMock(SynchronizationRunProgressService::class);

		$response = $this->makeController(['triggeredBy' => 'cron'], $service, $progress)->run('sync-1');

		$this->assertSame(200, $response->getStatus());
	}//end testAnUnknownTriggerIsNotPassedOn()
}//end class
