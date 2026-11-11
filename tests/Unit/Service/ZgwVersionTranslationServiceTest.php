<?php

/**
 * Unit tests for ZgwVersionTranslationService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/zgw-version-translation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\ZgwLiteralLeakException;
use OCA\Integriq\Exception\ZgwUnknownResourceException;
use OCA\Integriq\Exception\ZgwUnknownVersionException;
use OCA\Integriq\Service\ZgwVersion\CaseTranslator;
use OCA\Integriq\Service\ZgwVersion\CaseTypeTranslator;
use OCA\Integriq\Service\ZgwVersion\DecisionTranslator;
use OCA\Integriq\Service\ZgwVersion\InformatieObjectTranslator;
use OCA\Integriq\Service\ZgwVersion\ResultTranslator;
use OCA\Integriq\Service\ZgwVersion\RolTranslator;
use OCA\Integriq\Service\ZgwVersion\StatusTranslator;
use OCA\Integriq\Service\ZgwVersionNegotiationService;
use OCA\Integriq\Service\ZgwVersionTranslationService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for resource+version resolution, translator dispatch, passthrough,
 * and translation-log persistence.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-persistence-and-observability-zgw_version_translation_log-req-004
 */
class ZgwVersionTranslationServiceTest extends TestCase {

	/**
	 * The golden record, taken before translate() was split into steps.
	 *
	 * @var string
	 */
	private const GOLDEN_TRANSLATIONS = '{"outcomes":[{"returned":{"url":"https:\\/\\/host\\/api\\/zgw\\/zaken\\/v1\\/statussen\\/st","uuid":"st","zaak":"https:\\/\\/host\\/api\\/zgw\\/zaken\\/v1\\/zaken\\/abc","statustype":"https:\\/\\/host\\/api\\/zgw\\/catalogi\\/v1\\/statustypen\\/stt","datumStatusGezet":"2026-01-01T00:00:00+00:00"}},{"returned":{"url":"https:\\/\\/host\\/api\\/zgw\\/zaken\\/v1\\/statussen\\/st","uuid":"st","zaak":"https:\\/\\/host\\/api\\/zgw\\/zaken\\/v1\\/zaken\\/abc","statustype":"https:\\/\\/host\\/api\\/zgw\\/catalogi\\/v1\\/statustypen\\/stt","datumStatusGezet":"2026-01-01T00:00:00+00:00"}},{"returned":{"url":"https:\\/\\/host\\/api\\/zgw\\/zaken\\/v1\\/statussen\\/st","uuid":"st","zaak":"https:\\/\\/host\\/api\\/zgw\\/zaken\\/v1\\/zaken\\/abc","statustype":"https:\\/\\/host\\/api\\/zgw\\/catalogi\\/v1\\/statustypen\\/stt","datumStatusGezet":"2026-01-01T00:00:00+00:00"}},{"threw":"OCA\\\\Integriq\\\\Exception\\\\ZgwVersionNotImplementedException","message":"ZGW version \\"2.0\\" is recognised as the next-generation placeholder but has no stable OAS to translate against yet \\u2014 see design.md Open Question #1. Not a silent omission."},{"threw":"OCA\\\\Integriq\\\\Exception\\\\ZgwUnknownVersionException","message":"Unknown ZGW version \\"9.9\\" \\u2014 recognised versions are: 1.0, 1.6, 2.0."},{"threw":"OCA\\\\Integriq\\\\Exception\\\\ZgwUnknownVersionException","message":"Unknown ZGW version \\"9.9\\" \\u2014 recognised versions are: 1.0, 1.6, 2.0."},{"threw":"OCA\\\\Integriq\\\\Exception\\\\ZgwUnknownVersionException","message":"Unknown ZGW version \\"9.9\\" \\u2014 recognised versions are: 1.0, 1.6, 2.0."},{"threw":"OCA\\\\Integriq\\\\Exception\\\\ZgwUnknownResourceException","message":"Unknown ZGW resource \\"nope\\" \\u2014 supported resources are: zaak, zaaktype, enkelvoudiginformatieobject, besluit, rol, status, resultaat."},{"threw":"OCA\\\\Integriq\\\\Exception\\\\ZgwLiteralLeakException","message":"Resource \\"status\\" is missing required field \\"url\\"."}],"saved":[{"resource":"status","fromVersion":"1.0","toVersion":"1.0","status":"passthrough","error":null,"translatedAt":true},{"resource":"status","fromVersion":"1.0","toVersion":"1.6","status":"success","error":null,"translatedAt":true},{"resource":"status","fromVersion":"1.6","toVersion":"1.0","status":"success","error":null,"translatedAt":true},{"resource":"status","fromVersion":"1.0","toVersion":"1.6","status":"failed","error":"Resource \\"status\\" is missing required field \\"url\\".","translatedAt":true}]}';

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var ZgwVersionTranslationService
	 */
	private ZgwVersionTranslationService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ORObjectService::class);

		$this->service = new ZgwVersionTranslationService(
			negotiationService: new ZgwVersionNegotiationService(),
			objectService: $this->objectService,
			caseTranslator: new CaseTranslator(),
			caseTypeTranslator: new CaseTypeTranslator(),
			informatieObjectTranslator: new InformatieObjectTranslator(),
			decisionTranslator: new DecisionTranslator(),
			roleTranslator: new RolTranslator(),
			statusTranslator: new StatusTranslator(),
			resultTranslator: new ResultTranslator()
		);
	}//end setUp()

	/**
	 * A conformant `1.0` status fixture (the simplest resource — no special-case logic).
	 *
	 * @return array<string, mixed>
	 */
	private function conformantStatusPayload(): array {
		return [
			'url' => 'https://host/api/zgw/zaken/v1/statussen/st',
			'uuid' => 'st',
			'zaak' => 'https://host/api/zgw/zaken/v1/zaken/abc',
			'statustype' => 'https://host/api/zgw/catalogi/v1/statustypen/stt',
			'datumStatusGezet' => '2026-01-01T00:00:00+00:00',
		];
	}//end conformantStatusPayload()

	/**
	 * @return void
	 */
	public function testGetSupportedResourcesListsAllSeven(): void {
		$resources = $this->service->getSupportedResources();
		sort($resources);

		$this->assertSame(
			expected: [
				'besluit',
				'enkelvoudiginformatieobject',
				'resultaat',
				'rol',
				'status',
				'zaak',
				'zaaktype',
			],
			actual: $resources
		);
	}//end testGetSupportedResourcesListsAllSeven()

	/**
	 * @return void
	 */
	public function testSameVersionIsPassthroughAndSkipsTranslator(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->anything(),
				$this->anything(),
				$this->callback(
					static fn (string $schema): bool => $schema === ZgwVersionTranslationService::SCHEMA_LOG
				)
			);

		$payload = $this->conformantStatusPayload();
		$translated = $this->service->translate(
			resource: 'status',
			fromVersion: '1.0',
			toVersion: '1.0',
			payload: $payload
		);

		$this->assertSame(expected: $payload, actual: $translated);
	}//end testSameVersionIsPassthroughAndSkipsTranslator()

	/**
	 * @return void
	 */
	public function testSuccessfulTranslationPersistsSuccessLog(): void {
		$this->objectService->expects($this->once())->method('saveObject');

		$translated = $this->service->translate(
			resource: 'status',
			fromVersion: '1.0',
			toVersion: '1.6',
			payload: $this->conformantStatusPayload()
		);

		$this->assertSame(expected: $this->conformantStatusPayload(), actual: $translated);
	}//end testSuccessfulTranslationPersistsSuccessLog()

	/**
	 * @return void
	 */
	public function testUnknownResourceThrowsBeforeAnyPersistence(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(ZgwUnknownResourceException::class);
		$this->service->translate(
			resource: 'besluittype',
			fromVersion: '1.0',
			toVersion: '1.6',
			payload: []
		);
	}//end testUnknownResourceThrowsBeforeAnyPersistence()

	/**
	 * @return void
	 */
	public function testUnknownVersionThrowsBeforeAnyPersistence(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(ZgwUnknownVersionException::class);
		$this->service->translate(
			resource: 'status',
			fromVersion: '1.0',
			toVersion: '0.9',
			payload: []
		);
	}//end testUnknownVersionThrowsBeforeAnyPersistence()

	/**
	 * @return void
	 */
	public function testLiteralLeakFailurePersistsFailedLogAndRethrows(): void {
		$this->objectService->expects($this->once())->method('saveObject');

		$payload = $this->conformantStatusPayload();
		unset($payload['statustype']);

		$this->expectException(ZgwLiteralLeakException::class);
		$this->service->translate(
			resource: 'status',
			fromVersion: '1.0',
			toVersion: '1.6',
			payload: $payload
		);
	}//end testLiteralLeakFailurePersistsFailedLogAndRethrows()

	/**
	 * GOLDEN: every path of translate() across resources and version pairs,
	 * pinned as the returned payload or the exception class and message, and
	 * every log record saved (its time only as present).
	 *
	 * @return void
	 */
	public function testGoldenTranslationsAndLogs(): void {
		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$saved) {
				$record = $args[0];
				$record['translatedAt'] = (isset($record['translatedAt']) === true);
				$saved[] = $record;
				return new \OCA\OpenRegister\Db\ObjectEntity();
			}
		);

		$payload = $this->conformantStatusPayload();
		$cases = [
			['status', '1.0', '1.0', $payload],
			['status', '1.0', '1.6', $payload],
			['status', '1.6', '1.0', $payload],
			['status', '1.0', '2.0', $payload],
			['status', '9.9', '1.0', $payload],
			['status', '1.0', '9.9', $payload],
			['nope', '9.9', '1.0', $payload],
			['nope', '1.0', '1.6', $payload],
			['status', '1.0', '1.6', ['uuid' => 'broken']],
		];
		$outcomes = [];
		foreach ($cases as [$resource, $from, $to, $body]) {
			try {
				$outcomes[] = ['returned' => $this->service->translate($resource, $from, $to, $body)];
			} catch (\Throwable $exception) {
				$outcomes[] = ['threw' => get_class($exception), 'message' => $exception->getMessage()];
			}
		}

		$this->assertSame(self::GOLDEN_TRANSLATIONS, json_encode(['outcomes' => $outcomes, 'saved' => $saved]));
	}//end testGoldenTranslationsAndLogs()


	/**
	 * A 1.0 -> 1.6 request runs the upgrade, a 1.6 -> 1.0 request the downgrade.
	 *
	 * @return void
	 */
	public function testEachDirectionRunsItsOwnTranslation(): void {
		$status = $this->createMock(StatusTranslator::class);
		$status->method('getResource')->willReturn('status');
		$status->method('translateToV16')->willReturn(['ran' => 'to 1.6']);
		$status->method('translateToV1x')->willReturn(['ran' => 'to 1.x']);
		$this->objectService->method('saveObject')->willReturn(new \OCA\OpenRegister\Db\ObjectEntity());

		$service = new ZgwVersionTranslationService(
			negotiationService: new ZgwVersionNegotiationService(),
			objectService: $this->objectService,
			caseTranslator: new CaseTranslator(),
			caseTypeTranslator: new CaseTypeTranslator(),
			informatieObjectTranslator: new InformatieObjectTranslator(),
			decisionTranslator: new DecisionTranslator(),
			roleTranslator: new RolTranslator(),
			statusTranslator: $status,
			resultTranslator: new ResultTranslator()
		);

		$this->assertSame(['ran' => 'to 1.6'], $service->translate('status', '1.0', '1.6', []));
		$this->assertSame(['ran' => 'to 1.x'], $service->translate('status', '1.6', '1.0', []));
	}//end testEachDirectionRunsItsOwnTranslation()

}//end class
