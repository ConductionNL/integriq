<?php

/**
 * Unit tests for RegistrationService: the refusals of design D2 and the record.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registration;

use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\Registration\JsonRegistrationLeg;
use OCA\Integriq\Service\Registration\ObjectsApiRegistrationLeg;
use OCA\Integriq\Service\Registration\RegistrationRefusal;
use OCA\Integriq\Service\Registration\RegistrationService;
use OCA\Integriq\Service\Registration\RegistrationStore;
use OCA\Integriq\Service\Registration\StufZdsRegistrationLeg;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every refusal is an answer with its code and retryable flag; a success is recorded as done.
 */
#[CoversClass(RegistrationService::class)]
#[UsesClass(RegistrationRefusal::class)]
class RegistrationServiceTest extends TestCase {

	/**
	 * The target store.
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
	 * The json leg.
	 *
	 * @var JsonRegistrationLeg&MockObject
	 */
	private JsonRegistrationLeg $json;

	/**
	 * The records saved, as [data, uuid].
	 *
	 * @var array<int,array{0:array<string,mixed>,1:string|null}>
	 */
	private array $saved = [];

	/**
	 * A json target allowing portaliq.
	 *
	 * @var array<string,mixed>
	 */
	private const TARGET = ['slug' => 'json-milieu', 'title' => 'Milieu', 'kind' => 'json', 'mapping' => 'map-1', 'sources' => ['endpoint' => 'src-1'], 'enabled' => true];

	/**
	 * A well-formed hand-over.
	 *
	 * @var array<string,mixed>
	 */
	private const HANDOVER = ['reference' => 'OF-7Q2K', 'answers' => ['onderwerp' => 'milieu'], 'pdf' => ['fileId' => 1234], 'attachments' => [['fileId' => 1235, 'name' => 'kaart.pdf']]];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store    = $this->createMock(RegistrationStore::class);
		$this->mappings = $this->createMock(MappingServiceInterface::class);
		$this->json     = $this->createMock(JsonRegistrationLeg::class);
		$this->json->method('kind')->willReturn('json');
		$this->store->method('saveRecord')->willReturnCallback(function (array $data, ?string $uuid = null): string {
			$this->saved[] = [$data, $uuid];
			return $uuid ?? 'rec-1';
		});
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return RegistrationService
	 */
	private function service(): RegistrationService {
		$objects = $this->createMock(ObjectsApiRegistrationLeg::class);
		$objects->method('kind')->willReturn('objects-api');
		return new RegistrationService($this->store, $this->mappings, $this->json, $objects, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The store answers this target and no record.
	 *
	 * @param array<string,mixed>|null $target The target.
	 *
	 * @return void
	 */
	private function target(?array $target): void {
		$this->store->method('findTarget')->willReturnCallback(static fn (string $slug): ?array => ($target !== null && $slug === $target['slug']) ? $target : null);
	}//end target()

	/**
	 * A success maps the hand-over, writes the record started then done, and answers the outside reference.
	 *
	 * @return void
	 */
	public function testASubmissionIsRegisteredAndRecordedAsDone(): void {
		$this->target(self::TARGET);
		$this->store->expects($this->once())->method('findRecord')->with('json-milieu', 'portaliq', 'OF-7Q2K')->willReturn(null);
		$this->mappings->expects($this->once())->method('executeMapping')->with('map-1', self::HANDOVER)->willReturn(['subject' => 'milieu']);
		$this->json->expects($this->once())->method('register')
			->with(self::TARGET, ['subject' => 'milieu'], self::HANDOVER, [])
			->willReturn(['externalReference' => 'ext-9', 'externalUrl' => 'https://x.example/9', 'steps' => [['step' => 'posted']]]);

		$answer = $this->service()->register(app: 'portaliq', target: 'json-milieu', handover: self::HANDOVER);

		$this->assertSame(['ok' => true, 'externalReference' => 'ext-9', 'externalUrl' => 'https://x.example/9'], $answer);
		$this->assertCount(2, $this->saved);
		$this->assertSame(['started', null], [$this->saved[0][0]['state'], $this->saved[0][1]]);
		$this->assertSame(['json-milieu', 'portaliq', 'OF-7Q2K'], [$this->saved[0][0]['target'], $this->saved[0][0]['app'], $this->saved[0][0]['submissionReference']]);
		$this->assertSame(['done', 'rec-1', 'ext-9', [['step' => 'posted']]], [$this->saved[1][0]['state'], $this->saved[1][1], $this->saved[1][0]['externalReference'], $this->saved[1][0]['steps']]);
	}//end testASubmissionIsRegisteredAndRecordedAsDone()

	/**
	 * A stuf-zds target reaches the stuf-zds leg once it is wired, and its zaak is the reference.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 *
	 * @return void
	 */
	public function testAStufZdsTargetIsRegisteredThroughItsLeg(): void {
		$target = ['kind' => 'stuf-zds', 'sources' => ['zkn' => 'src-zkn']] + self::TARGET;
		$this->target($target);
		$this->store->method('findRecord')->willReturn(null);
		$this->mappings->method('executeMapping')->willReturn(['omschrijving' => 'Milieu']);
		$objects = $this->createMock(ObjectsApiRegistrationLeg::class);
		$objects->method('kind')->willReturn('objects-api');
		$stuf = $this->createMock(StufZdsRegistrationLeg::class);
		$stuf->method('kind')->willReturn('stuf-zds');
		$stuf->expects($this->once())->method('register')
			->with($target, ['omschrijving' => 'Milieu'], self::HANDOVER, [])
			->willReturn(['externalReference' => '0363-Z-1', 'externalUrl' => '', 'steps' => [['step' => 'zaak', 'reference' => '0363-Z-1']]]);
		$this->json->expects($this->never())->method('register');

		$service = new RegistrationService($this->store, $this->mappings, $this->json, $objects, $this->createMock(LoggerInterface::class), $stuf);
		$answer  = $service->register(app: 'portaliq', target: 'json-milieu', handover: self::HANDOVER);

		$this->assertSame(['ok' => true, 'externalReference' => '0363-Z-1', 'externalUrl' => ''], $answer);
	}//end testAStufZdsTargetIsRegisteredThroughItsLeg()

	/**
	 * The refusals that never reach a leg.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>|null,3:array<string,mixed>,4:string}>
	 */
	public static function earlyRefusals(): array {
		$handover = self::HANDOVER;
		return [
			'no such target' => ['portaliq', 'nope', self::TARGET, $handover, 'unknown-target'],
			'disabled target' => ['portaliq', 'json-milieu', ['enabled' => false] + self::TARGET, $handover, 'unknown-target'],
			'app not allowed by default' => ['dossiq', 'json-milieu', self::TARGET, $handover, 'not-allowed'],
			'app not in allowedApps' => ['portaliq', 'json-milieu', ['allowedApps' => ['dossiq']] + self::TARGET, $handover, 'not-allowed'],
			'no reference' => ['portaliq', 'json-milieu', self::TARGET, ['reference' => ' '] + $handover, 'invalid-handover'],
			'no answers' => ['portaliq', 'json-milieu', self::TARGET, ['answers' => 'x'] + $handover, 'invalid-handover'],
			'pdf as bytes' => ['portaliq', 'json-milieu', self::TARGET, ['pdf' => ['content' => 'JVBERi0=']] + $handover, 'invalid-handover'],
			'attachment without id' => ['portaliq', 'json-milieu', self::TARGET, ['attachments' => [['fileId' => 0]]] + $handover, 'invalid-handover'],
			'no mapping' => ['portaliq', 'json-milieu', ['mapping' => ''] + self::TARGET, $handover, 'mapping-failed'],
			'kind without a leg yet' => ['portaliq', 'json-milieu', ['kind' => 'zgw'] + self::TARGET, $handover, 'source-unavailable'],
		];
	}//end earlyRefusals()

	/**
	 * Each early refusal answers its code, is not retryable, and calls no leg and writes no record.
	 *
	 * @param string                   $app      The calling app.
	 * @param string                   $slug     The target slug asked for.
	 * @param array<string,mixed>|null $target   The stored target.
	 * @param array<string,mixed>      $handover The hand-over.
	 * @param string                   $code     The expected refusal.
	 *
	 * @return void
	 */
	#[DataProvider('earlyRefusals')]
	public function testAnEarlyRefusalIsAnAnswerNotAnException(string $app, string $slug, ?array $target, array $handover, string $code): void {
		$this->target($target);
		$this->json->expects($this->never())->method('register');

		$answer = $this->service()->register(app: $app, target: $slug, handover: $handover);

		$this->assertFalse($answer['ok']);
		$this->assertSame($code, $answer['code']);
		$this->assertFalse($answer['retryable']);
		$this->assertSame([], $this->saved);
	}//end testAnEarlyRefusalIsAnAnswerNotAnException()

	/**
	 * A mapping that throws is mapping-failed and not retryable.
	 *
	 * @return void
	 */
	public function testAFailingMappingIsMappingFailed(): void {
		$this->target(self::TARGET);
		$this->mappings->method('executeMapping')->willThrowException(new RuntimeException('Unknown variable'));
		$this->json->expects($this->never())->method('register');

		$answer = $this->service()->register(app: 'portaliq', target: 'json-milieu', handover: self::HANDOVER);

		$this->assertSame(['mapping-failed', false], [$answer['code'], $answer['retryable']]);
		$this->assertStringContainsString('Unknown variable', $answer['message']);
	}//end testAFailingMappingIsMappingFailed()

	/**
	 * A leg's refusal is recorded as failed with the refusal, and answered as it was.
	 *
	 * @return void
	 */
	public function testALegRefusalIsRecordedAsFailed(): void {
		$this->target(self::TARGET);
		$this->mappings->method('executeMapping')->willReturn(['subject' => 'milieu']);
		$this->json->method('register')->willThrowException(RegistrationRefusal::fromStatus(status: 503, detail: 'down'));

		$answer = $this->service()->register(app: 'portaliq', target: 'json-milieu', handover: self::HANDOVER);

		$this->assertSame(['source-unavailable', true, 503], [$answer['code'], $answer['retryable'], $answer['status']]);
		$this->assertSame('failed', $this->saved[1][0]['state']);
		$this->assertSame($answer, $this->saved[1][0]['lastError']);
	}//end testALegRefusalIsRecordedAsFailed()

	/**
	 * An unexpected failure is answered as retryable and never thrown.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsARetryableAnswer(): void {
		$this->store->method('findTarget')->willThrowException(new RuntimeException('database gone'));

		$answer = $this->service()->register(app: 'portaliq', target: 'json-milieu', handover: self::HANDOVER);

		$this->assertSame(['source-unavailable', true], [$answer['code'], $answer['retryable']]);
	}//end testAnUnexpectedFailureIsARetryableAnswer()

	/**
	 * A target without allowedApps allows portaliq only.
	 *
	 * @return void
	 */
	public function testAllowedAppsDefaultToPortaliq(): void {
		$this->assertSame(['portaliq'], RegistrationService::allowedApps(target: []));
		$this->assertSame(['portaliq'], RegistrationService::allowedApps(target: ['allowedApps' => []]));
		$this->assertSame(['dossiq'], RegistrationService::allowedApps(target: ['allowedApps' => ['dossiq', 7]]));
	}//end testAllowedAppsDefaultToPortaliq()
}//end class
