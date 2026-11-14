<?php

/**
 * Characterisation test for ObjectenTokenService::load.
 *
 * Pins which token declarations are refused (and why) and, for the ones that
 * load, the allowed and refused verdicts their keys then get.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
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

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the token declarations.
 */
class ObjectenTokenLoadGoldenTest extends TestCase {

	/**
	 * The recorded outcomes.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$registry = new ObjecttypeRegistry();
		$registry->load(
			[
				['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding'],
				['uuid' => 'bbb', 'name' => 'klacht', 'register' => 'klachten', 'schema' => 'klacht'],
			]
		);
		$keys = ['ref-a' => 'key-a', 'ref-b' => 'key-b', 'ref-c' => 'key-c', 'ref-d' => 'key-d', 'ref-e' => 'key-e', 'ref-f' => 'key-f'];
		$service = new ObjectenTokenService(
			objecttypes: $registry,
			credentialRead: static fn (string $reference): ?string => ($keys[$reference] ?? null)
		);

		$declarations = [
			'not an object',
			['credential' => 'ref-a', 'principal' => 'p-a', 'key' => 'pasted', 'permissions' => ['aaa' => 'read_write']],
			['credential' => 'ref-b', 'principal' => 'p-b', 'secret' => 'pasted'],
			['credential' => 'ref-b', 'principal' => 'p-b', 'token' => null, 'secret' => 'x'],
			['credential' => 'ref-b', 'principal' => 'p-b', 'token' => 'pasted'],
			['credential' => '  ', 'principal' => 'p-c'],
			['principal' => 'p-c'],
			['credential' => 'ref-c', 'principal' => ' '],
			['credential' => 'ref-c'],
			['credential' => ' ref-d ', 'principal' => ' p-d ', 'permissions' => ['aaa' => 'read_write', ' bbb ' => ' read ', '' => 'read', 'ccc' => 'write']],
			['credential' => 'ref-e', 'principal' => 'p-e', 'permissions' => 'aaa'],
			['credential' => 'ref-f', 'principal' => 'p-f', 'permissions' => ['bbb' => 'READ']],
			['credential' => 'ref-unknown', 'principal' => 'p-u', 'permissions' => ['aaa' => 'read']],
		];
		$record = ['refusals' => $service->load($declarations), 'verdicts' => []];

		foreach (['key-a', 'key-b', 'key-c', 'key-d', 'key-e', 'key-f', 'key-u'] as $key) {
			foreach (['aaa', 'bbb', 'zzz', ''] as $type) {
				foreach ([false, true] as $writing) {
					$verdict = $service->verdictFor('Token ' . $key, $type, $writing);
					$record['verdicts'][] = sprintf('%s %s %s => %s %d %s', $key, ($type === '' ? '-' : $type), ($writing === true ? 'w' : 'r'), $verdict['verdict'], $verdict['status'], $verdict['principal']);
				}
			}
		}

		$record['second load'] = $service->load([['credential' => 'ref-a', 'principal' => 'p-a2', 'permissions' => ['aaa' => 'read']]]);
		$record['after second load'] = $service->verdictFor('Token key-a', 'aaa', false);

		$path = __DIR__ . '/../../../fixtures/objecten/token-load.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
