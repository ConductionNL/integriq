<?php

/**
 * Integriq — connection monitor controller tests.
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

use DateTimeImmutable;
use OCA\Integriq\Controller\ConnectionMonitorController;
use OCA\Integriq\Service\ConnectionMonitorServiceInterface;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The monitor report for one named source, and the two refusals.
 *
 * @covers \OCA\Integriq\Controller\ConnectionMonitorController
 */
class ConnectionMonitorControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked monitor.
	 *
	 * @var ConnectionMonitorServiceInterface&MockObject
	 */
	private ConnectionMonitorServiceInterface $monitor;

	/**
	 * The controller under test.
	 *
	 * @var ConnectionMonitorController
	 */
	private ConnectionMonitorController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->monitor = $this->createMock(ConnectionMonitorServiceInterface::class);
		$l10n          = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text): string {
				return 'nl:' . $text;
			}
		);
		$this->controller = new ConnectionMonitorController(
			appName: 'integriq',
			request: $this->request,
			monitor: $this->monitor,
			l: $l10n
		);
	}//end setUp()

	/**
	 * A blank source is a bad request and the monitor is not asked.
	 *
	 * @return void
	 */
	public function testBlankSourceIsABadRequest(): void {
		$this->request->method('getParam')->with('source', '')->willReturn('   ');
		$this->monitor->expects($this->never())->method('report');

		$response = $this->controller->show();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'nl:Name the source to monitor.'], $response->getData());
	}//end testBlankSourceIsABadRequest()

	/**
	 * An unknown source is not found.
	 *
	 * @return void
	 */
	public function testUnknownSourceIsNotFound(): void {
		$this->request->method('getParam')->willReturn('ghost');
		$this->monitor->method('report')->willReturn(null);

		$response = $this->controller->show();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'nl:There is no such source.'], $response->getData());
	}//end testUnknownSourceIsNotFound()

	/**
	 * A known source is reported by its trimmed reference, as of now.
	 *
	 * @return void
	 */
	public function testKnownSourceIsReported(): void {
		$this->request->method('getParam')->willReturn('  src-9 ');
		$before = new DateTimeImmutable();
		$this->monitor->expects($this->once())->method('report')->with(
			'src-9',
			$this->callback(
				static function (DateTimeImmutable $now) use ($before): bool {
					return $now >= $before && $now <= new DateTimeImmutable();
				}
			)
		)->willReturn(['status' => 'healthy']);

		$response = $this->controller->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'healthy'], $response->getData());
	}//end testKnownSourceIsReported()
}//end class
