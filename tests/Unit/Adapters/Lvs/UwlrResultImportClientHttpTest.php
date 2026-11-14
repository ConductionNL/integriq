<?php

/**
 * Unit tests: the live UWLR result client against a recorded UWLR batch.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Lvs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Lvs;

use OCA\Integriq\Adapters\Lvs\UwlrResultImportClientHttp;
use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Sources\Lvs\UwlrResultImportSourceAdapter;
use OCA\Integriq\Tests\Unit\Adapters\Support\SupplierCallHarness;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A supplier's batch, read through its source and mapped by the source adapter.
 */
class UwlrResultImportClientHttpTest extends TestCase {
	use SupplierCallHarness;

	/**
	 * The recorded batch reaches the lvs-import-contract payload through the source adapter.
	 *
	 * @return void
	 */
	public function testABatchIsMappedOntoTheImportContract(): void {
		$recorded = $this->recorded(path: 'lvs/fixture-uwlr-result-batch.json');
		$client = new UwlrResultImportClientHttp(calls: $this->supplierCall(slugs: ['lvs-boom'], status: 200, body: (string)json_encode($recorded)));
		$adapter = new UwlrResultImportSourceAdapter(
			uwlrClient: $client,
			config: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$payload = $adapter->importResults(supplierId: 'lvs-boom');

		$this->assertCount(expectedCount: 3, haystack: $payload);
		$this->assertSame(
			expected: [
				'supplierId' => 'lvs-boom',
				'pupilReference' => 'leerling-mock-0001',
				'assessmentCode' => 'BL-M6',
				'referenceLevel' => '1F',
				'proficiencyScore' => 78,
				'administeredOn' => '2026-06-15',
				'groupLabel' => '6',
			],
			actual: $payload[0]
		);
		$this->assertSame(expected: ['lvs-boom', '/uwlr/results', 'GET'], actual: array_slice($this->calls[0], 0, 3));
		$this->assertTrue($this->calls[0][4], 'Pupil results stay out of the call log.');
		$this->assertSame(expected: 'https', actual: $client->flavour());
	}//end testABatchIsMappedOntoTheImportContract()

	/**
	 * A refused credential stops the batch: nothing is returned.
	 *
	 * @return void
	 */
	public function testARefusedCredentialStopsTheBatch(): void {
		$client = new UwlrResultImportClientHttp(calls: $this->supplierCall(slugs: ['lvs-cito-dult'], status: 401, body: ''));

		$this->expectException(SupplierCallException::class);
		$this->expectExceptionMessage('lvs-cito-dult answered /uwlr/results with status 401.');

		$client->fetchResults(supplierId: 'lvs-cito-dult');
	}//end testARefusedCredentialStopsTheBatch()

	/**
	 * Another supplier's batch, or a batch without results, is refused.
	 *
	 * @return void
	 */
	public function testAMisroutedOrEmptyBatchIsRefused(): void {
		$client = new UwlrResultImportClientHttp(calls: $this->supplierCall(slugs: ['lvs-iep'], status: 200, body: '{"supplierId":"lvs-boom","results":[]}'));
		try {
			$client->fetchResults(supplierId: 'lvs-iep');
			$this->fail('A misrouted batch must throw.');
		} catch (SupplierCallException $exception) {
			$this->assertSame(expected: 'lvs-iep answered a batch for lvs-boom; nothing is imported.', actual: $exception->getMessage());
		}

		$client = new UwlrResultImportClientHttp(calls: $this->supplierCall(slugs: ['lvs-iep'], status: 200, body: '{"supplierId":"lvs-iep"}'));
		$this->expectExceptionMessage('lvs-iep answered a batch without results.');
		$client->fetchResults(supplierId: 'lvs-iep');
	}//end testAMisroutedOrEmptyBatchIsRefused()
}//end class
