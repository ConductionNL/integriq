<?php

/**
 * Both synchronization services read a contract's upsert identity the same way.
 *
 * Pins SynchronizationContractService::contractIdentity() and the private
 * SynchronizationService::contractIdentity() over the same payloads: a uuid
 * wins, a non-numeric id is used, a numeric or empty id is refused, so the
 * shared rule can move without changing which contracts are upserted.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for a contract's upsert identity.
 */
final class ContractIdentityGoldenTest extends TestCase {

	/**
	 * Payloads and the identity each yields, for both services.
	 *
	 * @return array<string, array{0: class-string, 1: array<string, mixed>, 2: ?string}>
	 */
	public static function payloads(): array {
		$cases = [
			'uuid' => [['uuid' => 'u-1', 'id' => 'i-1'], 'u-1'],
			'int uuid' => [['uuid' => 42], '42'],
			'empty uuid, string id' => [['uuid' => '', 'id' => 'i-2'], 'i-2'],
			'null uuid, string id' => [['uuid' => null, 'id' => 'i-3'], 'i-3'],
			'numeric string id' => [['id' => '4438'], null],
			'int id' => [['id' => 4438], null],
			'float id' => [['id' => 1.5], null],
			'empty id' => [['id' => ''], null],
			'nothing' => [[], null],
		];
		$rows = [];
		foreach ([SynchronizationContractService::class, SynchronizationService::class] as $class) {
			foreach ($cases as $name => [$object, $identity]) {
				$rows[$class . ' ' . $name] = [$class, $object, $identity];
			}
		}

		return $rows;

	}//end payloads()

	/**
	 * The identity a payload yields.
	 *
	 * @param class-string         $class    The service.
	 * @param array<string, mixed> $object   The contract payload.
	 * @param string|null          $identity The identity it yields.
	 *
	 * @return void
	 */
	#[DataProvider('payloads')]
	public function testTheIdentityAPayloadYields(string $class, array $object, ?string $identity): void {
		$reflection = new ReflectionClass($class);
		$service = $reflection->newInstanceWithoutConstructor();

		$this->assertSame($identity, $reflection->getMethod('contractIdentity')->invoke($service, $object));

	}//end testTheIdentityAPayloadYields()
}//end class
