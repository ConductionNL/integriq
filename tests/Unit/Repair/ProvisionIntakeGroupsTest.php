<?php

/**
 * The repair step creates the intake and handler groups and enrols the intake accounts.
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
 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-an-upgraded-instance-keeps-its-intake-account
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\ProvisionIntakeGroups;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * ProvisionIntakeGroups over the connection world.
 */
class ProvisionIntakeGroupsTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * A fresh world.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();

	}//end setUp()

	/**
	 * The step over the world.
	 *
	 * @return ProvisionIntakeGroups The step.
	 */
	private function step(): ProvisionIntakeGroups {
		$connection = $this->buildWorldConnection(objectService: $this->buildWorldObjectService());
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				DsoConnection::class => $connection,
			}
		);

		return new ProvisionIntakeGroups(groups: $this->buildWorldIntakeGroups(), container: $container);

	}//end step()

	/**
	 * The four groups exist afterwards, the intake accounts are in their
	 * intake group, and nobody is added to a handler group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-an-upgraded-instance-keeps-its-intake-account
	 */
	public function testGroupsAreCreatedAndIntakeAccountsEnrolled(): void {
		$this->addAccount(uid: 'dso-intake', grants: []);
		$this->addAccount(uid: 'of-intake', grants: []);
		$this->addDsoConsumer(userId: 'dso-intake');
		$this->addOpenFormulierenConsumer(userId: 'of-intake');

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				'dso-intake' => ['dso-intake'],
				'dso-behandelaars' => [],
				'openformulieren-intake' => ['of-intake'],
				'openformulieren-behandelaars' => [],
			],
			$this->worldGroupMembers
		);
		$this->assertSame([], $this->worldWrites, 'nothing is written to OpenRegister');

	}//end testGroupsAreCreatedAndIntakeAccountsEnrolled()

	/**
	 * A second run changes nothing; a connection without an account enrols nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#scenario-an-upgraded-instance-keeps-its-intake-account
	 */
	public function testASecondRunAndAnEmptyAccountChangeNothing(): void {
		$this->addAccount(uid: 'dso-intake', grants: []);
		$this->addDsoConsumer(userId: 'dso-intake');
		$this->addOpenFormulierenConsumer(userId: '');

		$step = $this->step();
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['dso-intake'], $this->worldGroupMembers['dso-intake']);
		$this->assertSame([], $this->worldGroupMembers['openformulieren-intake']);

	}//end testASecondRunAndAnEmptyAccountChangeNothing()
}//end class
