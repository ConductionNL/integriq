<?php

/**
 * Characterisation test for AuthenticationConfigAuditor::auditAll and auditSource.
 *
 * Pins the whole report over a fleet of sources in every state.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
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

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\AuthenticationConfigAuditor;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the audit report.
 */
class AuthenticationConfigAuditGoldenTest extends TestCase {

	/**
	 * The auditor over the given raw sources.
	 *
	 * @param array<string, array{name: string, raw: array<string, mixed>}> $fleet The sources by uuid.
	 *
	 * @return AuthenticationConfigAuditor
	 */
	private function auditor(array $fleet): AuthenticationConfigAuditor {
		$planner = $this->createMock(InlineSecretMigrationPlanner::class);
		$planner->method('listSourceUuids')->willReturnCallback(
			static fn (int $limit): array => array_slice(array_map(static fn (array $s): string => $s['name'], $fleet), 0, $limit, true)
		);
		$planner->method('readRawSource')->willReturnCallback(
			static fn (string $uuid): array => ($fleet[$uuid]['raw'] ?? [])
		);

		return new AuthenticationConfigAuditor($planner);
	}//end auditor()

	/**
	 * The recorded reports.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$field = AuthenticationConfigAuditor::FIELD;
		$fleet = [
			'u-1' => ['name' => 'holds', 'raw' => ['id' => 1, $field => ['user' => 'svc', 'password' => 'hunter2', 'port' => 443, 'ratio' => 0.5, 'on' => true, 'none' => null, 'list' => [1, 2], 'map' => ['a' => 1]]]],
			'u-2' => ['name' => 'scalar', 'raw' => ['id' => 2, $field => 'token-abc', 'configuration' => ['headers' => ['Authorization' => '{{ source.authenticationConfig.token }}']]]],
			'u-3' => ['name' => 'clear empty', 'raw' => ['id' => 3, $field => [], 'configuration' => '{{ source["authenticationConfig"] }}']],
			'u-4' => ['name' => 'clear absent', 'raw' => ['id' => 4, 'configuration' => ['a' => ['b' => "{{ attribute(source, 'authenticationConfig') }}"], 'c' => 'plain']]],
			'u-5' => ['name' => 'clear blank', 'raw' => ['id' => 5, $field => '']],
			'u-6' => ['name' => 'unreadable', 'raw' => []],
			'7' => ['name' => 'numeric uuid', 'raw' => ['id' => 7, $field => null, 'configuration' => [3 => 'x']]],
		];

		$record = [
			'fleet' => $this->auditor($fleet)->auditAll(),
			'limited' => $this->auditor($fleet)->auditAll(limit: 1),
			'all clear' => $this->auditor(['c' => $fleet['u-5'], 'd' => $fleet['u-3']])->auditAll(),
			'clear but referenced' => $this->auditor(['d' => $fleet['u-3']])->auditAll(),
			'clear and unreferenced' => $this->auditor(['c' => $fleet['u-5']])->auditAll(),
			'unreadable only' => $this->auditor(['e' => $fleet['u-6']])->auditAll(),
			'empty fleet' => $this->auditor([])->auditAll(),
		];

		$path = __DIR__ . '/../../../fixtures/security/authentication-config-audit.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
