<?php

/**
 * Unit tests for RegistrationService: a submission is registered once.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registration;

use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\Registration\JsonRegistrationLeg;
use OCA\Integriq\Service\Registration\ObjectsApiRegistrationLeg;
use OCA\Integriq\Service\Registration\RegistrationService;
use OCA\Integriq\Service\Registration\RegistrationStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A done record answers without an outside call; an unfinished one resumes from its steps.
 */
#[CoversClass(RegistrationService::class)]
class RegistrationResumeTest extends TestCase {

	/**
	 * The service over a store holding this record, and the json leg and mapping given.
	 *
	 * @param array{uuid:string,data:array<string,mixed>} $record   The stored record.
	 * @param JsonRegistrationLeg                          $json     The json leg.
	 * @param MappingServiceInterface                      $mappings The mappings.
	 * @param array<int,array{0:array<string,mixed>,1:string|null}> $saved Receives the saved records.
	 *
	 * @return RegistrationService
	 */
	private function service(array $record, JsonRegistrationLeg $json, MappingServiceInterface $mappings, ?array &$saved = null): RegistrationService {
		$saved = [];
		$store = $this->createMock(RegistrationStore::class);
		$store->method('findTarget')->willReturn(['slug' => 't', 'kind' => 'json', 'mapping' => 'm', 'enabled' => true]);
		$store->method('findRecord')->with('t', 'portaliq', 'OF-1')->willReturn($record);
		$store->method('saveRecord')->willReturnCallback(function (array $data, ?string $uuid = null) use (&$saved): string {
			$saved[] = [$data, $uuid];
			return (string)$uuid;
		});
		$json->method('kind')->willReturn('json');
		$objects = $this->createMock(ObjectsApiRegistrationLeg::class);
		$objects->method('kind')->willReturn('objects-api');

		return new RegistrationService($store, $mappings, $json, $objects, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A repeat after success answers the stored result and calls no mapping and no leg.
	 *
	 * @return void
	 */
	public function testARepeatAfterSuccessMakesNoOutsideCall(): void {
		$json = $this->createMock(JsonRegistrationLeg::class);
		$json->expects($this->never())->method('register');
		$mappings = $this->createMock(MappingServiceInterface::class);
		$mappings->expects($this->never())->method('executeMapping');
		$record = ['uuid' => 'rec-7', 'data' => ['state' => 'done', 'externalReference' => 'ZAAK-1', 'externalUrl' => 'https://z.example/1']];

		$answer = $this->service($record, $json, $mappings, $saved)->register(app: 'portaliq', target: 't', handover: ['reference' => 'OF-1', 'answers' => []]);

		$this->assertSame(['ok' => true, 'externalReference' => 'ZAAK-1', 'externalUrl' => 'https://z.example/1'], $answer);
		$this->assertSame([], $saved);
	}//end testARepeatAfterSuccessMakesNoOutsideCall()

	/**
	 * A repeat of an unfinished registration hands the leg the steps already done and keeps the record and its start.
	 *
	 * @return void
	 */
	public function testAnUnfinishedRegistrationResumesFromItsSteps(): void {
		$steps  = [['step' => 'posted', 'reference' => 'ext-1', 'url' => '']];
		$record = ['uuid' => 'rec-7', 'data' => ['state' => 'failed', 'steps' => $steps, 'startedAt' => '2026-11-01T10:00:00+00:00']];
		$json   = $this->createMock(JsonRegistrationLeg::class);
		$json->expects($this->once())->method('register')->with($this->anything(), ['m' => 1], $this->anything(), $steps)
			->willReturn(['externalReference' => 'ext-1', 'externalUrl' => '', 'steps' => $steps]);
		$mappings = $this->createMock(MappingServiceInterface::class);
		$mappings->method('executeMapping')->willReturn(['m' => 1]);

		$answer = $this->service($record, $json, $mappings, $saved)->register(app: 'portaliq', target: 't', handover: ['reference' => 'OF-1', 'answers' => []]);

		$this->assertSame('ext-1', $answer['externalReference']);
		$this->assertSame(['rec-7', 'rec-7'], [$saved[0][1], $saved[1][1]]);
		$this->assertSame(['started', '2026-11-01T10:00:00+00:00'], [$saved[0][0]['state'], $saved[0][0]['startedAt']]);
		$this->assertSame('done', $saved[1][0]['state']);
	}//end testAnUnfinishedRegistrationResumesFromItsSteps()
}//end class
