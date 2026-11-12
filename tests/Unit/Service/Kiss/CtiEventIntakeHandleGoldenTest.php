<?php

/**
 * Characterisation test for CtiEventIntake::handle.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Event\CallEvent;
use OCA\Integriq\Service\Kiss\CallContextService;
use OCA\Integriq\Service\Kiss\CallEventDeduplicator;
use OCA\Integriq\Service\Kiss\CallEventLog;
use OCA\Integriq\Service\Kiss\CtiEventIntake;
use OCA\Integriq\Service\Kiss\CtiProviderInterface;
use OCA\Integriq\Service\Kiss\CtiSourceResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden record of the CTI event intake.
 */
class CtiEventIntakeHandleGoldenTest extends TestCase {

	/**
	 * Every call and log line, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * The intake.
	 *
	 * @param bool $withProvider Whether the source resolves to a provider.
	 * @param bool $logFails     Whether the call-event log throws.
	 * @param bool $listenerFails Whether a listener throws.
	 *
	 * @return CtiEventIntake
	 */
	private function intake(bool $withProvider, bool $logFails = false, bool $listenerFails = false): CtiEventIntake {
		$provider = $this->createMock(CtiProviderInterface::class);
		$provider->method('normalize')->willReturnCallback(
			function (array $sourceConfiguration, array $payload): array {
				$this->log[] = ['normalize' => [$sourceConfiguration, $payload]];
				return $payload['events'];
			}
		);
		$sources = $this->createMock(CtiSourceResolver::class);
		$sources->method('provider')->willReturn($withProvider === true ? $provider : null);

		$seen = [];
		$deduplicator = $this->createMock(CallEventDeduplicator::class);
		$deduplicator->method('claim')->willReturnCallback(
			function (string $sourceId, string $callId, string $kind) use (&$seen): bool {
				$this->log[] = ['claim' => [$sourceId, $callId, $kind]];
				$key = $sourceId . '|' . $callId . '|' . $kind;
				$fresh = isset($seen[$key]) === false;
				$seen[$key] = true;
				return $fresh;
			}
		);
		$context = $this->createMock(CallContextService::class);
		$context->method('resolve')->willReturnCallback(
			function (string $e164, array $sourceConfiguration = []): array {
				$this->log[] = ['context' => [$e164, $sourceConfiguration]];
				return ['caller' => ($e164 === '' ? null : ['name' => 'Jan']), 'openCases' => ($e164 === '' ? [] : ['Z-1'])];
			}
		);
		$callEvents = $this->createMock(CallEventLog::class);
		$callEvents->method('record')->willReturnCallback(
			function (CallEvent $event) use ($logFails): void {
				$this->log[] = ['record' => get_object_vars($event)];
				if ($logFails === true) {
					throw new RuntimeException('log full');
				}
			}
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($listenerFails): void {
				$this->log[] = ['dispatch' => $event::class];
				if ($listenerFails === true) {
					throw new RuntimeException('listener broke');
				}
			}
		);
		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<mixed> $log The shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, array_keys($context)]];
			}
		};

		return new CtiEventIntake($sources, $deduplicator, $context, $dispatcher, $logger, $callEvents);
	}//end intake()

	/**
	 * The recorded intakes.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$events = [
			['kind' => 'ringing', 'callId' => 'c-1', 'callerNumber' => '+31612345678', 'agentId' => 'a-1', 'at' => '2026-01-01T10:00:00Z'],
			['kind' => 'ringing', 'callId' => 'c-1', 'callerNumber' => '+31612345678'],
			['kind' => 'ended', 'callId' => 'c-1', 'durationSeconds' => '42'],
			'not an event',
			[],
		];
		$source = ['configuration' => ['provider' => 'webhook', 'kissUrl' => 'https://kiss.example.test']];
		$record = [];
		foreach ([
			'accepted' => [true, false, false, $source],
			'no provider' => [false, false, false, $source],
			'log fails' => [true, true, false, $source],
			'listener fails' => [true, false, true, []],
		] as $name => [$withProvider, $logFails, $listenerFails, $src]) {
			$this->log = [];
			$accepted = $this->intake($withProvider, $logFails, $listenerFails)->handle($src, 'cti-1', ['events' => $events]);
			$record[$name] = ['accepted' => $accepted, 'calls' => $this->log];
		}

		$path = __DIR__ . '/../../../fixtures/kiss/cti-intake-handle.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
