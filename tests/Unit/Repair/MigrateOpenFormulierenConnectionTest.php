<?php

/**
 * The repair step moves the source's webhook trust into the consumer.
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
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-existing-configuration-migrates-without-an-account
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateOpenFormulierenConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * MigrateOpenFormulierenConnection over the connection world.
 */
class MigrateOpenFormulierenConnectionTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * Every alert: reason and channel.
	 *
	 * @var list<array{reason: string, channel: string}>
	 */
	private array $alerted = [];

	/**
	 * A fresh world.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();
		$this->alerted = [];

	}//end setUp()

	/**
	 * Add an open-formulieren source (admin-only, so only an engine read sees it).
	 *
	 * @param string $secret  The webhook secret.
	 * @param bool   $enabled Whether the source is enabled.
	 *
	 * @return void
	 */
	private function addSource(string $secret, bool $enabled = true): void {
		$this->addOther(
			schema: 'source',
			uuid: 'source-of-' . count($this->worldOthers),
			data: [
				'name' => 'Open Formulieren',
				'type' => 'open-formulieren',
				'isEnabled' => $enabled,
				'configuration' => [
					'webhookSignature' => ['scheme' => 'github', 'secret' => $secret, 'header' => 'X-Hub-Signature-256'],
				],
			]
		);

	}//end addSource()

	/**
	 * The step over the world.
	 *
	 * @return MigrateOpenFormulierenConnection The step.
	 */
	private function step(): MigrateOpenFormulierenConnection {
		$objectService = $this->buildWorldObjectService();
		$connection = $this->buildWorldOpenFormulierenConnection(objectService: $objectService);

		$alerts = $this->createMock(DsoConnectionAlerts::class);
		$alerts->method('notify')->willReturnCallback(
			function (string $reason, string $channel = 'dso'): bool {
				$this->alerted[] = ['reason' => $reason, 'channel' => $channel];
				return true;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				OpenFormulierenConnection::class => $connection,
				OrObjectService::class => $objectService,
				DsoConnectionAlerts::class => $alerts,
			}
		);

		return new MigrateOpenFormulierenConnection(container: $container);

	}//end step()

	/**
	 * The source's trust becomes one consumer without an account, written as a system operation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-existing-configuration-migrates-without-an-account
	 */
	public function testTheSourceTrustBecomesOneConsumerWithoutAnAccount(): void {
		$this->addSource(secret: 'whsec_old');

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->worldConsumers);
		$consumer = array_values($this->worldConsumers)[0];
		$this->assertSame('open-formulieren', $consumer['authorizationType']);
		$this->assertSame('', $consumer['userId']);
		$this->assertSame('github', $consumer['authorizationConfiguration']['scheme']);
		$this->assertSame('whsec_old', $consumer['authorizationConfiguration']['secret']);
		$this->assertSame('X-Hub-Signature-256', $consumer['authorizationConfiguration']['header']);
		$this->assertSame([true], array_column($this->worldWrites, 'system'), 'the write is a system operation');
		$this->assertSame([['reason' => 'choose_account', 'channel' => 'openformulieren']], $this->alerted);

	}//end testTheSourceTrustBecomesOneConsumerWithoutAnAccount()

	/**
	 * A second run creates nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-existing-configuration-migrates-without-an-account
	 */
	public function testASecondRunCreatesNothing(): void {
		$this->addSource(secret: 'whsec_old');
		$step = $this->step();

		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->worldConsumers);
		$this->assertCount(1, $this->alerted);

	}//end testASecondRunCreatesNothing()

	/**
	 * No source with a secret, or only a disabled one: nothing is created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#scenario-existing-configuration-migrates-without-an-account
	 */
	public function testNoUsableSourceCreatesNothing(): void {
		$this->step()->run($this->createMock(IOutput::class));
		$this->addSource(secret: '');
		$this->addSource(secret: 'whsec_off', enabled: false);
		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->worldConsumers);
		$this->assertSame([], $this->alerted);

	}//end testNoUsableSourceCreatesNothing()
}//end class
