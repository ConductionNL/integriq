<?php

/**
 * Integriq — settings controller tests.
 *
 * The retention read, its update and the rebase: each answer, each refusal,
 * and that every route is for administrators only.
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
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\SettingsController;
use OCA\Integriq\Service\SettingsServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The settings routes: read, update, rebase, and who may call them.
 *
 * @covers \OCA\Integriq\Controller\SettingsController
 */
class SettingsControllerTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface $service;

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var SettingsController
	 */
	private SettingsController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(SettingsServiceInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$l10n          = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text): string {
				return 'nl:' . $text;
			}
		);

		$this->controller = new SettingsController(
			appName: 'integriq',
			request: $this->request,
			settingsService: $this->service,
			logger: $this->logger,
			l: $l10n
		);
	}//end setUp()

	/**
	 * Every route is gated on integriq's admin setting, so Nextcloud answers
	 * 403 to a user who is not an administrator before the method runs.
	 *
	 * @return void
	 */
	public function testEveryRouteIsForAdministratorsOnly(): void {
		foreach (['index', 'update', 'rebase'] as $method) {
			$attributes = (new ReflectionMethod(SettingsController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method);
			$this->assertSame([IntegriqAdmin::class], $attributes[0]->getArguments(), $method);
		}
	}//end testEveryRouteIsForAdministratorsOnly()

	/**
	 * The read answers what the service reports.
	 *
	 * @return void
	 */
	public function testIndexAnswersTheSettings(): void {
		$settings = ['retention' => ['callLogRetention' => 7776000000], 'defaults' => [], 'rebase' => []];
		$this->service->method('getSettings')->willReturn($settings);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($settings, $response->getData());
	}//end testIndexAnswersTheSettings()

	/**
	 * An update hands the sent retention object on and answers the settings after it.
	 *
	 * @return void
	 */
	public function testUpdateHandsTheRetentionOn(): void {
		$this->request->method('getParam')->with('retention')->willReturn(['callLogRetention' => 7776000000]);
		$this->service->expects($this->once())
			->method('updateSettings')
			->with(['retention' => ['callLogRetention' => 7776000000]])
			->willReturn(['retention' => ['callLogRetention' => 7776000000]]);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['retention' => ['callLogRetention' => 7776000000]], $response->getData());
	}//end testUpdateHandsTheRetentionOn()

	/**
	 * A refused value is a 400 that names the field.
	 *
	 * @return void
	 */
	public function testARefusedUpdateIsABadRequestNamingTheField(): void {
		$this->request->method('getParam')->willReturn(['callLogRetention' => -1]);
		$this->service->method('updateSettings')
			->willThrowException(new InvalidArgumentException('callLogRetention: must be a whole number of milliseconds, 0 or more.'));

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'callLogRetention: must be a whole number of milliseconds, 0 or more.'], $response->getData());
	}//end testARefusedUpdateIsABadRequestNamingTheField()

	/**
	 * A rebase passes the chosen kinds on and answers 202: it is queued, not done.
	 *
	 * @return void
	 */
	public function testRebaseQueuesTheChosenKinds(): void {
		$this->request->method('getParam')->with('kinds', [])->willReturn(['callLog']);
		$this->service->expects($this->once())
			->method('rebase')
			->with(['callLog'])
			->willReturn(['queued' => true, 'kinds' => ['callLog']]);

		$response = $this->controller->rebase();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['queued' => true, 'kinds' => ['callLog']], $response->getData());
	}//end testRebaseQueuesTheChosenKinds()

	/**
	 * One kind sent as a string is one kind, and an unknown kind is a 400.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsABadRequest(): void {
		$this->request->method('getParam')->willReturn('eventMessage');
		$this->service->expects($this->once())
			->method('rebase')
			->with(['eventMessage'])
			->willThrowException(new InvalidArgumentException('kinds: "eventMessage" is not a log kind that can be rebased.'));

		$response = $this->controller->rebase();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'kinds: "eventMessage" is not a log kind that can be rebased.'], $response->getData());
	}//end testAnUnknownKindIsABadRequest()

	/**
	 * A rebase that cannot be queued is a translated 500, and the cause is logged, not answered.
	 *
	 * @return void
	 */
	public function testARebaseThatCannotBeQueuedIsAServerError(): void {
		$this->request->method('getParam')->willReturn([]);
		$this->service->method('rebase')->willThrowException(new RuntimeException('job list down'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Failed to queue the retention rebase', ['exception' => 'job list down']);

		$response = $this->controller->rebase();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'nl:Failed to perform rebase operation'], $response->getData());
	}//end testARebaseThatCannotBeQueuedIsAServerError()
}//end class
