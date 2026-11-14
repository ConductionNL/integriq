<?php

/**
 * Integriq — OpenTelemetry settings controller tests.
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

use InvalidArgumentException;
use OCA\Integriq\Controller\OtelSettingsController;
use OCA\Integriq\Observability\Otel\OtelSettingsInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The OpenTelemetry settings read and write, with their defaults and refusal.
 *
 * @covers \OCA\Integriq\Controller\OtelSettingsController
 */
class OtelSettingsControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked settings.
	 *
	 * @var OtelSettingsInterface&MockObject
	 */
	private OtelSettingsInterface $settings;

	/**
	 * The controller under test.
	 *
	 * @var OtelSettingsController
	 */
	private OtelSettingsController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request    = $this->createMock(IRequest::class);
		$this->settings   = $this->createMock(OtelSettingsInterface::class);
		$this->controller = new OtelSettingsController(request: $this->request, settings: $this->settings);
	}//end setUp()

	/**
	 * The read answers with every stored setting.
	 *
	 * @return void
	 */
	public function testGetConfigReturnsTheStoredSettings(): void {
		$this->settings->method('all')->willReturn(['enabled' => true, 'endpoint' => 'https://otel.example']);

		$response = $this->controller->getConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['enabled' => true, 'endpoint' => 'https://otel.example'], $response->getData());
	}//end testGetConfigReturnsTheStoredSettings()

	/**
	 * Without parameters every field is saved with its default.
	 *
	 * @return void
	 */
	public function testSetConfigSavesDefaultsForMissingFields(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return $default;
			}
		);
		$this->settings->expects($this->once())->method('save')->with(
			[
				'enabled'        => false,
				'endpoint'       => '',
				'allowLocal'     => false,
				'samplingRatio'  => 0.1,
				'serviceName'    => '',
				'headerName'     => '',
				'credentialName' => '',
			]
		)->willReturn(['enabled' => false]);

		$response = $this->controller->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['enabled' => false], $response->getData());
	}//end testSetConfigSavesDefaultsForMissingFields()

	/**
	 * Posted fields are passed through and the stored result is returned.
	 *
	 * @return void
	 */
	public function testSetConfigPassesPostedFields(): void {
		$posted = [
			'enabled'        => true,
			'endpoint'       => 'https://otel.example/v1/traces',
			'allowLocal'     => true,
			'samplingRatio'  => 0.5,
			'serviceName'    => 'integriq-prod',
			'headerName'     => 'X-Api-Key',
			'credentialName' => 'otel',
			'ignored'        => 'x',
		];
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($posted): mixed {
				return ($posted[$key] ?? $default);
			}
		);
		$expected = $posted;
		unset($expected['ignored']);
		$this->settings->expects($this->once())->method('save')->with($expected)->willReturn(['stored' => true]);

		$this->assertSame(['stored' => true], $this->controller->setConfig()->getData());
	}//end testSetConfigPassesPostedFields()

	/**
	 * A value the settings refuse is a bad request carrying the reason.
	 *
	 * @return void
	 */
	public function testSetConfigRefusesAnInvalidValue(): void {
		$this->request->method('getParam')->willReturnArgument(1);
		$this->settings->method('save')->willThrowException(new InvalidArgumentException('The endpoint must be https.'));

		$response = $this->controller->setConfig();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'The endpoint must be https.'], $response->getData());
	}//end testSetConfigRefusesAnInvalidValue()
}//end class
