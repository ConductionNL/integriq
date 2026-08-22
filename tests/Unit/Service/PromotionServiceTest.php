<?php

/**
 * Unit tests for PromotionService (environments-and-promotion).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/environments-and-promotion/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConfigurationService;
use OCA\Integriq\Service\EnvironmentService;
use OCA\Integriq\Service\PromotionService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests local export delegation, credentialRef scan/rebind (reference-only,
 * never plaintext), remote dispatch via CallService, merged preview, and the
 * append-only promotion_audit write (success + failure paths).
 */
class PromotionServiceTest extends TestCase {
	/**
	 * @var ConfigurationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $configurationService;

	/**
	 * @var EnvironmentService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $environmentService;

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var PromotionService
	 */
	private PromotionService $service;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->environmentService = $this->createMock(EnvironmentService::class);
		$this->callService = $this->createMock(CallService::class);
		$this->orObjectService = ObjectServiceMockBuilder::make($this);

		$this->service = new PromotionService(
			$this->configurationService,
			$this->environmentService,
			$this->callService,
			$this->orObjectService,
		);
	}//end setUp()

	/**
	 * A document with one Source carrying a TOP-LEVEL proxy credentialRef
	 * placeholder under `configuration.authentication`.
	 *
	 * @param string $slug The Source slug.
	 * @param array $refValue The credentialRef inner value (e.g. `['credentialId' => '...']`).
	 *
	 * @return array<string,mixed>
	 */
	private function documentWithTopLevelPlaceholder(string $slug, array $refValue): array {
		return [
			'components' => [
				'sources' => [
					'api' => [
						[
							'slug' => $slug,
							'name' => 'My API Source',
							'configuration' => [
								'authentication' => ['credentialRef' => $refValue],
							],
						],
					],
				],
			],
		];
	}//end documentWithTopLevelPlaceholder()

	/**
	 * Build a fake CallLog ObjectEntity as {@see CallService::call()} would return.
	 *
	 * @param int $statusCode The HTTP status code.
	 * @param array $body The decoded response body.
	 * @param string $uuid The CallLog uuid.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private function fakeCallLog(int $statusCode, array $body, string $uuid = 'calllog-1') {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'statusCode' => $statusCode,
				'response' => ['body' => json_encode($body)],
			],
			$uuid
		);
	}//end fakeCallLog()

	/**
	 * Task 4 — export() delegates unchanged to ConfigurationService::exportConfiguration().
	 *
	 * @return void
	 */
	public function testExportDelegatesToConfigurationServiceUnchanged(): void {
		$document = ['components' => ['sources' => []]];
		$this->configurationService->expects($this->once())
			->method('exportConfiguration')
			->with('cfg-1')
			->willReturn($document);

		$this->assertSame($document, $this->service->export('cfg-1'));
	}//end testExportDelegatesToConfigurationServiceUnchanged()

	/**
	 * Task 4 — a TOP-LEVEL proxy credentialRef (`authentication` itself is
	 * the placeholder) is flagged with field `configuration.authentication.credentialRef`.
	 *
	 * @return void
	 */
	public function testScanCredentialRefsDetectsTopLevelPlaceholder(): void {
		$document = $this->documentWithTopLevelPlaceholder('my-api-source', ['credentialId' => 'src-env-uuid']);

		$flagged = $this->service->scanCredentialRefs($document);

		$this->assertCount(1, $flagged);
		$this->assertSame('source', $flagged[0]['type']);
		$this->assertSame('my-api-source', $flagged[0]['slug']);
		$this->assertSame('configuration.authentication.credentialRef', $flagged[0]['field']);
	}//end testScanCredentialRefsDetectsTopLevelPlaceholder()

	/**
	 * Task 4 — a NESTED injectable placeholder (e.g. under `apikey`) is
	 * flagged with the field path pointing at its own credentialRef leaf.
	 *
	 * @return void
	 */
	public function testScanCredentialRefsDetectsNestedPlaceholder(): void {
		$document = [
			'components' => [
				'sources' => [
					'api' => [
						[
							'slug' => 'self-hosted-source',
							'configuration' => [
								'authentication' => [
									'apikey' => ['credentialRef' => ['credentialName' => 'prod-api-key']],
								],
							],
						],
					],
				],
			],
		];

		$flagged = $this->service->scanCredentialRefs($document);

		$this->assertCount(1, $flagged);
		$this->assertSame('self-hosted-source', $flagged[0]['slug']);
		$this->assertSame('configuration.authentication.apikey.credentialRef', $flagged[0]['field']);
	}//end testScanCredentialRefsDetectsNestedPlaceholder()

	/**
	 * A Source with no `authentication.credentialRef` at all is never flagged.
	 *
	 * @return void
	 */
	public function testScanCredentialRefsIgnoresPlainAuthentication(): void {
		$document = [
			'components' => [
				'sources' => [
					'api' => [
						['slug' => 'plain-source', 'configuration' => ['authentication' => ['apikey' => 'embedded-value']]],
					],
				],
			],
		];

		$this->assertSame([], $this->service->scanCredentialRefs($document));
	}//end testScanCredentialRefsIgnoresPlainAuthentication()

	/**
	 * Task 5 — an operator-supplied `credentialName` rebinding REPLACES the
	 * reference before the target ever sees the original, and the
	 * replacement is a reference string only (never resolved to plaintext).
	 *
	 * @return void
	 */
	public function testApplyCredentialBindingsRewritesMatchedPlaceholderReferenceOnly(): void {
		$document = $this->documentWithTopLevelPlaceholder('my-api-source', ['credentialId' => 'source-env-uuid']);

		$rewritten = $this->service->applyCredentialBindings(
			$document,
			[['sourceSlug' => 'my-api-source', 'credentialName' => 'prod-api-key']]
		);

		$authentication = $rewritten['components']['sources']['api'][0]['configuration']['authentication'];
		$this->assertSame(['credentialRef' => ['credentialName' => 'prod-api-key']], $authentication);
	}//end testApplyCredentialBindingsRewritesMatchedPlaceholderReferenceOnly()

	/**
	 * Task 5 — a flagged Source with NO corresponding credentialBindings
	 * entry is sent verbatim: not dropped, not defaulted.
	 *
	 * @return void
	 */
	public function testApplyCredentialBindingsLeavesUnmatchedPlaceholderVerbatim(): void {
		$document = $this->documentWithTopLevelPlaceholder('my-api-source', ['credentialId' => 'source-env-uuid']);

		$rewritten = $this->service->applyCredentialBindings($document, []);

		$this->assertSame($document, $rewritten);
	}//end testApplyCredentialBindingsLeavesUnmatchedPlaceholderVerbatim()

	/**
	 * Task 5 regression guard — this class must never IMPORT or CALL the
	 * credential broker's plaintext-resolution surface. Docblocks are
	 * allowed to name `CredentialBrokerService`/`resolveInjectable` in
	 * backticks descriptively (explaining what this class deliberately does
	 * NOT do); the guard is on an actual `use` import or method-call syntax.
	 *
	 * @return void
	 */
	public function testClassNeverImportsOrCallsCredentialBrokerPlaintextResolution(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Service/PromotionService.php');

		$this->assertStringNotContainsString('use OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService', $source);
		$this->assertStringNotContainsString('->resolveInjectable(', $source);
	}//end testClassNeverImportsOrCallsCredentialBrokerPlaintextResolution()

	/**
	 * REQ-001 scenario 2 / REQ-002 — an unresolvable target environment is
	 * rejected BEFORE any export or remote call is attempted.
	 *
	 * @return void
	 */
	public function testResolveTargetSourceThrowsWhenEnvironmentMissing(): void {
		$this->environmentService->method('findBySlug')->willReturn(null);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/does not exist/');

		$this->service->resolveTargetSource('nonexistent');
	}//end testResolveTargetSourceThrowsWhenEnvironmentMissing()

	/**
	 * REQ-001 scenario 2 — an environment whose sourceRef no longer resolves
	 * to a Source is rejected with an actionable error naming the sourceRef.
	 *
	 * @return void
	 */
	public function testResolveTargetSourceThrowsWhenSourceRefDangling(): void {
		$environment = ObjectServiceMockBuilder::objectEntity(
			$this,
			['slug' => 'acceptance', 'sourceRef' => 'dangling-uuid'],
			'env-uuid'
		);
		$this->environmentService->method('findBySlug')->willReturn($environment);
		$this->environmentService->method('resolveSource')->willReturn(null);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/dangling-uuid/');

		$this->service->resolveTargetSource('acceptance');
	}//end testResolveTargetSourceThrowsWhenSourceRefDangling()

	/**
	 * REQ-002 — an unresolvable target never triggers export or a remote
	 * dispatch call.
	 *
	 * @return void
	 */
	public function testPreviewNeverExportsOrDispatchesWhenTargetUnresolved(): void {
		$this->environmentService->method('findBySlug')->willReturn(null);
		$this->configurationService->expects($this->never())->method('exportConfiguration');
		$this->callService->expects($this->never())->method('call');

		$this->expectException(InvalidArgumentException::class);

		$this->service->preview('cfg-1', 'nonexistent', []);
	}//end testPreviewNeverExportsOrDispatchesWhenTargetUnresolved()

	/**
	 * REQ-003 — preview merges the target's own creates/updates/collisions
	 * response with the locally-scanned credentialRefsNeedingRebind bucket.
	 *
	 * @return void
	 */
	public function testPreviewMergesTargetResponseWithCredentialRefsNeedingRebindBucket(): void {
		$environment = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'acceptance', 'sourceRef' => 'source-uuid'], 'env-uuid');
		$source = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Acceptance API', 'location' => 'https://acceptance.example.org'], 'source-uuid');
		$this->environmentService->method('findBySlug')->willReturn($environment);
		$this->environmentService->method('resolveSource')->willReturn($source);

		$document = $this->documentWithTopLevelPlaceholder('my-api-source', ['credentialId' => 'source-env-uuid']);
		$this->configurationService->method('exportConfiguration')->willReturn($document);

		$targetResponse = [
			'creates' => [['type' => 'source', 'slug' => 'new-source']],
			'updates' => [],
			'collisions' => [],
			'unresolvedReferences' => [],
			'credentialsNeedingReentry' => [],
		];
		$this->callService->expects($this->once())
			->method('call')
			->with(
				source: $source,
				endpoint: '/index.php/apps/openconnector/api/configurations/import/preview',
				method: 'POST',
				config: $this->anything()
			)
			->willReturn($this->fakeCallLog(200, $targetResponse));

		$preview = $this->service->preview('cfg-1', 'acceptance', []);

		$this->assertSame($targetResponse['creates'], $preview['creates']);
		$this->assertCount(1, $preview['credentialRefsNeedingRebind']);
		$this->assertSame('my-api-source', $preview['credentialRefsNeedingRebind'][0]['slug']);
		$this->assertFalse($preview['credentialRefsNeedingRebind'][0]['rebound']);
	}//end testPreviewMergesTargetResponseWithCredentialRefsNeedingRebindBucket()

	/**
	 * REQ-006 — a successful promotion writes exactly one promotion_audit
	 * object with outcome success, the dispatch CallLog id, and a
	 * counts-only preview summary.
	 *
	 * @return void
	 */
	public function testPromoteWritesSuccessAuditWithCallLogIdAndCountsOnlySummary(): void {
		$environment = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'acceptance', 'sourceRef' => 'source-uuid'], 'env-uuid');
		$source = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Acceptance API'], 'source-uuid');
		$this->environmentService->method('findBySlug')->willReturn($environment);
		$this->environmentService->method('resolveSource')->willReturn($source);
		$this->configurationService->method('exportConfiguration')->willReturn(['components' => ['sources' => []]]);

		$targetResponse = [
			'creates' => [['type' => 'source', 'slug' => 'new-source']],
			'updates' => [],
			'collisions' => [],
			'written' => ['sources' => ['new-source']],
		];
		$this->callService->method('call')->willReturn($this->fakeCallLog(200, $targetResponse, 'calllog-77'));

		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					static function (array $payload): bool {
						return ($payload['outcome'] ?? null) === 'success'
							&& ($payload['callLogId'] ?? null) === 'calllog-77'
							&& ($payload['fromEnvironmentSlug'] ?? null) === 'local'
							&& ($payload['toEnvironmentSlug'] ?? null) === 'acceptance'
							&& is_array($payload['previewSummary'])
							&& !array_key_exists('entities', $payload['previewSummary']);
					}
				),
				'openconnector',
				'promotion_audit'
			)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'audit-1'));

		$result = $this->service->promote('cfg-1', 'acceptance', [], 'operator-uid');

		$this->assertSame('calllog-77', $result['callLogId']);
		$this->assertSame('audit-1', $result['auditId']);
	}//end testPromoteWritesSuccessAuditWithCallLogIdAndCountsOnlySummary()

	/**
	 * REQ-006 — a failed promotion (target returns 404, e.g. an older
	 * OpenConnector without the import routes) still writes exactly one
	 * promotion_audit object, with outcome failed and no fabricated
	 * written summary, and the failure propagates to the caller.
	 *
	 * @return void
	 */
	public function testPromoteWritesFailedAuditAndRethrowsWhenTargetReturns404(): void {
		$environment = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'acceptance', 'sourceRef' => 'source-uuid'], 'env-uuid');
		$source = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Acceptance API'], 'source-uuid');
		$this->environmentService->method('findBySlug')->willReturn($environment);
		$this->environmentService->method('resolveSource')->willReturn($source);
		$this->configurationService->method('exportConfiguration')->willReturn(['components' => ['sources' => []]]);

		$this->callService->method('call')->willReturn($this->fakeCallLog(404, ['error' => 'Not Found'], 'calllog-404'));

		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					static function (array $payload): bool {
						return ($payload['outcome'] ?? null) === 'failed'
							&& ($payload['previewSummary'] ?? ['x']) === []
							&& ($payload['callLogId'] ?? null) === 'calllog-404';
					}
				),
				'openconnector',
				'promotion_audit'
			)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'audit-2'));

		$this->expectException(RuntimeException::class);

		$this->service->promote('cfg-1', 'acceptance', [], 'operator-uid');
	}//end testPromoteWritesFailedAuditAndRethrowsWhenTargetReturns404()
}//end class
