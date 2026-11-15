<?php

/**
 * Integriq — CTI controller tests.
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

use OCA\Integriq\Controller\CtiController;
use OCA\Integriq\Service\Kiss\CtiEventIntakeInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The public call-event endpoint refuses unknown sources and bad signatures before reading the body.
 *
 * Under the CLI test runner php://input is empty, so a verified request carries an empty body.
 *
 * @covers \OCA\Integriq\Controller\CtiController
 */
class CtiControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked intake.
	 *
	 * @var CtiEventIntakeInterface&MockObject
	 */
	private CtiEventIntakeInterface $intake;

	/**
	 * The controller under test.
	 *
	 * @var CtiController
	 */
	private CtiController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request    = $this->createMock(IRequest::class);
		$this->intake     = $this->createMock(CtiEventIntakeInterface::class);
		$this->controller = new CtiController(
			appName: 'integriq',
			request: $this->request,
			intake: $this->intake,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * An unknown source is unauthorised, and nothing else is consulted.
	 *
	 * @return void
	 */
	public function testUnknownSourceIsUnauthorised(): void {
		$this->intake->expects($this->once())->method('findSource')->with('src-1')->willReturn(null);
		$this->intake->expects($this->never())->method('verify');
		$this->intake->expects($this->never())->method('handle');

		$response = $this->controller->events(sourceId: 'src-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unauthorized'], $response->getData());
	}//end testUnknownSourceIsUnauthorised()

	/**
	 * A request that fails verification is unauthorised and never handled.
	 *
	 * @return void
	 */
	public function testFailedVerificationIsUnauthorised(): void {
		$source = ['id' => 'src-1', 'secret' => 's'];
		$this->intake->method('findSource')->willReturn($source);
		$this->intake->expects($this->once())->method('headersFrom')->with($this->request)->willReturn(['x-sig' => 'abc']);
		$this->intake->expects($this->once())->method('verify')->with($source, ['x-sig' => 'abc'], '')->willReturn(false);
		$this->intake->expects($this->never())->method('handle');

		$response = $this->controller->events(sourceId: 'src-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unauthorized'], $response->getData());
	}//end testFailedVerificationIsUnauthorised()

	/**
	 * A verified request whose body is not a JSON object is a bad request and never handled.
	 *
	 * @return void
	 */
	public function testVerifiedButMalformedBodyIsABadRequest(): void {
		$this->intake->method('findSource')->willReturn(['id' => 'src-1']);
		$this->intake->method('headersFrom')->willReturn([]);
		$this->intake->method('verify')->willReturn(true);
		$this->intake->expects($this->never())->method('handle');

		$response = $this->controller->events(sourceId: 'src-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'malformed_body'], $response->getData());
	}//end testVerifiedButMalformedBodyIsABadRequest()
}//end class
