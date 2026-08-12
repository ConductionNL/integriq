<?php

/**
 * Unit tests for DSOStatusService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\DSOStatusService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the DSO status push service.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-14
 */
class DSOStatusServiceTest extends TestCase {

	/**
	 * @var DSOStatusService
	 */
	private DSOStatusService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$logger = $this->createMock(LoggerInterface::class);
		$clientService = $this->createMock(IClientService::class);

		$this->service = new DSOStatusService(
			logger: $logger,
			clientService: $clientService
		);

	}//end setUp()

	/**
	 * Test status mapping for known zaak status.
	 *
	 * @return void
	 */
	public function testMapZaakStatusKnownStatuses(): void {
		$this->assertSame('ontvangen', $this->service->mapZaakStatusToDSOStatus(zaakStatus: 'ontvangen'));
		$this->assertSame('in behandeling', $this->service->mapZaakStatusToDSOStatus(zaakStatus: 'in_behandeling'));
		$this->assertSame('besluit genomen', $this->service->mapZaakStatusToDSOStatus(zaakStatus: 'besluit_genomen'));
		$this->assertSame('afgerond', $this->service->mapZaakStatusToDSOStatus(zaakStatus: 'afgerond'));
		$this->assertSame('buiten behandeling', $this->service->mapZaakStatusToDSOStatus(zaakStatus: 'buiten_behandeling'));

	}//end testMapZaakStatusKnownStatuses()

	/**
	 * Test status mapping returns 'onbekend' for unknown zaak status.
	 *
	 * @return void
	 */
	public function testMapZaakStatusUnknownReturnsOnbekend(): void {
		$result = $this->service->mapZaakStatusToDSOStatus(zaakStatus: 'some_unknown_status');

		$this->assertSame('onbekend', $result);

	}//end testMapZaakStatusUnknownReturnsOnbekend()

	/**
	 * Test buildStatusPayload returns correct structure.
	 *
	 * @return void
	 */
	public function testBuildStatusPayloadReturnsCorrectStructure(): void {
		$payload = $this->service->buildStatusPayload(
			verzoekId: 'dso-12345',
			dsoStatus: 'in behandeling'
		);

		$this->assertArrayHasKey('verzoekId', $payload);
		$this->assertArrayHasKey('status', $payload);
		$this->assertArrayHasKey('timestamp', $payload);

		$this->assertSame('dso-12345', $payload['verzoekId']);
		$this->assertSame('in behandeling', $payload['status']);

	}//end testBuildStatusPayloadReturnsCorrectStructure()

	/**
	 * Test buildStatusPayload timestamp is in ISO 8601 format.
	 *
	 * @return void
	 */
	public function testBuildStatusPayloadTimestampIsISO8601(): void {
		$payload = $this->service->buildStatusPayload(
			verzoekId: 'dso-12345',
			dsoStatus: 'ontvangen'
		);

		// ISO 8601 format: 2024-06-15T10:30:00+00:00 or similar.
		$parsed = \DateTime::createFromFormat(\DateTime::ATOM, $payload['timestamp']);
		$this->assertNotFalse($parsed, 'Timestamp is not a valid ISO 8601 date');

	}//end testBuildStatusPayloadTimestampIsISO8601()
}//end class
