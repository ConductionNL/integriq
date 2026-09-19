<?php

/**
 * Unit tests for GroupMappingResolver — the declared directory-to-group mapping
 * and its create-or-refuse setting.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\DirectoryEntry;
use OCA\Integriq\Directory\GroupMappingResolver;
use OCA\Integriq\Exception\DirectorySyncRefusalException;
use OCP\IGroupManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the declared mapping.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
 */
class GroupMappingResolverTest extends TestCase {

	/**
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $groupManager;

	/**
	 * The resolver under test.
	 *
	 * @var GroupMappingResolver
	 */
	private GroupMappingResolver $resolver;

	/**
	 * Build the resolver over a mocked group manager.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				return vsprintf(str_replace(['%1$s', '%2$s', '%3$s'], '%s', $text), $parameters);
			}
		);

		$this->resolver = new GroupMappingResolver(groupManager: $this->groupManager, l10n: $l10n);

	}//end setUp()

	/**
	 * The configuration two directory groups onto one Nextcloud group.
	 *
	 * @return array<string,mixed> The configuration.
	 */
	private function twoOntoOne(): array {
		return [
			'mapping' => [
				'createMissingGroups' => false,
				'rules' => [
					['directoryGroup' => 'OU=Vergunningen', 'group' => 'behandelaars'],
					['directoryGroup' => 'OU=Toezicht', 'group' => 'behandelaars'],
				],
			],
		];
	}//end twoOntoOne()

	/**
	 * Members of either directory group land in the one Nextcloud group.
	 *
	 * @return void
	 */
	public function testTwoDirectoryGroupsResolveOntoOneNextcloudGroup(): void {
		$configuration = $this->twoOntoOne();

		$vergunningen = new DirectoryEntry(userId: 'anja', directoryGroups: ['OU=Vergunningen']);
		$toezicht = new DirectoryEntry(userId: 'bram', directoryGroups: ['OU=Toezicht']);

		$this->assertSame(['behandelaars'], $this->resolver->resolve(entry: $vergunningen, configuration: $configuration));
		$this->assertSame(['behandelaars'], $this->resolver->resolve(entry: $toezicht, configuration: $configuration));
		$this->assertSame(['behandelaars'], $this->resolver->managedGroups(configuration: $configuration));

	}//end testTwoDirectoryGroupsResolveOntoOneNextcloudGroup()

	/**
	 * An attribute rule matches on a directory attribute value.
	 *
	 * @return void
	 */
	public function testAttributeRuleMatchesOnValue(): void {
		$configuration = [
			'mapping' => [
				'rules' => [['attribute' => 'department', 'equals' => 'Toezicht', 'group' => 'toezichthouders']],
			],
		];

		$matching = new DirectoryEntry(userId: 'cees', attributes: ['department' => 'Toezicht']);
		$other = new DirectoryEntry(userId: 'dana', attributes: ['department' => 'Vergunningen']);

		$this->assertSame(['toezichthouders'], $this->resolver->resolve(entry: $matching, configuration: $configuration));
		$this->assertSame([], $this->resolver->resolve(entry: $other, configuration: $configuration));

	}//end testAttributeRuleMatchesOnValue()

	/**
	 * An unknown target group with creation off refuses and names the group.
	 *
	 * The refusal is the whole point of REQ-DS-002: the third behaviour, dropping
	 * the membership quietly, is the one that produces a permission nobody can
	 * explain. The assertion therefore checks the group NAME is in the message,
	 * not merely that something was thrown.
	 *
	 * @return void
	 */
	public function testUnknownTargetGroupRefusesNamingTheGroup(): void {
		$this->groupManager->method('groupExists')->willReturn(false);
		$this->groupManager->expects($this->never())->method('createGroup');

		try {
			$this->resolver->ensureTargets(configuration: $this->twoOntoOne());
			$this->fail('ensureTargets must refuse when a target group does not exist and creation is off');
		} catch (DirectorySyncRefusalException $refusal) {
			$this->assertStringContainsString('behandelaars', $refusal->getMessage());
			$this->assertSame('unknown-target-group', $refusal->getContext()['reason']);
			$this->assertSame('behandelaars', $refusal->getContext()['group']);
		}

	}//end testUnknownTargetGroupRefusesNamingTheGroup()

	/**
	 * With creation on, a missing target group is created.
	 *
	 * @return void
	 */
	public function testMissingTargetGroupIsCreatedWhenCreationIsOn(): void {
		$configuration = $this->twoOntoOne();
		$configuration['mapping']['createMissingGroups'] = true;

		$this->groupManager->method('groupExists')->willReturn(false);
		$this->groupManager->expects($this->once())->method('createGroup')->with('behandelaars');

		$this->assertSame(['behandelaars'], $this->resolver->ensureTargets(configuration: $configuration));

	}//end testMissingTargetGroupIsCreatedWhenCreationIsOn()

	/**
	 * A preview creates nothing, even with creation on.
	 *
	 * @return void
	 */
	public function testPreviewCreatesNoGroup(): void {
		$configuration = $this->twoOntoOne();
		$configuration['mapping']['createMissingGroups'] = true;

		$this->groupManager->method('groupExists')->willReturn(false);
		$this->groupManager->expects($this->never())->method('createGroup');

		$this->assertSame(['behandelaars'], $this->resolver->ensureTargets(configuration: $configuration, dryRun: true));

	}//end testPreviewCreatesNoGroup()
}//end class
