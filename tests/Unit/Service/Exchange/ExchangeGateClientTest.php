<?php

/**
 * ExchangeGateClient: the gate fails closed on everything but an explicit allow.
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
 *
 * @link https://www.Integriq.nl
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
 * REQ-003 scenarios.
 */
class ExchangeGateClientTest extends TestCase {

	/**
	 * An exchange job owned by learniq.
	 *
	 * @var array<string, mixed>
	 */
	private const JOB = [
		'ownerApp' => 'learniq',
		'exchangeTarget' => 'bron-rod',
		'exchangeDirection' => 'export',
		'ownerRef' => 'school-advies/1',
		'exchangeScope' => ['berichtsoort' => 'schooladvies'],
	];

	/**
	 * Build a client whose dispatcher runs the given listener.
	 *
	 * @param callable|null $listener   Receives the real event, or null for no listener.
	 * @param bool          $appEnabled Whether the owning app is enabled.
	 *
	 * @return ExchangeGateClient The client.
	 */
	private function client(?callable $listener, bool $appEnabled = true): ExchangeGateClient {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function ($event) use ($listener): void {
				if ($listener !== null) {
					$listener($event);
				}
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($appEnabled);

		return new ExchangeGateClient($dispatcher, $apps, $this->createMock(LoggerInterface::class));

	}//end client()

	/**
	 * The owning app allows and hands over two records.
	 *
	 * @return void
	 */
	public function testTheOwningAppAllowsTheJob(): void {
		$seen = null;
		$client = $this->client(
			static function (ExchangeGateRequestedEvent $event) use (&$seen): void {
				$seen = $event;
				$event->allow([
					['recordId' => 'a', 'sourceKind' => 'learner-profile', 'data' => ['givenName' => 'Sanne']],
					['recordId' => 'b', 'sourceKind' => 'learner-profile', 'data' => ['givenName' => 'Daan']],
				]);
			}
		);

		$decision = $client->ask('job-1', self::JOB);

		$this->assertSame('allow', $decision['decision']);
		$this->assertCount(2, $decision['records']);
		$this->assertInstanceOf(ExchangeGateRequestedEvent::class, $seen);
		$this->assertSame('job-1', $seen->getJobId());
		$this->assertSame('bron-rod', $seen->getTarget());
		$this->assertSame(['berichtsoort' => 'schooladvies'], $seen->getScope());

	}//end testTheOwningAppAllowsTheJob()

	/**
	 * The owning app refuses with its own code and reason.
	 *
	 * @return void
	 */
	public function testTheOwningAppRefuses(): void {
		$client = $this->client(
			static function (ExchangeGateRequestedEvent $event): void {
				$event->refuse('teldatum-unconfirmed', 'The teldatum check for 1 October is not confirmed yet.');
			}
		);

		$decision = $client->ask('job-1', self::JOB);

		$this->assertSame('refuse', $decision['decision']);
		$this->assertSame('teldatum-unconfirmed', $decision['code']);
		$this->assertSame([], $decision['records']);

	}//end testTheOwningAppRefuses()

	/**
	 * A disabled owning app is never asked.
	 *
	 * @return void
	 */
	public function testTheOwningAppIsAbsent(): void {
		$asked = false;
		$client = $this->client(
			static function () use (&$asked): void {
				$asked = true;
			},
			false
		);

		$decision = $client->ask('job-1', self::JOB);

		$this->assertSame('gate-app-absent', $decision['code']);
		$this->assertFalse($asked, 'An absent app must not be asked.');

	}//end testTheOwningAppIsAbsent()

	/**
	 * Nobody answers.
	 *
	 * @return void
	 */
	public function testNobodyAnswersTheGate(): void {
		$decision = $this->client(null)->ask('job-1', self::JOB);

		$this->assertSame('refuse', $decision['decision']);
		$this->assertSame('gate-unanswered', $decision['code']);

	}//end testNobodyAnswersTheGate()

	/**
	 * The listener throws; the exception stays inside the gate.
	 *
	 * @return void
	 */
	public function testTheGateListenerThrows(): void {
		$client = $this->client(
			static function (): void {
				throw new RuntimeException('database gone');
			}
		);

		$decision = $client->ask('job-1', self::JOB);

		$this->assertSame('gate-error', $decision['code']);

	}//end testTheGateListenerThrows()

	/**
	 * A job without an owner is refused before anything is dispatched.
	 *
	 * @return void
	 */
	public function testAJobWithoutAnOwnerIsRefused(): void {
		$decision = $this->client(null)->ask('job-1', ['exchangeTarget' => 'bron-rod']);

		$this->assertSame('gate-owner-missing', $decision['code']);

	}//end testAJobWithoutAnOwnerIsRefused()

	/**
	 * A refusal without a code falls back to gate-refused; the first answer wins.
	 *
	 * @return void
	 */
	public function testTheFirstAnswerWinsAndAnEmptyCodeFallsBack(): void {
		$client = $this->client(
			static function (ExchangeGateRequestedEvent $event): void {
				$event->refuse('', 'No.');
				$event->allow([['recordId' => 'x', 'data' => []]]);
			}
		);

		$decision = $client->ask('job-1', self::JOB);

		$this->assertSame('refuse', $decision['decision']);
		$this->assertSame('gate-refused', $decision['code']);

	}//end testTheFirstAnswerWinsAndAnEmptyCodeFallsBack()
}//end class
