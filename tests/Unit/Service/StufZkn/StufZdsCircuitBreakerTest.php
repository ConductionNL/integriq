<?php

/**
 * Unit tests for StufZdsCircuitBreaker.
 *
 * Ported from dossiq's CircuitBreakerServiceTest, against an in-memory app config.
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
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Service\StufZkn\StufZdsCircuitBreaker;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsCircuitBreaker
 */
class StufZdsCircuitBreakerTest extends TestCase {

	/**
	 * The in-memory app config values.
	 *
	 * @var array<string,int>
	 */
	private array $store = [];

	/**
	 * The breaker under test.
	 *
	 * @var StufZdsCircuitBreaker
	 */
	private StufZdsCircuitBreaker $breaker;

	/**
	 * Set up an app config backed by $store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$config      = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default=0): int => ($this->store[$key] ?? $default));
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->assertSame('integriq', $app);
				$this->assertLessThanOrEqual(64, strlen($key));
				$this->store[$key] = $value;
				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->store[$key]);
			}
		);

		$this->breaker = new StufZdsCircuitBreaker($config, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * Three failures degrade, the fourth opens the circuit.
	 *
	 * @return void
	 */
	public function testFourFailuresOpenTheCircuit(): void {
		$source = str_repeat('a-very-long-source-id-', 5);
		for ($i = 1; $i < StufZdsCircuitBreaker::THRESHOLD; $i++) {
			$this->assertFalse($this->breaker->recordFailure($source));
		}

		$this->assertTrue($this->breaker->allows($source));
		$this->assertSame('degraded', $this->breaker->snapshot($source)['state']);

		$this->assertTrue($this->breaker->recordFailure($source));
		$this->assertFalse($this->breaker->allows($source));
		$this->assertSame(['state' => 'circuit_open', 'failureCount' => 4], array_slice($this->breaker->snapshot($source), 0, 2));
	}//end testFourFailuresOpenTheCircuit()

	/**
	 * A success clears the count and closes the circuit.
	 *
	 * @return void
	 */
	public function testResetClosesTheCircuit(): void {
		for ($i = 0; $i < StufZdsCircuitBreaker::THRESHOLD; $i++) {
			$this->breaker->recordFailure('src');
		}

		$this->breaker->reset('src');
		$this->assertTrue($this->breaker->allows('src'));
		$this->assertSame('ok', $this->breaker->snapshot('src')['state']);
	}//end testResetClosesTheCircuit()

	/**
	 * After the cooldown the circuit closes on its own.
	 *
	 * @return void
	 */
	public function testCircuitClosesAfterTheCooldown(): void {
		for ($i = 0; $i < StufZdsCircuitBreaker::THRESHOLD; $i++) {
			$this->breaker->recordFailure('src');
		}

		foreach (array_keys($this->store) as $key) {
			if (str_starts_with($key, 'stuf.zds.o.') === true) {
				$this->store[$key] = (time() - StufZdsCircuitBreaker::COOLDOWN_SECONDS - 1);
			}
		}

		$this->assertTrue($this->breaker->allows('src'));
		$this->assertSame(0, $this->breaker->snapshot('src')['failureCount']);
	}//end testCircuitClosesAfterTheCooldown()

	/**
	 * A source without an id is never broken.
	 *
	 * @return void
	 */
	public function testNoSourceIdIsNeverBroken(): void {
		$this->assertFalse($this->breaker->recordFailure(''));
		$this->assertTrue($this->breaker->allows(''));
		$this->assertSame([], $this->store);
	}//end testNoSourceIdIsNeverBroken()
}//end class
