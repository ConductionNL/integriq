<?php

/**
 * Unit tests: the live Zermelo rostering client against a recorded Zermelo answer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Roster;

use DateTimeImmutable;
use OCA\Integriq\Adapters\Roster\RosterImportClientZermelo;
use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Tests\Unit\Adapters\Support\SupplierCallHarness;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Next week's appointments, read from the school's Zermelo source.
 */
class RosterImportClientZermeloTest extends TestCase {
	use SupplierCallHarness;

	/**
	 * The client over a recorded answer, on Wednesday 2026-11-11 noon.
	 *
	 * @param int   $status The answered status.
	 * @param mixed $body   The answered body.
	 *
	 * @return RosterImportClientZermelo
	 */
	private function client(int $status, mixed $body): RosterImportClientZermelo {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-11T12:00:00'));

		return new RosterImportClientZermelo(calls: $this->supplierCall(slugs: ['roster-zermelo'], status: $status, body: $body), time: $time);
	}//end client()

	/**
	 * The recorded appointments come back in Zermelo's own field names, from next week's window.
	 *
	 * @return void
	 */
	public function testNextWeeksLessonsAreRead(): void {
		$recorded = $this->recorded(path: 'roster/zermelo-appointments-recorded.json');

		$lessons = $this->client(status: 200, body: (string)json_encode($recorded))->fetchLessons(systemId: 'roster-zermelo');

		$this->assertSame(expected: $recorded['response']['data'], actual: $lessons);
		$this->assertSame(expected: ['roster-zermelo', '/api/v3/appointments', 'GET'], actual: array_slice($this->calls[0], 0, 3));
		$this->assertSame(
			expected: [
				'start' => (new DateTimeImmutable('2026-11-16T00:00:00'))->getTimestamp(),
				'end' => (new DateTimeImmutable('2026-11-23T00:00:00'))->getTimestamp(),
				'valid' => 'true',
				'fields' => 'appointmentInstance,start,end,subjects,groups,teachers,locations,cancelled',
			],
			actual: $this->calls[0][3]['query']
		);
		$this->assertSame(expected: 'zermelo', actual: $this->client(status: 200, body: '{}')->flavour());
	}//end testNextWeeksLessonsAreRead()

	/**
	 * Another rostering system is refused and Zermelo is not called.
	 *
	 * @return void
	 */
	public function testAnotherSystemIsRefusedWithoutACall(): void {
		$client = $this->client(status: 200, body: '{}');

		try {
			$client->fetchLessons(systemId: 'roster-xedule');
			$this->fail('Only Zermelo is live.');
		} catch (SupplierCallException $exception) {
			$this->assertSame(expected: 'No live rostering client exists for roster-xedule; only roster-zermelo is live.', actual: $exception->getMessage());
		}

		$this->assertSame(expected: [], actual: $this->calls);
	}//end testAnotherSystemIsRefusedWithoutACall()

	/**
	 * A refused token and an answer without data both throw.
	 *
	 * @return void
	 */
	public function testARefusalOrAnAnswerWithoutDataThrows(): void {
		try {
			$this->client(status: 403, body: '{}')->fetchLessons(systemId: 'roster-zermelo');
			$this->fail('A 403 must throw.');
		} catch (SupplierCallException $exception) {
			$this->assertTrue($exception->isRefusedCredential());
		}

		$this->expectExceptionMessage('Zermelo answered without response.data.');
		$this->client(status: 200, body: '{"response":{"status":200}}')->fetchLessons(systemId: 'roster-zermelo');
	}//end testARefusalOrAnAnswerWithoutDataThrows()
}//end class
