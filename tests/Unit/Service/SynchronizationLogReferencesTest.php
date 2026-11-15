<?php

/**
 * Characterisation tests for SynchronizationLogService's compaction of the
 * run-log reference lists.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationLogService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins every entry kind `contracts` can hold, the positional pairing with
 * `_embed.contracts`, and the `logs` filter.
 */
final class SynchronizationLogReferencesTest extends TestCase {

	/**
	 * Run the private compaction on a result payload.
	 *
	 * @param array $result The run-log result.
	 *
	 * @return array The compacted result.
	 */
	private function normalise(array $result): array {
		$service = new SynchronizationLogService(
			$this->createMock(OrObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ISession::class)
		);
		$method = new \ReflectionMethod(SynchronizationLogService::class, 'normaliseResultReferences');
		$method->setAccessible(true);

		return $method->invoke($service, $result);

	}//end normalise()

	/**
	 * Objects reduce to their uuid; objects without one, empty strings, nulls
	 * and other scalars are dropped, with their embedded partners.
	 *
	 * @return void
	 */
	public function testContractsReduceToUuidsAndKeepTheirEmbeddedPartners(): void {
		$withUuid = new ObjectEntity();
		$withUuid->setUuid('obj-uuid');
		$withoutUuid = new ObjectEntity();
		$withoutUuid->setUuid('');

		$result = $this->normalise(
			[
				'contracts' => ['k1' => 'uuid-a', 'k2' => $withUuid, 'k3' => $withoutUuid, 'k4' => new \stdClass(), 'k5' => 42, 'k6' => 'uuid-b'],
				'_embed'    => ['contracts' => ['x' => 'e-a', 'y' => 'e-obj', 'z' => 'e-none']],
				'other'     => 'kept',
			]
		);

		$this->assertSame(
			[
				'contracts' => ['uuid-a', 'obj-uuid', 'uuid-b'],
				'_embed'    => ['contracts' => ['e-a', 'e-obj', null]],
				'other'     => 'kept',
			],
			$result
		);

	}//end testContractsReduceToUuidsAndKeepTheirEmbeddedPartners()

	/**
	 * Without an embedded list none is added; non-list values stay untouched;
	 * logs lose only null and '' and are reindexed.
	 *
	 * @return void
	 */
	public function testListsWithoutPartnersAndNonListsAreHandledApart(): void {
		$this->assertSame(
			['contracts' => ['a'], '_embed' => ['contracts' => 'not-a-list'], 'logs' => [0, 'l', false]],
			$this->normalise(['contracts' => [null, 'a'], '_embed' => ['contracts' => 'not-a-list'], 'logs' => [3 => 0, 4 => null, 5 => 'l', 6 => '', 7 => false]])
		);
		$this->assertSame(['contracts' => []], $this->normalise(['contracts' => []]));
		$this->assertSame(['contracts' => 'x', 'logs' => 'y'], $this->normalise(['contracts' => 'x', 'logs' => 'y']));
		$this->assertSame([], $this->normalise([]));

	}//end testListsWithoutPartnersAndNonListsAreHandledApart()

}//end class
