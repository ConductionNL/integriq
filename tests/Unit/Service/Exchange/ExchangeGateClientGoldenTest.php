<?php

/**
 * Golden tests for ExchangeGateClient::ask.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Exchange;

use OCA\Integriq\Event\ExchangeGateRequestedEvent;
use OCA\Integriq\Service\Exchange\ExchangeGateClient;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every decision of the gate — allowed and refused — with its exact
 * reason, the event the owning app sees, and the warning logged when the gate fails.
 */
class ExchangeGateClientGoldenTest extends TestCase {

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Events dispatched.
	 *
	 * @var array<int, ExchangeGateRequestedEvent>
	 */
	private array $events = [];

	/**
	 * Apps asked whether they are enabled.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * A client whose owning app runs the given listener.
	 *
	 * @param callable|null $listener   The owning app's listener.
	 * @param bool          $appEnabled Whether the owning app is enabled.
	 *
	 * @return ExchangeGateClient The client.
	 */
	private function client(?callable $listener, bool $appEnabled=true): ExchangeGateClient {
		$this->warnings = [];
		$this->events   = [];
		$this->asked    = [];
		$dispatcher     = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event) use ($listener): void {
				$this->events[] = $event;
				if ($listener !== null) {
					$listener($event);
				}
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(
			function (string $app) use ($appEnabled): bool {
				$this->asked[] = $app;
				return $appEnabled;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new ExchangeGateClient($dispatcher, $apps, $logger);

	}//end client()

	/**
	 * The decision without its timestamp, which must be an ISO 8601 moment.
	 *
	 * @param array $decision The decision.
	 *
	 * @return array The decision minus checkedAt.
	 */
	private function stable(array $decision): array {
		$this->assertSame(['decision', 'code', 'reason', 'checkedAt', 'records'], array_keys($decision));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $decision['checkedAt']);
		unset($decision['checkedAt']);
		return $decision;

	}//end stable()

	/**
	 * Refusals before the gate is asked: no owner, absent app.
	 *
	 * @return void
	 */
	public function testRefusalsBeforeAsking(): void {
		$client = $this->client(null);
		$this->assertSame(
			['decision' => 'refuse', 'code' => 'gate-owner-missing', 'reason' => 'The job names no owning app.', 'records' => []],
			$this->stable($client->ask('job-1', ['ownerApp' => '']))
		);
		$this->assertSame([], $this->asked);

		$client = $this->client(null, false);
		$this->assertSame(
			['decision' => 'refuse', 'code' => 'gate-app-absent', 'reason' => 'The owning app "learniq" is not installed or not enabled.', 'records' => []],
			$this->stable($client->ask('job-1', ['ownerApp' => 'learniq']))
		);
		$this->assertSame(['learniq'], $this->asked);
		$this->assertSame([], $this->events);

	}//end testRefusalsBeforeAsking()

	/**
	 * The event carries the job; a non-array scope becomes empty; nobody answering refuses.
	 *
	 * @return void
	 */
	public function testEventAndUnanswered(): void {
		$client = $this->client(null);
		$this->assertSame(
			['decision' => 'refuse', 'code' => 'gate-unanswered', 'reason' => 'The owning app "learniq" did not answer the gate.', 'records' => []],
			$this->stable($client->ask('job-1', ['ownerApp' => 'learniq', 'exchangeTarget' => 't', 'exchangeDirection' => 'export', 'ownerRef' => 'r/1', 'exchangeScope' => 'x']))
		);
		$event = $this->events[0];
		$this->assertSame(['job-1', 'learniq', 't', 'export', 'r/1', []], [$event->getJobId(), $event->getOwnerApp(), $event->getTarget(), $event->getDirection(), $event->getOwnerRef(), $event->getScope()]);

		$client->ask('job-2', ['ownerApp' => 'learniq']);
		$event = $this->events[1];
		$this->assertSame(['job-2', 'learniq', '', '', '', []], [$event->getJobId(), $event->getOwnerApp(), $event->getTarget(), $event->getDirection(), $event->getOwnerRef(), $event->getScope()]);

	}//end testEventAndUnanswered()

	/**
	 * A failing gate is logged and refused.
	 *
	 * @return void
	 */
	public function testFailingGate(): void {
		$client = $this->client(static function (): void {
			throw new RuntimeException('boom');
		});
		$this->assertSame(
			['decision' => 'refuse', 'code' => 'gate-error', 'reason' => 'The gate of "learniq" failed: boom', 'records' => []],
			$this->stable($client->ask('job-1', ['ownerApp' => 'learniq']))
		);
		$this->assertSame(['[ExchangeGateClient] the gate of learniq failed for job job-1: boom'], $this->warnings);

	}//end testFailingGate()

	/**
	 * The owning app's refusal and allowance.
	 *
	 * @return void
	 */
	public function testAnswers(): void {
		$client = $this->client(static fn (ExchangeGateRequestedEvent $event) => $event->refuse('', ''));
		$this->assertSame(
			['decision' => 'refuse', 'code' => 'gate-refused', 'reason' => '', 'records' => []],
			$this->stable($client->ask('job-1', ['ownerApp' => 'learniq']))
		);

		$client = $this->client(static fn (ExchangeGateRequestedEvent $event) => $event->refuse('own-code', 'own reason'));
		$this->assertSame(
			['decision' => 'refuse', 'code' => 'own-code', 'reason' => 'own reason', 'records' => []],
			$this->stable($client->ask('job-1', ['ownerApp' => 'learniq']))
		);

		$client = $this->client(static fn (ExchangeGateRequestedEvent $event) => $event->allow([['id' => 1]]));
		$this->assertSame(
			['decision' => 'allow', 'code' => '', 'reason' => '', 'records' => [['id' => 1]]],
			$this->stable($client->ask('job-1', ['ownerApp' => 'learniq']))
		);
		$this->assertSame([], $this->warnings);

	}//end testAnswers()
}//end class
