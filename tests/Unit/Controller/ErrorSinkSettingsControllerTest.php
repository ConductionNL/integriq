<?php

/**
 * Unit tests for the error sink settings endpoint (REQ-OTEL-008).
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
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-the-error-sink-is-configured-on-the-admin-page-req-otel-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\ErrorSinkSettingsController;
use OCA\Integriq\Service\SettingsServiceInterface;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The error sink settings read and write, with their defaults and refusal.
 */
class ErrorSinkSettingsControllerTest extends TestCase {

	/**
	 * The read answers with the stored settings.
	 *
	 * @return void
	 */
	public function testGetConfigReturnsTheStoredSettings(): void {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getErrorSinkSettings')->willReturn(['enabled' => true, 'kind' => 'sentry']);
		$controller = new ErrorSinkSettingsController(request: $this->createMock(originalClassName: IRequest::class), settings: $settings);

		$response = $controller->getConfig();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['enabled' => true, 'kind' => 'sentry'], actual: $response->getData());

	}//end testGetConfigReturnsTheStoredSettings()

	/**
	 * The write passes every field, with its default, and answers what was stored.
	 *
	 * @return void
	 */
	public function testSetConfigPassesEveryFieldWithItsDefault(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default) {
				if ($key === 'endpoint') {
					return 'https://key@sentry.example.org/42';
				}

				return $default;
			}
		);
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->expects($this->once())->method('saveErrorSinkSettings')->with(
			[
				'enabled' => false,
				'kind' => 'sentry',
				'endpoint' => 'https://key@sentry.example.org/42',
				'allowLocal' => false,
				'minLevel' => 3,
				'apps' => ['integriq', 'openregister', 'opencatalogi'],
				'headerName' => '',
				'credentialName' => '',
			]
		)->willReturn(['stored' => true]);
		$controller = new ErrorSinkSettingsController(request: $request, settings: $settings);

		$response = $controller->setConfig();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['stored' => true], actual: $response->getData());

	}//end testSetConfigPassesEveryFieldWithItsDefault()

	/**
	 * A refused value answers 400 with the reason.
	 *
	 * @return void
	 */
	public function testARefusedValueAnswersBadRequest(): void {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('saveErrorSinkSettings')->willThrowException(new InvalidArgumentException('The error service needs an endpoint or DSN.'));
		$controller = new ErrorSinkSettingsController(request: $this->createMock(originalClassName: IRequest::class), settings: $settings);

		$response = $controller->setConfig();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'The error service needs an endpoint or DSN.'], actual: $response->getData());

	}//end testARefusedValueAnswersBadRequest()

	/**
	 * Both actions are for administrators of the integriq settings only.
	 *
	 * @return void
	 */
	public function testBothActionsNeedTheAdminSetting(): void {
		foreach (['getConfig', 'setConfig'] as $method) {
			$attributes = (new ReflectionMethod(ErrorSinkSettingsController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(expectedCount: 1, haystack: $attributes, message: $method);
			$this->assertSame(expected: [IntegriqAdmin::class], actual: $attributes[0]->getArguments(), message: $method);
		}

	}//end testBothActionsNeedTheAdminSetting()
}//end class
