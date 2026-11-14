<?php

/**
 * Unit tests for the regression check on a promotion (REQ-IRT-005).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-the-promotion-preview-shows-failing-cases-before-a-change-goes-live-req-irt-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConfigurationService;
use OCA\Integriq\Service\EnvironmentService;
use OCA\Integriq\Service\PromotionRegressionCheck;
use OCA\Integriq\Service\PromotionService;
use OCA\Integriq\Service\RegressionSuiteService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The preview lists failing cases; a confirmed promotion with failures needs
 * the override, is refused before anything is sent without it, and the audit
 * records the count and the override.
 *
 * @covers \OCA\Integriq\Service\PromotionRegressionCheck
 * @covers \OCA\Integriq\Service\PromotionService
 * @covers \OCA\Integriq\Service\RegressionSuiteService::failures
 */
final class PromotionRegressionCheckTest extends TestCase {

	private const DOCUMENT = [
		'components' => [
			'sources' => [],
			'mappings' => [
				'zaak-naar-jobboard' => ['slug' => 'zaak-naar-jobboard', 'id' => 'map-uuid'],
				'ok-mapping' => ['slug' => 'ok-mapping'],
			],
			'synchronizations' => ['vacatures' => ['slug' => 'vacatures']],
		],
	];

	/**
	 * Every audit written.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $audits = [];

	/**
	 * Every call to the target.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	public function testTheCheckListsEveryFailingCaseOnceUnderTheSubjectItRanFor(): void {
		$failing = $this->check()->failingCases(document: self::DOCUMENT);

		self::assertSame(
			[
				['subjectType' => 'mapping', 'subjectId' => 'zaak-naar-jobboard', 'caseId' => 'case-1', 'caseName' => 'Vacature met salaris'],
				['subjectType' => 'synchronization', 'subjectId' => 'vacatures', 'caseId' => 'case-3', 'caseName' => 'Lege titel'],
			],
			$failing
		);
		self::assertSame([], $this->check()->failingCases(document: ['components' => ['mappings' => 'not a list']]));
	}

	public function testThePreviewListsTheFailingCases(): void {
		$preview = $this->service()->preview('cfg-1', 'acceptance');

		self::assertSame(['case-1', 'case-3'], array_column($preview['failingTestCases'], 'caseId'));
		self::assertSame([['type' => 'mapping', 'slug' => 'zaak-naar-jobboard']], $preview['creates']);
	}

	public function testAPromotionWithFailuresIsRefusedWithoutTheOverrideAndNothingIsSent(): void {
		try {
			$this->service()->promote('cfg-1', 'acceptance', [], 'operator-uid');
			self::fail('The promotion should have been refused.');
		} catch (InvalidArgumentException $e) {
			self::assertStringStartsWith('2 test case(s) of this configuration fail.', $e->getMessage());
		}

		self::assertSame([], $this->calls);
		self::assertCount(1, $this->audits);
		self::assertSame(['failed', 2, false], [$this->audits[0]['outcome'], $this->audits[0]['failedTestCount'], $this->audits[0]['overrideFailingTests']]);
	}

	public function testWithTheOverrideThePromotionGoesAheadAndTheAuditSaysSo(): void {
		$result = $this->service()->promote('cfg-1', 'acceptance', [], 'operator-uid', 'local', true);

		self::assertSame('audit-1', $result['auditId']);
		self::assertSame(['/index.php/apps/integriq/api/configurations/import'], $this->calls);
		self::assertSame(['success', 2, true], [$this->audits[0]['outcome'], $this->audits[0]['failedTestCount'], $this->audits[0]['overrideFailingTests']]);
	}

	public function testAPassingConfigurationPromotesWithTheAuditItAlwaysHad(): void {
		$service = $this->service(document: ['components' => ['mappings' => ['ok-mapping' => ['slug' => 'ok-mapping']]]]);

		$service->promote('cfg-1', 'acceptance', [], 'operator-uid');

		self::assertCount(1, $this->calls);
		self::assertSame('success', $this->audits[0]['outcome']);
		self::assertArrayNotHasKey('failedTestCount', $this->audits[0]);
		self::assertArrayNotHasKey('overrideFailingTests', $this->audits[0]);
	}

	/**
	 * The check over a suite double: two cases fail on the job-board mapping
	 * (one asked by slug and by id), one on the synchronization.
	 *
	 * @return PromotionRegressionCheck
	 */
	private function check(): PromotionRegressionCheck {
		$suites = $this->createMock(RegressionSuiteService::class);
		$suites->method('failures')->willReturnCallback(
			static fn (string $subjectType, string $subjectId): array => match ($subjectType . ':' . $subjectId) {
				'mapping:zaak-naar-jobboard', 'mapping:map-uuid' => [['caseId' => 'case-1', 'caseName' => 'Vacature met salaris', 'differences' => [['path' => 'salary']]]],
				'synchronization:vacatures' => [['caseId' => 'case-3', 'caseName' => 'Lege titel', 'error' => 'boom']],
				default => [],
			}
		);

		return new PromotionRegressionCheck($suites);
	}

	/**
	 * The promotion service with the check, a target that answers, and audits captured.
	 *
	 * @param array<string,mixed> $document The exported configuration.
	 *
	 * @return PromotionService
	 */
	private function service(array $document = self::DOCUMENT): PromotionService {
		$environments = $this->createMock(EnvironmentService::class);
		$environments->method('findBySlug')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'acceptance', 'sourceRef' => 'source-uuid'], 'env-uuid'));
		$environments->method('resolveSource')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Acceptance'], 'source-uuid'));
		$configurations = $this->createMock(ConfigurationService::class);
		$configurations->method('exportConfiguration')->willReturn($document);

		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint) {
				$this->calls[] = $endpoint;
				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200, 'response' => ['body' => json_encode(['creates' => [['type' => 'mapping', 'slug' => 'zaak-naar-jobboard']], 'updates' => [], 'collisions' => []])]], 'calllog-1');
			}
		);

		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->audits[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'audit-' . count($this->audits));
			}
		);

		return new PromotionService($configurations, $environments, $calls, $objects, $this->check());
	}
}
