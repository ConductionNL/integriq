<?php

/**
 * The placeholder-secret repair and its occ command.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Command\BlankPlaceholderSecrets;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlannerInterface;
use OCA\Integriq\Service\Security\PlaceholderSecretRepair;
use OCA\Integriq\Service\Security\PlaceholderSecretRepairInterface;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\ServiceBindings;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Dry run, apply, failed save and the command's default.
 *
 * @covers \OCA\Integriq\Service\Security\PlaceholderSecretRepair
 * @covers \OCA\Integriq\Command\BlankPlaceholderSecrets
 * @covers \OCA\Integriq\Service\ServiceBindings
 */
class PlaceholderSecretRepairTest extends TestCase {

	private const P = SensitiveFieldRegistry::PLACEHOLDER;

	/**
	 * Saves the repair made: [schema, uuid, object, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * The raw objects per schema and uuid.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function store(): array {
		return [
			'source' => [
				'src-1' => ['name' => 'CTI', 'configuration' => ['sharedSecret' => self::P, 'eventsPath' => '/x', 'authentication' => ['type' => 'apikey', 'keys' => self::P]]],
				'src-2' => ['name' => 'Clean', 'configuration' => ['sharedSecret' => 'real', 'note' => 'contains ' . self::P]],
			],
			'rule' => [
				'rule-1' => ['name' => 'Rule', 'configuration' => ['authentication' => ['users' => [self::P, 'bob']]]],
			],
		];
	}//end store()

	/**
	 * The repair over the scripted store.
	 *
	 * @param boolean $saveFails Whether every save throws.
	 *
	 * @return PlaceholderSecretRepair
	 */
	private function repair(bool $saveFails = false): PlaceholderSecretRepair {
		$store  = $this->store();
		$reader = $this->createMock(InlineSecretMigrationPlannerInterface::class);
		$reader->method('listUuids')->willReturnCallback(
			static fn (string $schema, int $limit) => array_map(static fn (array $o) => $o['name'], array_slice($store[$schema] ?? [], 0, $limit, true))
		);
		$reader->method('readRawSource')->willReturnCallback(
			static fn (string $uuid, string $schema) => ($store[$schema][$uuid] ?? [])
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null, $_rbac = true, $_multitenancy = true) use ($saveFails) {
				if ($saveFails === true) {
					throw new RuntimeException('save refused');
				}

				$this->saves[] = [$register, $schema, $uuid, $object, $_rbac, $_multitenancy];
				return new ObjectEntity();
			}
		);

		return new PlaceholderSecretRepair($reader, $objects, $this->createMock(LoggerInterface::class));
	}//end repair()

	/**
	 * A dry run reports every placeholder path and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunReportsAndWritesNothing(): void {
		$report = $this->repair()->run(apply: false, limit: 1000);

		$this->assertSame([], $this->saves);
		$this->assertSame(2, $report['found']);
		$this->assertSame(0, $report['blanked']);
		$this->assertSame(
			[
				['schema' => 'source', 'uuid' => 'src-1', 'name' => 'CTI', 'paths' => ['configuration.sharedSecret', 'configuration.authentication.keys'], 'blanked' => false],
				['schema' => 'rule', 'uuid' => 'rule-1', 'name' => 'Rule', 'paths' => ['configuration.authentication.users.0'], 'blanked' => false],
			],
			$report['objects']
		);
	}//end testADryRunReportsAndWritesNothing()

	/**
	 * Apply blanks exactly the placeholder values, in system context.
	 *
	 * @return void
	 */
	public function testApplyBlanksOnlyThePlaceholders(): void {
		$report = $this->repair()->run(apply: true, limit: 1000);

		$this->assertSame(2, $report['blanked']);
		$this->assertSame(0, $report['failed']);
		$this->assertSame(
			[
				['integriq', 'source', 'src-1', ['name' => 'CTI', 'configuration' => ['sharedSecret' => '', 'eventsPath' => '/x', 'authentication' => ['type' => 'apikey', 'keys' => '']]], false, false],
				['integriq', 'rule', 'rule-1', ['name' => 'Rule', 'configuration' => ['authentication' => ['users' => ['', 'bob']]]], false, false],
			],
			$this->saves
		);
	}//end testApplyBlanksOnlyThePlaceholders()

	/**
	 * A failed save is counted and reported, not blanked.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsCounted(): void {
		$report = $this->repair(saveFails: true)->run(apply: true, limit: 1000);

		$this->assertSame(['found' => 2, 'blanked' => 0, 'failed' => 2], array_diff_key($report, ['objects' => 1]));
		$this->assertFalse($report['objects'][0]['blanked']);
	}//end testAFailedSaveIsCounted()

	/**
	 * The command is a dry run unless --apply is given.
	 *
	 * @return void
	 */
	public function testTheCommandIsADryRunByDefault(): void {
		$tester = new CommandTester(new BlankPlaceholderSecrets($this->repair()));
		$this->assertSame(0, $tester->execute([]));
		$this->assertSame([], $this->saves);
		$this->assertStringContainsString('Dry run: 2 object(s)', $tester->getDisplay(true));

		$this->assertSame(0, $tester->execute(['--apply' => true]));
		$this->assertCount(2, $this->saves);

		$this->assertSame(1, $tester->execute(['--limit' => '0']));
	}//end testTheCommandIsADryRunByDefault()

	/**
	 * A failed apply exits non-zero.
	 *
	 * @return void
	 */
	public function testAFailedApplyExitsNonZero(): void {
		$tester = new CommandTester(new BlankPlaceholderSecrets($this->repair(saveFails: true)));
		$this->assertSame(1, $tester->execute(['--apply' => true]));
		$this->assertSame(1, $tester->execute(['--apply' => true, '--json' => true]));
	}//end testAFailedApplyExitsNonZero()

	/**
	 * The command's contract resolves to the repair in the container.
	 *
	 * @return void
	 */
	public function testTheCommandContractIsBoundToTheRepair(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new ServiceBindings())->register(context: $context);

		$this->assertSame(PlaceholderSecretRepair::class, $aliases[PlaceholderSecretRepairInterface::class] ?? null);
	}//end testTheCommandContractIsBoundToTheRepair()
}//end class
