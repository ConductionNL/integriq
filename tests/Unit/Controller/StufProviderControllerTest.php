<?php

/**
 * Unit tests for StufProviderController.
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

use OCA\Integriq\Controller\StufProviderController;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Controller\StufProviderController
 */
class StufProviderControllerTest extends TestCase {

	/**
	 * Each route hands the receiver its source and service, and returns its answer as XML.
	 *
	 * @return void
	 */
	public function testEachRouteNamesItsServiceAndReturnsTheReceiversAnswer(): void {
		$receiver = $this->createMock(StufProviderReceiver::class);
		$receiver->expects($this->exactly(2))->method('receive')->willReturnCallback(
			static fn(string $rawXml, string $sourceId, string $service): array => [
				'statusCode' => ($service === 'cases' ? 200 : 500),
				'body' => '<' . $service . ' source="' . $sourceId . '" in="' . $rawXml . '"/>',
			]
		);

		$controller = new class('integriq', $this->createMock(IRequest::class), $receiver) extends StufProviderController {
			/**
			 * The body under test.
			 *
			 * @return string The body.
			 */
			protected function readRawBody(): string {
				return 'zakLv01';
			}
		};

		$cases = $controller->cases(sourceId: 'zsh');
		$this->assertSame(200, $cases->getStatus());
		$this->assertSame('<cases source="zsh" in="zakLv01"/>', $cases->render());

		$persons = $controller->persons(sourceId: 'zsh');
		$this->assertSame(500, $persons->getStatus());
		$this->assertSame('<persons source="zsh" in="zakLv01"/>', $persons->render());
	}//end testEachRouteNamesItsServiceAndReturnsTheReceiversAnswer()
}//end class
