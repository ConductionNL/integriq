<?php

/**
 * Tests for RegistrationService::dryRun.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registration;

use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\ValidationOutcome;
use OCA\Integriq\Service\Registration\JsonRegistrationLeg;
use OCA\Integriq\Service\Registration\ObjectsApiRegistrationLeg;
use OCA\Integriq\Service\Registration\RegistrationRefusal;
use OCA\Integriq\Service\Registration\RegistrationService;
use OCA\Integriq\Service\Registration\RegistrationStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The administrator's dry run maps and checks a hand-over and stops before any write.
 */
#[CoversClass(RegistrationService::class)]
#[UsesClass(RegistrationRefusal::class)]
#[UsesClass(JsonRegistrationLeg::class)]
#[UsesClass(JsonSchemaChecker::class)]
#[UsesClass(ValidationOutcome::class)]
class RegistrationDryRunTest extends TestCase {

	/**
	 * A json target with a schema, disabled and allowing another app only.
	 *
	 * @var array<string,mixed>
	 */
	private const TARGET = [
		'slug' => 'json-milieu',
		'kind' => 'json',
		'mapping' => 'map-1',
		'enabled' => false,
		'allowedApps' => ['someotherapp'],
		'sources' => ['endpoint' => 'src-1'],
		'json' => ['path' => '/intake', 'schema' => ['type' => 'object', 'required' => ['subject'], 'properties' => ['subject' => ['type' => 'string']]]],
	];

	/**
	 * The target store; it must never write.
	 *
	 * @var RegistrationStore&MockObject
	 */
	private RegistrationStore $store;

	/**
	 * The mapping service.
	 *
	 * @var MappingServiceInterface&MockObject
	 */
	private MappingServiceInterface $mappings;

	/**
	 * The transport the json leg posts through; it must never be called.
	 *
	 * @var CallServiceCaseSystemTransport&MockObject
	 */
	private CallServiceCaseSystemTransport $transport;

	/**
	 * Set up a store with one target and a transport that must stay silent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(RegistrationStore::class);
		$this->store->method('findTarget')->willReturnCallback(static fn (string $slug): ?array => $slug === 'json-milieu' ? self::TARGET : null);
		$this->store->expects($this->never())->method('saveRecord');
		$this->store->expects($this->never())->method('findRecord');

		$this->mappings  = $this->createMock(MappingServiceInterface::class);
		$this->transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$this->transport->expects($this->never())->method('send');
	}//end setUp()

	/**
	 * The service with a real json leg over the silent transport.
	 *
	 * @return RegistrationService
	 */
	private function service(): RegistrationService {
		$objects = $this->createMock(ObjectsApiRegistrationLeg::class);
		$objects->method('kind')->willReturn('objects-api');
		$objects->expects($this->never())->method('register');

		return new RegistrationService(
			$this->store,
			$this->mappings,
			new JsonRegistrationLeg($this->transport, new JsonSchemaChecker()),
			$objects,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A matching body is answered as the mapped body, whatever the enabled flag or allowed apps.
	 *
	 * @return void
	 */
	public function testTheMappedBodyIsAnsweredWithoutAnyWrite(): void {
		$handover = ['reference' => 'OF-1', 'answers' => ['onderwerp' => 'milieu']];
		$this->mappings->expects($this->once())->method('executeMapping')->with('map-1', $handover)->willReturn(['subject' => 'milieu']);

		$answer = $this->service()->dryRun('json-milieu', $handover);

		$this->assertSame(['ok' => true, 'dry' => true, 'kind' => 'json', 'mapped' => ['subject' => 'milieu']], $answer);
	}//end testTheMappedBodyIsAnsweredWithoutAnyWrite()

	/**
	 * Without a hand-over the sample of design D3 is mapped.
	 *
	 * @return void
	 */
	public function testAnEmptyHandoverMapsTheSample(): void {
		$this->mappings->expects($this->once())->method('executeMapping')->with('map-1', RegistrationService::SAMPLE_HANDOVER)->willReturn(['subject' => 'x']);

		$answer = $this->service()->dryRun('json-milieu');

		$this->assertTrue($answer['ok']);
		$this->assertSame(['subject' => 'x'], $answer['mapped']);
	}//end testAnEmptyHandoverMapsTheSample()

	/**
	 * The sample is a well-formed hand-over that names no file.
	 *
	 * @return void
	 */
	public function testTheSampleCarriesNoFiles(): void {
		$this->assertArrayNotHasKey('pdf', RegistrationService::SAMPLE_HANDOVER);
		$this->assertSame([], RegistrationService::SAMPLE_HANDOVER['attachments']);
		$this->assertIsString(RegistrationService::SAMPLE_HANDOVER['reference']);
		$this->assertIsArray(RegistrationService::SAMPLE_HANDOVER['answers']);
	}//end testTheSampleCarriesNoFiles()

	/**
	 * A body the schema refuses is answered as mapping-failed, still without a POST.
	 *
	 * @return void
	 */
	public function testABodyTheSchemaRefusesIsMappingFailed(): void {
		$this->mappings->method('executeMapping')->willReturn(['subject' => 7]);

		$answer = $this->service()->dryRun('json-milieu', ['reference' => 'OF-1', 'answers' => []]);

		$this->assertFalse($answer['ok']);
		$this->assertTrue($answer['dry']);
		$this->assertSame(RegistrationRefusal::MAPPING_FAILED, $answer['code']);
		$this->assertFalse($answer['retryable']);
	}//end testABodyTheSchemaRefusesIsMappingFailed()

	/**
	 * An unknown slug is unknown-target, and the mapping is never run.
	 *
	 * @return void
	 */
	public function testAnUnknownTargetIsRefused(): void {
		$this->mappings->expects($this->never())->method('executeMapping');

		$answer = $this->service()->dryRun('nope');

		$this->assertSame(['dry' => true, 'ok' => false, 'code' => RegistrationRefusal::UNKNOWN_TARGET, 'retryable' => false, 'message' => 'There is no registration target nope.', 'status' => null], $answer);
	}//end testAnUnknownTargetIsRefused()

	/**
	 * A malformed hand-over is invalid-handover before any mapping.
	 *
	 * @return void
	 */
	public function testAMalformedHandoverIsRefusedBeforeMapping(): void {
		$this->mappings->expects($this->never())->method('executeMapping');

		$answer = $this->service()->dryRun('json-milieu', ['answers' => []]);

		$this->assertSame(RegistrationRefusal::INVALID_HANDOVER, $answer['code']);
	}//end testAMalformedHandoverIsRefusedBeforeMapping()

	/**
	 * A mapping that throws is mapping-failed with its message.
	 *
	 * @return void
	 */
	public function testAFailingMappingIsMappingFailed(): void {
		$this->mappings->method('executeMapping')->willThrowException(new RuntimeException('no such field'));

		$answer = $this->service()->dryRun('json-milieu', ['reference' => 'OF-1', 'answers' => []]);

		$this->assertSame(RegistrationRefusal::MAPPING_FAILED, $answer['code']);
		$this->assertStringContainsString('no such field', $answer['message']);
	}//end testAFailingMappingIsMappingFailed()

	/**
	 * A store failure is answered, never thrown.
	 *
	 * @return void
	 */
	public function testAStoreFailureIsAnsweredNotThrown(): void {
		$store = $this->createMock(RegistrationStore::class);
		$store->method('findTarget')->willThrowException(new RuntimeException('register down'));
		$this->store = $store;

		$answer = $this->service()->dryRun('json-milieu');

		$this->assertSame(RegistrationRefusal::SOURCE_UNAVAILABLE, $answer['code']);
		$this->assertTrue($answer['dry']);
		$this->assertStringContainsString('register down', $answer['message']);
	}//end testAStoreFailureIsAnsweredNotThrown()
}//end class
