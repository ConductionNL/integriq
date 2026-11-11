<?php

/**
 * Characterisation tests for StufZdsMessageLog::findOutbound().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Service\StufZkn\StufZdsMessageLog;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the lookup a confirmation's cross-reference runs, read as the engine,
 * and every answer that finds nothing.
 */
class StufZdsMessageLogOutboundTest extends TestCase {

	/**
	 * Every findAll() call, as [config, _rbac, _multitenancy].
	 *
	 * @var list<array>
	 */
	private array $reads = [];

	/**
	 * The log over an object service answering $answer (or throwing it).
	 *
	 * @param mixed $answer The findAll() answer, or a Throwable.
	 *
	 * @return StufZdsMessageLog
	 */
	private function log(mixed $answer): StufZdsMessageLog {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($answer) {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		return new StufZdsMessageLog($objects);

	}//end log()

	/**
	 * The first outbound row answers with its id, enveloped or bare.
	 *
	 * @return void
	 */
	public function testTheFirstOutboundRowAnswersWithItsId(): void {
		$row = ObjectServiceMockBuilder::objectEntity($this, ['referentienummer' => 'R-1', 'id' => 'stale'], 'msg-1');

		$this->assertSame(['referentienummer' => 'R-1', 'id' => 'msg-1'], $this->log(['results' => [$row]])->findOutbound('R-1'));
		$this->assertSame(['referentienummer' => 'R-1', 'id' => 'msg-1'], $this->log([$row])->findOutbound('R-1'));
		$this->assertSame(
			[
				'filters' => ['register' => 'integriq', 'schema' => 'stuf_message', 'direction' => 'outbound', 'referentienummer' => 'R-1'],
				'limit' => 1,
			],
			$this->reads[0][0]
		);
		$this->assertSame([false, false], [$this->reads[0][1], $this->reads[0][2]]);

	}//end testTheFirstOutboundRowAnswersWithItsId()

	/**
	 * No reference, a failing read, no rows and an answer that is no list
	 * find nothing.
	 *
	 * @return void
	 */
	public function testNothingFoundAnswersNull(): void {
		$this->assertNull($this->log([])->findOutbound(''));
		$this->assertSame([], $this->reads);
		$this->assertNull($this->log(new RuntimeException('down'))->findOutbound('R'));
		$this->assertNull($this->log(['results' => []])->findOutbound('R'));
		$this->assertNull($this->log(['results' => 'x'])->findOutbound('R'));

	}//end testNothingFoundAnswersNull()

}//end class
