<?php

/**
 * Unit tests for the BroadenExchangeReadDefault repair step.
 *
 * The step runs against the real ActionAuthService over an in-memory
 * IAppConfig, so each test asserts on the JSON actually stored, not on a call
 * count: a step that wrote the wrong matrix would satisfy a count.
 *
 * @category Test
 * @package  OCA\Integriq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/exchange-read-defaults/specs/action-authorization/spec.md#requirement-req-002-an-upgrade-broadens-only-the-untouched-default
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\BroadenExchangeReadDefault;
use OCA\Integriq\Service\ActionAuthService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Repair\BroadenExchangeReadDefault
 */
final class BroadenExchangeReadDefaultTest extends TestCase {

	/**
	 * In-memory app config values, keyed by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * Number of writes to the `actions` key.
	 *
	 * @var int
	 */
	private int $matrixWrites = 0;

	/**
	 * The step under test.
	 *
	 * @var BroadenExchangeReadDefault
	 */
	private BroadenExchangeReadDefault $step;

	/**
	 * Build the step over an in-memory IAppConfig.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store        = [];
		$this->matrixWrites = 0;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string) ($this->store[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === 'actions') {
					$this->matrixWrites++;
				}

				$this->store[$key] = $value;
				return true;
			}
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool) ($this->store[$key] ?? $default)
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$actionAuth = new ActionAuthService($appConfig, $this->createMock(IGroupManager::class));
		$this->step = new BroadenExchangeReadDefault($actionAuth, $appConfig, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * Store a matrix as JSON.
	 *
	 * @param array<string, array<int, string>> $matrix The matrix.
	 *
	 * @return void
	 */
	private function givenMatrix(array $matrix): void {
		$this->store['actions'] = json_encode($matrix, JSON_THROW_ON_ERROR);
	}//end givenMatrix()

	/**
	 * Read the stored matrix back.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function storedMatrix(): array {
		return json_decode((string) ($this->store['actions'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
	}//end storedMatrix()

	/**
	 * The untouched default is broadened; every other entry is kept.
	 *
	 * @return void
	 */
	public function testUntouchedDefaultIsBroadened(): void {
		$this->givenMatrix(['exchange.read' => ['admin'], 'exchange.waive' => ['admin'], 'job.run' => ['admin', 'ops']]);

		$this->step->run($this->createMock(IOutput::class));

		$matrix = $this->storedMatrix();
		$this->assertSame(['admin', 'coordinators', 'compliance-officers'], $matrix['exchange.read']);
		$this->assertSame(['admin'], $matrix['exchange.waive']);
		$this->assertSame(['admin', 'ops'], $matrix['job.run']);
		$this->assertTrue($this->store[BroadenExchangeReadDefault::MARKER_KEY]);
	}//end testUntouchedDefaultIsBroadened()

	/**
	 * An absent entry reads as the admin fallback, so it gets the new default.
	 *
	 * @return void
	 */
	public function testAbsentEntryIsAdded(): void {
		$this->givenMatrix(['job.run' => ['admin']]);

		$this->step->run($this->createMock(IOutput::class));

		$this->assertSame(['admin', 'coordinators', 'compliance-officers'], $this->storedMatrix()['exchange.read']);
	}//end testAbsentEntryIsAdded()

	/**
	 * A value an administrator changed is never written.
	 *
	 * @return void
	 */
	public function testAdministratorValueIsKept(): void {
		$this->givenMatrix(['exchange.read' => ['admin', 'team-leads']]);

		$this->step->run($this->createMock(IOutput::class));

		$this->assertSame(0, $this->matrixWrites);
		$this->assertSame(['admin', 'team-leads'], $this->storedMatrix()['exchange.read']);
		$this->assertTrue($this->store[BroadenExchangeReadDefault::MARKER_KEY]);
	}//end testAdministratorValueIsKept()

	/**
	 * An empty matrix belongs to the seeding step; nothing is written, no marker.
	 *
	 * @return void
	 */
	public function testEmptyMatrixIsLeftAlone(): void {
		$this->step->run($this->createMock(IOutput::class));

		$this->assertSame(0, $this->matrixWrites);
		$this->assertArrayNotHasKey(BroadenExchangeReadDefault::MARKER_KEY, $this->store);
	}//end testEmptyMatrixIsLeftAlone()

	/**
	 * Admin set back on purpose after the first run survives the next upgrade.
	 *
	 * @return void
	 */
	public function testRunsOnlyOnce(): void {
		$this->givenMatrix(['exchange.read' => ['admin']]);
		$this->step->run($this->createMock(IOutput::class));

		$this->givenMatrix(['exchange.read' => ['admin']]);
		$this->matrixWrites = 0;
		$this->step->run($this->createMock(IOutput::class));

		$this->assertSame(0, $this->matrixWrites);
		$this->assertSame(['admin'], $this->storedMatrix()['exchange.read']);
	}//end testRunsOnlyOnce()

	/**
	 * The shipped seed carries the D33 default and keeps the write actions admin only.
	 *
	 * @return void
	 */
	public function testSeedCarriesTheNewDefault(): void {
		$seed = json_decode((string) file_get_contents(__DIR__.'/../../../lib/actions.seed.json'), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(BroadenExchangeReadDefault::NEW_DEFAULT, $seed['actions']['exchange.read']);
		$this->assertSame(['admin'], $seed['actions']['exchange.resubmit']);
		$this->assertSame(['admin'], $seed['actions']['exchange.waive']);
	}//end testSeedCarriesTheNewDefault()
}//end class
