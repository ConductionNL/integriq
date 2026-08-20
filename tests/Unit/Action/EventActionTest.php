<?php

/**
 * Tests for the scheduled CloudEvent emitter.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Action;

use OCA\OpenConnector\Action\EventAction;
use OCA\OpenConnector\Service\EventService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenConnector\Action\EventAction
 */
class EventActionTest extends TestCase {

	/**
	 * Build the action over a mocked EventService.
	 *
	 * @param EventService|null $service The service double.
	 *
	 * @return EventAction The action.
	 */
	private function action(?EventService $service = null): EventAction {
		if ($service === null) {
			$service = $this->createMock(EventService::class);
			$service->method('hasActiveSubscriptions')->willReturn(true);
			$service->method('emitCloudEvent')->willReturn([]);
		}

		return new EventAction($service);
	}//end action()

	/**
	 * A job with no type emits nothing and says why.
	 *
	 * @return void
	 */
	public function testItRefusesWithoutAType(): void {
		$service = $this->createMock(EventService::class);
		$service->expects($this->never())->method('emitCloudEvent');

		$result = $this->action($service)->run(['source' => '/openconnector']);

		$this->assertSame('ERROR', $result['level']);
		$this->assertStringContainsString('type', $result['message']);
	}//end testItRefusesWithoutAType()

	/**
	 * A job with no source emits nothing and says why.
	 *
	 * @return void
	 */
	public function testItRefusesWithoutASource(): void {
		$service = $this->createMock(EventService::class);
		$service->expects($this->never())->method('emitCloudEvent');

		$result = $this->action($service)->run(['type' => 'nl.conduction.test']);

		$this->assertSame('ERROR', $result['level']);
		$this->assertStringContainsString('source', $result['message']);
	}//end testItRefusesWithoutASource()

	/**
	 * A configured job emits the event with the arguments it was given.
	 *
	 * @return void
	 */
	public function testItEmitsTheConfiguredEvent(): void {
		$service = $this->createMock(EventService::class);
		$service->method('hasActiveSubscriptions')->willReturn(true);
		$service->expects($this->once())
			->method('emitCloudEvent')
			->with(
				'nl.conduction.window.closed',
				'/openconnector/jobs',
				'window-42',
				['closedAt' => '2026-07-27'],
				'admin'
			)
			->willReturn([['id' => 'm1'], ['id' => 'm2']]);

		$result = $this->action($service)->run([
			'type' => 'nl.conduction.window.closed',
			'source' => '/openconnector/jobs',
			'subject' => 'window-42',
			'data' => ['closedAt' => '2026-07-27'],
			'userId' => 'admin',
		]);

		$this->assertSame('INFO', $result['level']);
		$this->assertStringContainsString('2 subscription message(s)', $result['message']);
	}//end testItEmitsTheConfiguredEvent()

	/**
	 * `data` authored as a JSON string is accepted — job arguments come from a
	 * free-text field, so this is the normal case, not an edge case.
	 *
	 * @return void
	 */
	public function testItAcceptsDataAsAJsonString(): void {
		$service = $this->createMock(EventService::class);
		$service->method('hasActiveSubscriptions')->willReturn(true);
		$service->expects($this->once())
			->method('emitCloudEvent')
			->with($this->anything(), $this->anything(), $this->anything(), ['a' => 1], $this->anything())
			->willReturn([]);

		$result = $this->action($service)->run([
			'type' => 't',
			'source' => 's',
			'data' => '{"a":1}',
		]);

		$this->assertSame('INFO', $result['level']);
	}//end testItAcceptsDataAsAJsonString()

	/**
	 * A `data` string that is not JSON is refused rather than emitted empty.
	 *
	 * @return void
	 */
	public function testItRefusesUnparseableData(): void {
		$service = $this->createMock(EventService::class);
		$service->expects($this->never())->method('emitCloudEvent');

		$result = $this->action($service)->run(['type' => 't', 'source' => 's', 'data' => 'not json']);

		$this->assertSame('ERROR', $result['level']);
	}//end testItRefusesUnparseableData()

	/**
	 * A bus with no subscribers is reported, not treated as a failure.
	 *
	 * @return void
	 */
	public function testItNotesWhenNobodyIsSubscribed(): void {
		$service = $this->createMock(EventService::class);
		$service->method('hasActiveSubscriptions')->willReturn(false);
		$service->method('emitCloudEvent')->willReturn([]);

		$result = $this->action($service)->run(['type' => 't', 'source' => 's']);

		$this->assertSame('INFO', $result['level']);
		$this->assertNotEmpty(
			array_filter($result['stackTrace'], static fn ($l): bool => str_contains($l, 'No active subscriptions')),
			'a silent bus is called out in the trace'
		);
	}//end testItNotesWhenNobodyIsSubscribed()

	/**
	 * A throwing emit is reported, never propagated.
	 *
	 * This is the one that matters operationally: an action that throws aborts
	 * the whole cron pass, taking every other app's jobs with it.
	 *
	 * @return void
	 */
	public function testAThrowingEmitDoesNotEscape(): void {
		$service = $this->createMock(EventService::class);
		$service->method('hasActiveSubscriptions')->willReturn(true);
		$service->method('emitCloudEvent')->willThrowException(new RuntimeException('bus down'));

		$result = $this->action($service)->run(['type' => 't', 'source' => 's']);

		$this->assertSame('ERROR', $result['level']);
		$this->assertStringContainsString('bus down', $result['message']);
	}//end testAThrowingEmitDoesNotEscape()

	/**
	 * A failing subscription check does not stop the emit.
	 *
	 * @return void
	 */
	public function testAFailingSubscriptionCheckStillEmits(): void {
		$service = $this->createMock(EventService::class);
		$service->method('hasActiveSubscriptions')->willThrowException(new RuntimeException('db hiccup'));
		$service->expects($this->once())->method('emitCloudEvent')->willReturn([]);

		$result = $this->action($service)->run(['type' => 't', 'source' => 's']);

		$this->assertSame('INFO', $result['level']);
	}//end testAFailingSubscriptionCheckStillEmits()

	/**
	 * The action never returns an empty array — the old stub did, which is why
	 * a scheduled EventAction job looked like it had run successfully.
	 *
	 * @return void
	 */
	public function testItAlwaysReportsSomething(): void {
		foreach ([[], ['type' => 't'], ['type' => 't', 'source' => 's']] as $args) {
			$result = $this->action()->run($args);
			$this->assertNotSame([], $result);
			$this->assertArrayHasKey('stackTrace', $result);
			$this->assertArrayHasKey('level', $result);
		}
	}//end testItAlwaysReportsSomething()
}//end class
