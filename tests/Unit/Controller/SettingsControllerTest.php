<?php

/**
 * Integriq — settings controller tests.
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

use OCA\Integriq\Controller\SettingsController;
use OCA\Integriq\Service\SettingsServiceInterface;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The rebase endpoint: its answer, its logging and its failure.
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
		$this->logger  = $this->createMock(LoggerInterface::class);
		$l10n          = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text): string {
				return 'nl:' . $text;
			}
		);
		$this->controller = new SettingsController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			settingsService: $this->service,
			logger: $this->logger,
			l: $l10n
		);
	}//end setUp()

	/**
	 * A rebase answers with the service result and logs its summary.
	 *
	 * @return void
	 */
	public function testRebaseReturnsTheResultAndLogsASummary(): void {
		$result = ['success' => true, 'duration' => '2s', 'errors' => ['a', 'b']];
		$this->service->expects($this->once())->method('rebase')->willReturn($result);
		$logged = [];
		$this->logger->expects($this->exactly(2))->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller->rebase();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
		$this->assertSame(
			[
				['Rebase endpoint called', ['endpoint' => '/api/settings/rebase']],
				['Rebase operation completed', ['success' => true, 'duration' => '2s', 'errors' => 2]],
			],
			$logged
		);
	}//end testRebaseReturnsTheResultAndLogsASummary()

	/**
	 * A result without summary keys is logged with the defaults.
	 *
	 * @return void
	 */
	public function testRebaseLogsDefaultsForASparseResult(): void {
		$this->service->method('rebase')->willReturn([]);
		$logged = [];
		$this->logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $context;
			}
		);

		$this->assertSame([], $this->controller->rebase()->getData());
		$this->assertSame(['success' => false, 'duration' => 'unknown', 'errors' => 0], $logged[1]);
	}//end testRebaseLogsDefaultsForASparseResult()

	/**
	 * A failing rebase is a 500 with a translated error and the message, and is logged as an error.
	 *
	 * @return void
	 */
	public function testRebaseFailureIsAServerError(): void {
		$this->service->method('rebase')->willThrowException(new RuntimeException('register missing'));
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to perform rebase operation',
			['exception' => 'register missing']
		);

		$response = $this->controller->rebase();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(
			['error' => 'nl:Failed to perform rebase operation', 'message' => 'register missing'],
			$response->getData()
		);
	}//end testRebaseFailureIsAServerError()
}//end class
