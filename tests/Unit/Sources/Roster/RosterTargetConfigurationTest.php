<?php

/**
 * Tests for RosterTargetConfiguration.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-a-per-source-target-configuration-links-school-codes-to-fleet-ids-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Roster;

use OCA\Integriq\Sources\Roster\RosterTargetConfiguration;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The target is planninq and the code maps merge stored and delivered entries.
 */
class RosterTargetConfigurationTest extends TestCase {
	/**
	 * Build the configuration over stored app config values.
	 *
	 * @param array<string,string> $stored Stored values by key.
	 * @param LoggerInterface|null $logger Logger double.
	 *
	 * @return RosterTargetConfiguration
	 */
	private function configuration(array $stored, ?LoggerInterface $logger = null): RosterTargetConfiguration {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);

		return new RosterTargetConfiguration($config, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end configuration()

	/**
	 * A delivery's entries win over the stored map; other stored entries stay.
	 *
	 * @return void
	 */
	public function testDeliveryMapWinsOverTheStoredOne(): void {
		$configuration = $this->configuration(['roster.roster-zermelo.group_map' => '{"3a":"old","3b":"b"}']);

		$resolved = $configuration->forSystem('roster-zermelo', ['groupMap' => ['3a' => 'new']]);

		$this->assertSame('planninq', $resolved['target']);
		$this->assertSame('new', $resolved['groupMap']['3a']);
		$this->assertSame('b', $resolved['groupMap']['3b']);
		$this->assertSame([], $resolved['teacherMap']);
	}//end testDeliveryMapWinsOverTheStoredOne()

	/**
	 * Numeric school codes keep pointing at their own cohort.
	 *
	 * @return void
	 */
	public function testNumericCodesAreNotRenumbered(): void {
		$configuration = $this->configuration(['roster.roster-untis-oneroster.group_map' => '{"1024":"c-1024","7":"c-7"}']);

		$resolved = $configuration->forSystem('roster-untis-oneroster', ['groupMap' => ['9' => 'c-9']]);

		$this->assertSame('c-1024', $resolved['groupMap']['1024']);
		$this->assertSame('c-7', $resolved['groupMap']['7']);
		$this->assertSame('c-9', $resolved['groupMap']['9']);
	}//end testNumericCodesAreNotRenumbered()

	/**
	 * An unreadable stored map counts as empty and is logged; junk entries are dropped.
	 *
	 * @return void
	 */
	public function testUnreadableMapIsEmptyAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('roster-target.unreadable-map');
		$configuration = $this->configuration(['roster.roster-xedule.teacher_map' => 'not json'], $logger);

		$resolved = $configuration->forSystem('roster-xedule', ['groupMap' => ['' => 'x', 'ok' => ['nested']], 'teacherMap' => 'nope']);

		$this->assertSame([], $resolved['teacherMap']);
		$this->assertSame([], $resolved['groupMap']);
	}//end testUnreadableMapIsEmptyAndLogged()
}//end class
