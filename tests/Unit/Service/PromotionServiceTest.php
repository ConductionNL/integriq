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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

	private const GOLDEN_SCAN = '[{"type":"source","slug":"top","field":"configuration.authentication.credentialRef"},{"type":"source","slug":"deep","field":"configuration.authentication.apikey.credentialRef"},{"type":"source","slug":"deep","field":"configuration.authentication.oauth.client.secret.credentialRef"},{"type":"source","slug":"unbound","field":"configuration.authentication.password.credentialRef"}]';

	private const GOLDEN_REBOUND = '{"components":{"sources":{"api":["not-a-source",{"slug":"no-auth","configuration":[]},{"slug":"top","configuration":{"authentication":{"credentialRef":{"credentialId":"old-top"}}}},{"slug":"deep","configuration":{"authentication":{"apikey":{"credentialRef":{"credentialName":"new-default"}},"header":"X-Api-Key","oauth":{"client":{"secret":{"credentialRef":{"credentialId":"new-secret"}}},"scopes":["read","write"]}}}}],"broken":"not-a-group","sftp":[{"slug":"unbound","configuration":{"authentication":{"password":{"credentialRef":{"credentialId":"old-pw"}}}}}]}}}';

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
		foreach (['PromotionService.php', 'SourceCredentialRefs.php'] as $file) {
			$source = (string)file_get_contents(__DIR__ . '/../../../lib/Service/' . $file);

			$this->assertStringNotContainsString('use OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService', $source);
			$this->assertStringNotContainsString('->resolveInjectable(', $source);
		}
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
				endpoint: '/index.php/apps/integriq/api/configurations/import/preview',
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
				'integriq',
				'promotion_audit'
			)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'audit-1'));

		$result = $this->service->promote('cfg-1', 'acceptance', [], 'operator-uid');

		$this->assertSame('calllog-77', $result['callLogId']);
		$this->assertSame('audit-1', $result['auditId']);
	}//end testPromoteWritesSuccessAuditWithCallLogIdAndCountsOnlySummary()

	/**
	 * The golden promotions, taken before promote() was split.
	 *
	 * @var string
	 */
	private const GOLDEN_PROMOTIONS = '{"success rebound":{"returned":{"creates":[{"slug":"a"}],"updates":[{"slug":"b"},{"slug":"c"}],"written":{"sources":["a"]},"auditId":"audit-2","callLogId":"calllog-200"},"seen":[["call","\\/index.php\\/apps\\/integriq\\/api\\/configurations\\/import","POST",{"json":{"document":{"components":{"sources":{"api":[{"slug":"my-api-source","name":"My API Source","configuration":{"authentication":{"credentialRef":{"credentialId":"new-id"}}}}]}}},"confirmed":true}}],["audit",{"actorUid":"operator-uid","configurationId":"cfg-1","fromEnvironmentSlug":"local","toEnvironmentSlug":"acceptance","startedAt":true,"completedAt":true,"outcome":"success","message":"","previewSummary":{"creates":1,"updates":2,"collisions":0,"written":{"sources":["a"]}},"credentialRebindCount":1,"callLogId":"calllog-200"},["integriq","promotion_audit"]]]},"success unbound":{"returned":{"creates":[],"auditId":"audit-2","callLogId":"calllog-201"},"seen":[["call","\\/index.php\\/apps\\/integriq\\/api\\/configurations\\/import","POST",{"json":{"document":{"components":{"sources":{"api":[{"slug":"my-api-source","name":"My API Source","configuration":{"authentication":{"credentialRef":{"credentialId":"old-id"}}}}]}}},"confirmed":true}}],["audit",{"actorUid":"operator-uid","configurationId":"cfg-1","fromEnvironmentSlug":"local","toEnvironmentSlug":"acceptance","startedAt":true,"completedAt":true,"outcome":"success","message":"","previewSummary":{"creates":0,"updates":0,"collisions":0,"written":[]},"credentialRebindCount":0,"callLogId":"calllog-201"},["integriq","promotion_audit"]]]},"success from test":{"returned":{"creates":[{"slug":"a"}],"updates":[{"slug":"b"},{"slug":"c"}],"written":{"sources":["a"]},"auditId":"audit-2","callLogId":"calllog-200"},"seen":[["call","\\/index.php\\/apps\\/integriq\\/api\\/configurations\\/import","POST",{"json":{"document":{"components":[]},"confirmed":true}}],["audit",{"actorUid":"operator-uid","configurationId":"cfg-1","fromEnvironmentSlug":"test","toEnvironmentSlug":"acceptance","startedAt":true,"completedAt":true,"outcome":"success","message":"","previewSummary":{"creates":1,"updates":2,"collisions":0,"written":{"sources":["a"]}},"credentialRebindCount":0,"callLogId":"calllog-200"},["integriq","promotion_audit"]]]},"unknown target":{"threw":"InvalidArgumentException","message":"Target environment \'nowhere\' does not exist.","seen":[["audit",{"actorUid":"operator-uid","configurationId":"cfg-1","fromEnvironmentSlug":"local","toEnvironmentSlug":"nowhere","startedAt":true,"completedAt":true,"outcome":"failed","message":"Target environment \'nowhere\' does not exist.","previewSummary":[],"credentialRebindCount":0,"callLogId":""},["integriq","promotion_audit"]]]},"export fails":{"threw":"RuntimeException","message":"export broke","seen":[["audit",{"actorUid":"operator-uid","configurationId":"cfg-1","fromEnvironmentSlug":"local","toEnvironmentSlug":"acceptance","startedAt":true,"completedAt":true,"outcome":"failed","message":"export broke","previewSummary":[],"credentialRebindCount":0,"callLogId":""},["integriq","promotion_audit"]]]},"call fails":{"threw":"RuntimeException","message":"target unreachable","seen":[["call","\\/index.php\\/apps\\/integriq\\/api\\/configurations\\/import","POST",{"json":{"document":{"components":{"sources":{"api":[{"slug":"my-api-source","name":"My API Source","configuration":{"authentication":{"credentialRef":{"credentialId":"new-id"}}}}]}}},"confirmed":true}}],["audit",{"actorUid":"operator-uid","configurationId":"cfg-1","fromEnvironmentSlug":"local","toEnvironmentSlug":"acceptance","startedAt":true,"completedAt":true,"outcome":"failed","message":"target unreachable","previewSummary":[],"credentialRebindCount":0,"callLogId":""},["integriq","promotion_audit"]]]},"target refuses":{"threw":"RuntimeException","message":"Target environment call failed with HTTP 409: collision","seen":[["call","\\/index.php\\/apps\\/integriq\\/api\\/configurations\\/import","POST",{"json":{"document":{"components":{"sources":{"api":[{"slug":"my-api-source","name":"My API Source","configuration":{"authentication":{"credentialRef":{"credentialId":"new-id"}}}}]}}},"confirmed":true}}],["audit",{"actorUid":"operator-uid","configurationId":"cfg-1","fromEnvironmentSlug":"local","toEnvironmentSlug":"acceptance","startedAt":true,"completedAt":true,"outcome":"failed","message":"Target environment call failed with HTTP 409: collision","previewSummary":[],"credentialRebindCount":0,"callLogId":"calllog-409"},["integriq","promotion_audit"]]]},"unreadable answer":{"threw":"RuntimeException","message":"Target environment returned a non-JSON response.","seen":[["call","\\/index.php\\/apps\\/integriq\\/api\\/configurations\\/import","POST",{"json":{"document":{"components":{"sources":{"api":[{"slug":"my-api-source","name":"My API Source","configuration":{"authentication":{"credentialRef":{"credentialId":"new-id"}}}}]}}},"confirmed":true}}],["audit",{"actorUid":"operator-uid","configurationId":"cfg-1","fromEnvironmentSlug":"local","toEnvironmentSlug":"acceptance","startedAt":true,"completedAt":true,"outcome":"failed","message":"Target environment returned a non-JSON response.","previewSummary":[],"credentialRebindCount":0,"callLogId":"calllog-200"},["integriq","promotion_audit"]]]}}';

	/**
	 * GOLDEN: one promotion per outcome — success with and without a
	 * rebinding, from a named environment, and a failure at every stage — with
	 * every audit record (its times only as present), every call to the target
	 * and the answer or the exception. Pinned before promote() was split.
	 *
	 * @return void
	 */
	public function testGoldenPromotions(): void {
		$document = $this->documentWithTopLevelPlaceholder('my-api-source', ['credentialId' => 'old-id']);
		$bindings = [['sourceSlug' => 'my-api-source', 'credentialId' => 'new-id']];
		$body = ['creates' => [['slug' => 'a']], 'updates' => [['slug' => 'b'], ['slug' => 'c']], 'written' => ['sources' => ['a']]];
		$cases = [
			'success rebound' => ['acceptance', $bindings, 'local', $document, 200, $body],
			'success unbound' => ['acceptance', [], 'local', $document, 201, ['creates' => []]],
			'success from test' => ['acceptance', $bindings, 'test', ['components' => []], 200, $body],
			'unknown target' => ['nowhere', $bindings, 'local', $document, 200, $body],
			'export fails' => ['acceptance', $bindings, 'local', null, 200, $body],
			'call fails' => ['acceptance', $bindings, 'local', $document, null, $body],
			'target refuses' => ['acceptance', $bindings, 'local', $document, 409, ['error' => 'collision']],
			'unreadable answer' => ['acceptance', $bindings, 'local', $document, 200, 'not json'],
		];

		$record = [];
		foreach ($cases as $name => [$target, $caseBindings, $from, $export, $status, $answer]) {
			$seen = [];
			$environments = $this->createMock(EnvironmentService::class);
			$environments->method('findBySlug')->willReturnCallback(
				fn (string $slug) => ($slug === 'acceptance') ? ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'acceptance', 'sourceRef' => 'source-uuid'], 'env-uuid') : null
			);
			$environments->method('resolveSource')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Acceptance API'], 'source-uuid'));
			$configurations = $this->createMock(ConfigurationService::class);
			$configurations->method('exportConfiguration')->willReturnCallback(
				function () use ($export): array {
					if ($export === null) {
						throw new RuntimeException('export broke');
					}

					return $export;
				}
			);
			$calls = $this->createMock(CallService::class);
			$calls->method('call')->willReturnCallback(
				function (...$args) use ($status, $answer, &$seen) {
					$seen[] = ['call', $args[1], $args[2], $args[3]];
					if ($status === null) {
						throw new RuntimeException('target unreachable');
					}

					$raw = is_string($answer) ? $answer : json_encode($answer);
					return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => $status, 'response' => ['body' => $raw]], 'calllog-' . $status);
				}
			);
			$objects = ObjectServiceMockBuilder::make($this);
			$objects->method('saveObject')->willReturnCallback(
				function (...$args) use (&$seen) {
					$payload = $args[0];
					$payload['startedAt'] = isset($payload['startedAt']);
					$payload['completedAt'] = isset($payload['completedAt']);
					$seen[] = ['audit', $payload, array_values(array_filter(array_slice($args, 1), 'is_string'))];
					return ObjectServiceMockBuilder::objectEntity($this, [], 'audit-' . count($seen));
				}
			);
			$service = new PromotionService($configurations, $environments, $calls, $objects);

			try {
				$outcome = ['returned' => $service->promote('cfg-1', $target, $caseBindings, 'operator-uid', $from)];
			} catch (\Throwable $exception) {
				$outcome = ['threw' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$record[$name] = $outcome + ['seen' => $seen];
		}//end foreach

		$this->assertSame(self::GOLDEN_PROMOTIONS, json_encode($record));
	}//end testGoldenPromotions()

	/**
	 * REQ-006 — a failed promotion (target returns 404, e.g. an older
	 * Integriq without the import routes) still writes exactly one
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
				'integriq',
				'promotion_audit'
			)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'audit-2'));

		$this->expectException(RuntimeException::class);

		$this->service->promote('cfg-1', 'acceptance', [], 'operator-uid');
	}//end testPromoteWritesFailedAuditAndRethrowsWhenTargetReturns404()

	/**
	 * GOLDEN: a document with several source groups, entries that are not
	 * arrays, a source without authentication, a top-level placeholder, nested
	 * placeholders at two depths and plain values. The scan lists every
	 * placeholder in walk order; the rebinding rewrites a field-specific match,
	 * falls back to a slug-wide binding, and leaves unmatched placeholders and
	 * plain values verbatim.
	 *
	 * @return void
	 */
	public function testGoldenScanAndRebindingAcrossEveryDocumentShape(): void {
		$document = [
			'components' => [
				'sources' => [
					'api' => [
						'not-a-source',
						['slug' => 'no-auth', 'configuration' => []],
						['slug' => 'top', 'configuration' => ['authentication' => ['credentialRef' => ['credentialId' => 'old-top']]]],
						[
							'slug' => 'deep',
							'configuration' => [
								'authentication' => [
									'apikey' => ['credentialRef' => ['credentialName' => 'old-key']],
									'header' => 'X-Api-Key',
									'oauth' => [
										'client' => ['secret' => ['credentialRef' => ['credentialId' => 'old-secret']]],
										'scopes' => ['read', 'write'],
									],
								],
							],
						],
					],
					'broken' => 'not-a-group',
					'sftp' => [
						['slug' => 'unbound', 'configuration' => ['authentication' => ['password' => ['credentialRef' => ['credentialId' => 'old-pw']]]]],
					],
				],
			],
		];
		$bindings = [
			'not-a-binding',
			['sourceSlug' => '', 'credentialId' => 'ignored'],
			['sourceSlug' => 'deep', 'field' => 'configuration.authentication.oauth.client.secret.credentialRef', 'credentialId' => 'new-secret'],
			['sourceSlug' => 'deep', 'credentialName' => 'new-default'],
			['sourceSlug' => 'top', 'credentialId' => ''],
		];

		$this->assertSame(self::GOLDEN_SCAN, json_encode($this->service->scanCredentialRefs($document)));
		$this->assertSame(self::GOLDEN_REBOUND, json_encode($this->service->applyCredentialBindings($document, $bindings)));
	}//end testGoldenScanAndRebindingAcrossEveryDocumentShape()

}//end class
