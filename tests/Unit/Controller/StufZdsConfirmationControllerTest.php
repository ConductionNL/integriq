<?php

/**
 * Unit tests for StufZdsConfirmationController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\StufZdsConfirmationController;
use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiver;
use OCA\Integriq\Service\StufZkn\StufZknRequestBody;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Controller\StufZdsConfirmationController
 */
class StufZdsConfirmationControllerTest extends TestCase {

	/**
	 * The receiver's status and body are what the case system gets, as XML.
	 *
	 * @return void
	 */
	public function testTheReceiversAnswerIsTheResponse(): void {
		$receiver = $this->createMock(StufZdsConfirmationReceiver::class);
		$receiver->expects($this->once())->method('receive')->with('<Bv01/>')->willReturn(['statusCode' => 422, 'body' => '<Fo03/>']);

		$controller = new class('integriq', $this->createMock(IRequest::class), $receiver, new StufZknRequestBody()) extends StufZdsConfirmationController {
			/**
			 * The body under test.
			 *
			 * @return string The body.
			 */
			protected function readRawBody(): string {
				return '<Bv01/>';
			}
		};

		$response = $controller->receive();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('<Fo03/>', $response->render());
	}//end testTheReceiversAnswerIsTheResponse()
}//end class
