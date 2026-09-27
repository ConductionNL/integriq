<?php

/**
 * Tests for the dormant rostering source adapter, now targeting planninq.
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
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Roster;

use InvalidArgumentException;
use OCA\Integriq\Adapters\Roster\RosterImportClientMock;
use OCA\Integriq\Sources\Roster\RosterImportSourceAdapter;
use OCA\Integriq\Sources\Roster\RosterMappingPresetRegistry;
use OCA\Integriq\Sources\Roster\RosterSessionMapper;
use OCA\Integriq\Sources\Roster\RosterTargetConfiguration;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every mock source maps onto planninq timetable sessions.
 */
class RosterImportSourceAdapterTest extends TestCase {
	/**
	 * Build the adapter over the real presets, mapper and configuration.
	 *
	 * @param array<string,string> $stored Stored app config values.
	 * @param LoggerInterface|null $logger Logger double.
	 *
	 * @return RosterImportSourceAdapter
	 */
	private function adapter(array $stored = [], ?LoggerInterface $logger = null): RosterImportSourceAdapter {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$logger ??= $this->createMock(LoggerInterface::class);

		return new RosterImportSourceAdapter(
			$config,
			$logger,
			new RosterImportClientMock(),
			new RosterMappingPresetRegistry(),
			new RosterSessionMapper(),
			new RosterTargetConfiguration($config, $logger)
		);
	}//end adapter()

	/**
	 * The feature flag defaults to off.
	 *
	 * @return void
	 */
	public function testIsActiveDefaultsToFalse(): void {
		$this->assertFalse($this->adapter()->isActive());
		$this->assertTrue($this->adapter(['roster.import.feature_flag' => '1'])->isActive());
		$this->assertSame('mock', $this->adapter()->flavour());
	}//end testIsActiveDefaultsToFalse()

	/**
	 * Each of the four sources maps onto planninq's session shape; the former
	 * learniq payload names never appear.
	 *
	 * @return void
	 */
	public function testEverySourceMapsOntoPlanninqSessions(): void {
		$adapter = $this->adapter();

		foreach (['roster-zermelo', 'roster-untis-oneroster', 'roster-xedule', 'roster-timeedit'] as $systemId) {
			$sessions = $adapter->importLessons($systemId);
			$this->assertCount(2, $sessions, $systemId);
			foreach ($sessions as $session) {
				foreach (['externalRef', 'subject', 'startsAt', 'endsAt', 'groupReference', 'teacherReference', 'roomReference', 'status'] as $field) {
					$this->assertArrayHasKey($field, $session, "{$systemId} must yield {$field}");
				}

				$this->assertNotFalse(strtotime($session['startsAt']), $systemId);
				$this->assertLessThan(strtotime($session['endsAt']), strtotime($session['startsAt']), $systemId);
				$this->assertArrayNotHasKey('startTime', $session);
				$this->assertArrayNotHasKey('endTime', $session);
				$this->assertArrayNotHasKey('systemId', $session);
			}

			$this->assertSame(['scheduled', 'cancelled'], array_column($sessions, 'status'), "{$systemId}: second lesson is cancelled");
		}
	}//end testEverySourceMapsOntoPlanninqSessions()

	/**
	 * Stored and delivered code maps resolve fleet ids.
	 *
	 * @return void
	 */
	public function testCodeMapsResolveFleetIds(): void {
		$adapter = $this->adapter(['roster.roster-zermelo.teacher_map' => '{"JAN":"jan.devries"}']);

		$sessions = $adapter->importLessons('roster-zermelo', ['groupMap' => ['3a' => 'cohort-3a']]);

		$this->assertSame('cohort-3a', $sessions[0]['cohortId']);
		$this->assertSame('jan.devries', $sessions[0]['teacherUserId']);
		$this->assertArrayNotHasKey('teacherUserId', $sessions[1], 'PIE has no mapping');
	}//end testCodeMapsResolveFleetIds()

	/**
	 * A summary is logged at debug with the target named.
	 *
	 * @return void
	 */
	public function testImportLessonsLogsSummary(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with(
				'roster-import.importLessons',
				$this->callback(
					static fn (array $context): bool => $context['source'] === 'roster-timeedit'
						&& $context['target'] === 'planninq'
						&& $context['recordCount'] === 2
						&& $context['flavour'] === 'mock'
						&& $context['active'] === false
				)
			);

		$this->adapter([], $logger)->importLessons('roster-timeedit');
	}//end testImportLessonsLogsSummary()

	/**
	 * An unknown source has no preset.
	 *
	 * @return void
	 */
	public function testUnknownSourceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->adapter()->importLessons('roster-unknown');
	}//end testUnknownSourceIsRefused()
}//end class
