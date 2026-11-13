<?php

/**
 * Tests for the scheduled ping: it needs a source id, reads the source
 * without RBAC or multitenancy, calls it once and reports the call log.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Job;

use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\Job\PingAction;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\Job\PingAction
 */
class PingActionTest extends TestCase {

	/**
	 * The call service double.
	 *
	 * @var CallServiceInterface&MockObject
	 */
	private CallServiceInterface&MockObject $callService;

	/**
	 * The OpenRegister object service double.
	 *
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService&MockObject $objects;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->callService = $this->createMock(CallServiceInterface::class);
		$this->objects = $this->createMock(OrObjectService::class);
	}//end setUp()

	/**
	 * The action under test.
	 *
	 * @return PingAction The action.
	 */
	private function action(): PingAction {
		return new PingAction($this->callService, $this->objects);
	}//end action()

	/**
	 * Without a source id nothing is read or called.
	 *
	 * @return void
	 */
	public function testItSkipsWithoutASourceId(): void {
		$this->objects->expects($this->never())->method('find');
		$this->callService->expects($this->never())->method('call');

		$expected = ['stackTrace' => ['Running PingAction', 'No sourceId in arguments, skipping ping']];
		$this->assertSame($expected, $this->action()->run());
		$this->assertSame($expected, $this->action()->run(['sourceId' => '']));
		$this->assertSame($expected, $this->action()->run(['sourceId' => 0]));
	}//end testItSkipsWithoutASourceId()

	/**
	 * An unknown source is reported and not called.
	 *
	 * @return void
	 */
	public function testItReportsAnUnknownSource(): void {
		$this->objects->method('find')->willReturn(null);
		$this->callService->expects($this->never())->method('call');

		$this->assertSame(
			['stackTrace' => ['Running PingAction', 'Found sourceId 42 in arguments', 'Source not found for id: 42']],
			$this->action()->run(['sourceId' => 42])
		);
	}//end testItReportsAnUnknownSource()

	/**
	 * A found source is read in system context, called once, and its call log reported.
	 *
	 * @return void
	 */
	public function testItCallsTheSourceAndReportsTheCallLog(): void {
		$source = new ObjectEntity();
		$this->objects->expects($this->once())
			->method('find')
			->with(id: 'src-1', register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false)
			->willReturn($source);

		$callLog = $this->createMock(ObjectEntity::class);
		$callLog->method('getUuid')->willReturn('log-uuid-7');
		$this->callService->expects($this->once())->method('call')->with($source)->willReturn($callLog);

		$this->assertSame(
			[
				'stackTrace' => [
					'Running PingAction',
					'Found sourceId src-1 in arguments',
					'Calling callService...',
					'Created callLog with uuid: log-uuid-7',
				],
			],
			$this->action()->run(['sourceId' => 'src-1'])
		);
	}//end testItCallsTheSourceAndReportsTheCallLog()
}//end class
