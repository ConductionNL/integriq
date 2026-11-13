<?php

/**
 * Integriq Metrics Controller — OpenRegister dependency guard tests.
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
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MetricsController;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

// The engine controller lives in the peer OpenRegister app, which is not in vendor.
if (class_exists(GenericMetricsController::class) === false) {
	require_once __DIR__ . '/../../stubs/OCA/OpenRegister/AppHost/Controller/GenericMetricsController.php';
}

/**
 * The metrics endpoint answers 503 without OpenRegister and delegates with it.
 *
 * @covers \OCA\Integriq\Controller\MetricsController
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class MetricsControllerTest extends TestCase {
	/**
	 * The controller over an app manager that reports OpenRegister as given.
	 *
	 * @param bool $enabled Whether OpenRegister is enabled.
	 * @param GenericMetricsController|null $delegate The engine controller.
	 *
	 * @return MetricsController
	 */
	private function controller(bool $enabled, ?GenericMetricsController $delegate): MetricsController {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('isEnabledForAnyone')->with('openregister')->willReturn($enabled);

		return new MetricsController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			appManager: $appManager,
			delegate: $delegate
		);
	}//end controller()

	/**
	 * Assert the response is the Prometheus 503 naming the missing dependency.
	 *
	 * @param TextPlainResponse $response The response.
	 *
	 * @return void
	 */
	private function assertUnavailable(TextPlainResponse $response): void {
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(
			'# metrics unavailable: Integriq requires the OpenRegister app — install and enable it.' . "\n",
			$response->render()
		);
		// Response::getHeaders() reaches into the server container for its
		// defaults, so read the headers the controller set directly.
		$headers = (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
		$this->assertSame('text/plain; version=0.0.4; charset=utf-8', $headers['Content-Type']);
	}//end assertUnavailable()

	/**
	 * OpenRegister disabled: 503 with a Prometheus comment, and the engine is never asked.
	 *
	 * @return void
	 */
	public function testMetricsReport503WhenOpenRegisterIsDisabled(): void {
		$delegate = $this->createMock(GenericMetricsController::class);
		$delegate->expects($this->never())->method('index');

		$this->assertUnavailable($this->controller(false, $delegate)->index());
	}//end testMetricsReport503WhenOpenRegisterIsDisabled()

	/**
	 * OpenRegister enabled but no engine controller wired: still 503, never a 500.
	 *
	 * @return void
	 */
	public function testMetricsReport503WhenTheEngineIsMissing(): void {
		$this->assertUnavailable($this->controller(true, null)->index());
	}//end testMetricsReport503WhenTheEngineIsMissing()

	/**
	 * OpenRegister enabled with the engine wired: its response is answered unchanged.
	 *
	 * @return void
	 */
	public function testMetricsDelegateToTheEngine(): void {
		$engineResponse = new TextPlainResponse("integriq_up 1\n");
		$delegate = $this->createMock(GenericMetricsController::class);
		$delegate->expects($this->once())->method('index')->willReturn($engineResponse);

		$this->assertSame($engineResponse, $this->controller(true, $delegate)->index());
	}//end testMetricsDelegateToTheEngine()
}//end class
