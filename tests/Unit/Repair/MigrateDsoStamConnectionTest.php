<?php

/**
 * Tests for MigrateDsoStamConnection.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateDsoStamConnection;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Keys present creates one consumer without an account; a rerun and no keys create nothing.
 */
class MigrateDsoStamConnectionTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * The legacy app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Reasons alerted.
	 *
	 * @var list<string>
	 */
	private array $alerted = [];

	/**
	 * An empty world, no user active (an upgrade runs on the CLI).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetWorld();
		$this->config = [];
		$this->alerted = [];
	}//end setUp()

	/**
	 * The repair step over the world.
	 *
	 * @return MigrateDsoStamConnection
	 */
	private function step(): MigrateDsoStamConnection {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$objectService = $this->buildWorldObjectService();
		$connection = $this->buildWorldConnection(objectService: $objectService);
		$alerts = $this->createMock(DsoConnectionAlerts::class);
		$alerts->method('notify')->willReturnCallback(
			function (string $reason): bool {
				$this->alerted[] = $reason;
				return true;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				DsoConnection::class => $connection,
				OrObjectService::class => $objectService,
				DsoConnectionAlerts::class => $alerts,
			}
		);

		return new MigrateDsoStamConnection(appConfig: $appConfig, container: $container);
	}//end step()

	/**
	 * The old keys become one dso-stam consumer with an empty account, written
	 * as a system operation, and the admins are asked to choose the account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-existing-configuration-migrates-without-an-account
	 */
	public function testTheOldKeysBecomeOneConsumerWithoutAnAccount(): void {
		$this->config = ['dso_pki_mode' => 'rsa', 'dso_pki_hmac_secret' => 'old-secret', 'dso_pki_root_ca' => 'ROOT'];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->worldConsumers);
		$consumer = array_values($this->worldConsumers)[0];
		$this->assertSame('dso-stam', $consumer['authorizationType']);
		$this->assertSame('', $consumer['userId']);
		$this->assertSame('pkioverheid', $consumer['authorizationConfiguration']['mode']);
		$this->assertSame('old-secret', $consumer['authorizationConfiguration']['hmacSecret']);
		$this->assertSame('ROOT', $consumer['authorizationConfiguration']['rootCa']);
		$this->assertSame([true], array_column($this->worldWrites, 'system'), 'the write is a system operation');
		$this->assertSame(['choose_account'], $this->alerted);

	}//end testTheOldKeysBecomeOneConsumerWithoutAnAccount()

	/**
	 * A second run creates nothing and notifies nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-existing-configuration-migrates-without-an-account
	 */
	public function testASecondRunCreatesNothing(): void {
		$this->config = ['dso_pki_hmac_secret' => 'old-secret'];
		$step = $this->step();

		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->worldConsumers);
		$this->assertSame(['choose_account'], $this->alerted);

	}//end testASecondRunCreatesNothing()

	/**
	 * No keys: nothing is created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-5
	 */
	public function testNoKeysCreateNothing(): void {
		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->worldConsumers);
		$this->assertSame([], $this->alerted);

	}//end testNoKeysCreateNothing()
}//end class
