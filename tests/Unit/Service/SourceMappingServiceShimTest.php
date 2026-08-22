<?php

/**
 * Wire-contract tests for SourceMappingService's MongoDB Data API shim.
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

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use InvalidArgumentException;
use OCA\Integriq\Service\SourceMappingService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Covers openspec/specs/object-service-shim/spec.md.
 *
 * WHY THIS FILE EXISTS. That spec's `@e2e exclude` claimed all ten scenarios
 * were "covered by PHPUnit". `SourceMappingServiceTest` covers exactly one of
 * them (`openregister app is not installed`); the nine MongoDB Data API
 * scenarios — which endpoint each operation targets, what it puts in the
 * envelope, and the UUID minting — had no test at all.
 *
 * HOW THE HTTP LAYER IS DRIVEN. `getClient()` does `new Client($config)` with
 * the caller's array, and Guzzle honours a `handler` key in that array. So a
 * `MockHandler` can be injected through the ordinary public API — no
 * reflection, no subclassing, and the code under test is exercised exactly as
 * production calls it. A history middleware records the outgoing requests so
 * the assertions can be about the WIRE, which is what "Data API shim" means.
 */
class SourceMappingServiceShimTest extends TestCase {

	/**
	 * Recorded Guzzle transactions for the most recent config built.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * Build a service with mocked Nextcloud dependencies.
	 *
	 * @return SourceMappingService
	 */
	private function service(): SourceMappingService {
		return new SourceMappingService(
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
		);
	}//end service()

	/**
	 * An IAppManager whose `getEnabledApps()` returns the given list.
	 *
	 * `getOpenRegisters()` calls `$this->appManager->getEnabledApps()`, but
	 * `OCP\App\IAppManager` DOES NOT DECLARE that method in the pinned
	 * `vendor/nextcloud/ocp` (v32.0.9) — it declares `getEnabledAppsForUser`,
	 * `getEnabledAppsForGroup` and `getInstalledApps` only. The production call
	 * therefore depends on a method of the CONCRETE `OC\App\AppManager` rather
	 * than of the interface it is typed against, and a plain
	 * `createMock(IAppManager::class)` cannot stub it here.
	 *
	 * Built whichever way the loaded interface allows, so this test behaves the
	 * same in a bare container and inside a real Nextcloud — the lesson from
	 * hand-implementing IRequest, which fatals when the two disagree.
	 *
	 * @param array<int, string> $enabled The enabled-app ids to report.
	 *
	 * @return IAppManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function appManagerReporting(array $enabled) {
		if (method_exists(IAppManager::class, 'getEnabledApps') === true) {
			$appManager = $this->createMock(IAppManager::class);
		} else {
			$appManager = $this->getMockBuilder(IAppManager::class)
				->addMethods(['getEnabledApps'])
				->getMockForAbstractClass();
		}

		$appManager->method('getEnabledApps')->willReturn($enabled);

		return $appManager;
	}//end appManagerReporting()

	/**
	 * Build a `$config` whose Guzzle client answers with the given responses
	 * and records every request it sends.
	 *
	 * @param array<int, GuzzleResponse> $responses Queued responses.
	 *
	 * @return array<string, mixed> The config to hand to a shim method.
	 */
	private function configWithResponses(array $responses): array {
		$this->history = [];
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->history));

		return [
			'base_uri' => 'https://data.example.org/app/data-api/endpoint/',
			'handler' => $stack,
			'mongodbCluster' => 'test-cluster',
		];
	}//end configWithResponses()

	/**
	 * The decoded JSON body of the n-th recorded request.
	 *
	 * @param int $index Zero-based request index.
	 *
	 * @return array<string, mixed>
	 */
	private function sentJson(int $index): array {
		$this->assertArrayHasKey($index, $this->history, 'expected at least ' . ($index + 1) . ' HTTP request(s)');

		return json_decode((string)$this->history[$index]['request']->getBody(), true);
	}//end sentJson()

	/**
	 * The path of the n-th recorded request.
	 *
	 * @param int $index Zero-based request index.
	 *
	 * @return string
	 */
	private function sentPath(int $index): string {
		return (string)$this->history[$index]['request']->getUri()->getPath();
	}//end sentPath()

	// -----------------------------------------------------------------------
	// REQ-001 — Data API CRUD wrapper
	// -----------------------------------------------------------------------

	/**
	 * Scenario: saveObject posts a document and re-fetches by inserted id.
	 *
	 * THEN it POSTs `action/insertOne` with the document merged into
	 * BASE_OBJECT and `dataSource` set from `$config['mongodbCluster']`, then
	 * re-fetches via `action/findOne` filtered on the inserted id.
	 *
	 * @return void
	 */
	public function testSaveObjectPostsInsertOneThenRefetchesByInsertedId(): void {
		$config = $this->configWithResponses(
			[
				new GuzzleResponse(200, [], json_encode(['insertedId' => 'mongo-id-1'])),
				new GuzzleResponse(200, [], json_encode(['document' => ['id' => 'mongo-id-1', 'name' => 'thing']])),
			]
		);

		$result = $this->service()->saveObject(['name' => 'thing'], $config);

		$this->assertCount(2, $this->history, 'saveObject makes exactly two calls: insert then re-fetch');
		$this->assertStringEndsWith('action/insertOne', $this->sentPath(0));
		$this->assertStringEndsWith('action/findOne', $this->sentPath(1));

		$insert = $this->sentJson(0);
		$this->assertSame('test-cluster', $insert['dataSource']);
		$this->assertSame('thing', $insert['document']['name']);

		// The re-fetch must be filtered on the id the insert reported, not on
		// the minted document id — getting this wrong returns someone else's
		// document while every shape assertion still passes.
		$this->assertSame(['_id' => 'mongo-id-1'], $this->sentJson(1)['filter']);

		// saveObject returns findObject()'s value, and findObject unwraps the
		// Data API envelope to `$body['document']` — so the caller gets the
		// document itself, not the envelope.
		$this->assertSame(['id' => 'mongo-id-1', 'name' => 'thing'], $result);
	}//end testSaveObjectPostsInsertOneThenRefetchesByInsertedId()

	/**
	 * Scenario: findObjects returns the raw decoded Data API body.
	 *
	 * @return void
	 */
	public function testFindObjectsPostsActionFindAndReturnsTheDecodedBody(): void {
		$body = ['documents' => [['id' => 'a'], ['id' => 'b']]];
		$config = $this->configWithResponses([new GuzzleResponse(200, [], json_encode($body))]);

		$result = $this->service()->findObjects(['status' => 'active'], $config);

		$this->assertStringEndsWith('action/find', $this->sentPath(0));
		$sent = $this->sentJson(0);
		$this->assertSame('test-cluster', $sent['dataSource']);
		$this->assertSame(['status' => 'active'], $sent['filter']);
		$this->assertSame($body, $result, 'the decoded body is returned verbatim');
	}//end testFindObjectsPostsActionFindAndReturnsTheDecodedBody()

	/**
	 * Scenario: deleteObject returns an empty array regardless of upstream
	 * response.
	 *
	 * The upstream body is deliberately non-empty here: a implementation that
	 * forwarded it would fail this test, which is the whole point.
	 *
	 * @return void
	 */
	public function testDeleteObjectAlwaysReturnsAnEmptyArray(): void {
		$config = $this->configWithResponses(
			[new GuzzleResponse(200, [], json_encode(['deletedCount' => 1, 'noise' => 'upstream']))]
		);

		$result = $this->service()->deleteObject(['_id' => 'x'], $config);

		$this->assertStringEndsWith('action/deleteOne', $this->sentPath(0));
		$this->assertSame(['_id' => 'x'], $this->sentJson(0)['filter']);
		$this->assertSame([], $result);
	}//end testDeleteObjectAlwaysReturnsAnEmptyArray()

	/**
	 * Scenario: getClient returns a Guzzle Client built from caller-supplied
	 * config.
	 *
	 * The spec records — as OBSERVED behaviour, explicitly "flagged for
	 * follow-up rather than silently corrected" — that the
	 * `mongodbCluster`-stripped local copy is computed and DISCARDED, and the
	 * original config is what reaches the Guzzle constructor. This test pins
	 * that: `mongodbCluster` survives into the client's config.
	 *
	 * @return void
	 */
	public function testGetClientPassesTheOriginalConfigIncludingMongodbCluster(): void {
		$config = $this->configWithResponses([]);

		$client = $this->service()->getClient($config);

		$this->assertSame('test-cluster', $client->getConfig('mongodbCluster'));
		$this->assertSame(
			'https://data.example.org/app/data-api/endpoint/',
			(string)$client->getConfig('base_uri')
		);
	}//end testGetClientPassesTheOriginalConfigIncludingMongodbCluster()

	// -----------------------------------------------------------------------
	// REQ-002 — UUID minting on insert
	// -----------------------------------------------------------------------

	/**
	 * Scenario: caller-supplied id is overwritten by minted UUID.
	 *
	 * Both `id` and `_id` must carry the SAME freshly minted UUIDv4, and the
	 * caller's own values must not survive. A shim that honoured a
	 * caller-supplied `_id` would let a caller overwrite an existing document.
	 *
	 * @return void
	 */
	public function testCallerSuppliedIdIsOverwrittenByAMintedUuid(): void {
		$config = $this->configWithResponses(
			[
				new GuzzleResponse(200, [], json_encode(['insertedId' => 'mongo-id-1'])),
				// findObject() unwraps `$body['document']` and is typed `: array`,
				// so the re-fetch stub must carry that key.
				new GuzzleResponse(200, [], json_encode(['document' => []])),
			]
		);

		$this->service()->saveObject(
			['id' => 'caller-id', '_id' => 'caller-underscore-id', 'name' => 'thing'],
			$config
		);

		$document = $this->sentJson(0)['document'];

		$this->assertNotSame('caller-id', $document['id']);
		$this->assertNotSame('caller-underscore-id', $document['_id']);
		$this->assertSame($document['id'], $document['_id'], 'id and _id carry the same minted value');
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			(string)$document['id'],
			'the minted value is a UUIDv4'
		);
	}//end testCallerSuppliedIdIsOverwrittenByAMintedUuid()

	/**
	 * Two inserts must not reuse a UUID — the control that the value is minted
	 * per call rather than derived from the payload.
	 *
	 * @return void
	 */
	public function testEachInsertMintsAFreshUuid(): void {
		$ids = [];
		foreach ([0, 1] as $ignored) {
			$config = $this->configWithResponses(
				[
					new GuzzleResponse(200, [], json_encode(['insertedId' => 'x'])),
					// `findObject()` returns `$result['document']` and is typed
					// `: array`, so a body without that key is a TypeError, not
					// an empty result. The re-fetch stub must therefore carry
					// one. (Noted as a robustness gap: an upstream Data API
					// error body fatals the shim rather than surfacing.)
					new GuzzleResponse(200, [], json_encode(['document' => []])),
				]
			);
			$this->service()->saveObject(['name' => 'same-payload'], $config);
			$ids[] = (string)$this->sentJson(0)['document']['id'];
		}

		$this->assertNotSame($ids[0], $ids[1], 'identical payloads must still mint distinct UUIDs');
	}//end testEachInsertMintsAFreshUuid()

	// -----------------------------------------------------------------------
	// REQ-003 — aggregation
	// -----------------------------------------------------------------------

	/**
	 * Scenario: aggregateObjects forwards filters + pipeline verbatim.
	 *
	 * @return void
	 */
	public function testAggregateObjectsForwardsFiltersAndPipelineVerbatim(): void {
		$filters = ['status' => 'published'];
		$pipeline = [['$group' => ['_id' => '$category', 'count' => ['$sum' => 1]]]];
		$body = ['documents' => [['_id' => 'news', 'count' => 3]]];

		$config = $this->configWithResponses([new GuzzleResponse(200, [], json_encode($body))]);

		$result = $this->service()->aggregateObjects($filters, $pipeline, $config);

		$this->assertStringEndsWith('action/aggregate', $this->sentPath(0));
		$sent = $this->sentJson(0);
		$this->assertSame($filters, $sent['filter']);
		$this->assertSame($pipeline, $sent['pipeline']);
		$this->assertSame('test-cluster', $sent['dataSource']);
		$this->assertSame($body, $result);
	}//end testAggregateObjectsForwardsFiltersAndPipelineVerbatim()

	// -----------------------------------------------------------------------
	// REQ-004 — opportunistic OpenRegister resolution
	// -----------------------------------------------------------------------

	/**
	 * Scenario: openregister is installed but the container binding is
	 * missing.
	 *
	 * THEN the method catches and returns null, and does NOT rethrow.
	 *
	 * @return void
	 */
	public function testGetOpenRegistersReturnsNullWhenTheContainerBindingIsMissing(): void {
		$appManager = $this->appManagerReporting(['openconnector', 'openregister']);

		$notFound = new class extends \Exception implements NotFoundExceptionInterface {
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException($notFound);

		$service = new SourceMappingService(appManager: $appManager, container: $container);

		$this->assertNull($service->getOpenRegisters());
	}//end testGetOpenRegistersReturnsNullWhenTheContainerBindingIsMissing()

	/**
	 * The app-not-installed path must not even ask the container.
	 *
	 * @return void
	 */
	public function testGetOpenRegistersDoesNotTouchTheContainerWhenTheAppIsAbsent(): void {
		$appManager = $this->appManagerReporting(['openconnector']);

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$service = new SourceMappingService(appManager: $appManager, container: $container);

		$this->assertNull($service->getOpenRegisters());
	}//end testGetOpenRegistersDoesNotTouchTheContainerWhenTheAppIsAbsent()

	// -----------------------------------------------------------------------
	// REQ-005 — mapper resolution
	// -----------------------------------------------------------------------

	/**
	 * Scenario: register + schema resolves an OR mapper.
	 *
	 * THEN the method delegates to
	 * `getOpenRegisters()->getMapper(register: $registerId, schema: $schemaId)`
	 * and returns that mapper.
	 *
	 * The argument order is the load-bearing part: `getMapper` takes
	 * `($objectType, $schema, $register)` but delegates as
	 * `(register: $register, schema: $schema)`. Transposing the two would still
	 * return "a mapper" for any caller whose register and schema ids happened
	 * to both exist, and would silently resolve the wrong one.
	 *
	 * @return void
	 */
	public function testRegisterAndSchemaResolveAnOpenRegisterMapper(): void {
		$expectedMapper = new \stdClass();
		$captured = [];

		$openRegister = $this->getMockBuilder(\OCA\OpenRegister\Service\ObjectService::class)
			->disableOriginalConstructor()
			->getMock();
		$openRegister->method('getMapper')->willReturnCallback(
			function ($register = null, $schema = null) use (&$captured, $expectedMapper) {
				$captured = ['register' => $register, 'schema' => $schema];
				return $expectedMapper;
			}
		);

		$appManager = $this->appManagerReporting(['openconnector', 'openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($openRegister);

		$service = new SourceMappingService(appManager: $appManager, container: $container);

		$this->assertSame($expectedMapper, $service->getMapper(null, 3, 7));
		$this->assertSame(['register' => 7, 'schema' => 3], $captured);
	}//end testRegisterAndSchemaResolveAnOpenRegisterMapper()

	/**
	 * Scenario: any other input combination throws.
	 *
	 * Each rejected combination is driven, with the message asserted — the
	 * spec pins the message text, and a caller distinguishing failure modes
	 * on it would break silently otherwise.
	 *
	 * @param string|null $objectType The objectType argument.
	 * @param int|null $schema The schema argument.
	 * @param int|null $register The register argument.
	 *
	 * @return void
	 *
	 * @dataProvider rejectedMapperArguments
	 */
	public function testGetMapperThrowsForEveryOtherInputCombination(
		?string $objectType,
		?int $schema,
		?int $register,
	): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown object type: ' . $objectType);

		$this->service()->getMapper($objectType, $schema, $register);
	}//end testGetMapperThrowsForEveryOtherInputCombination()

	/**
	 * The three combinations the spec names as rejected.
	 *
	 * @return array<string, array{0: string|null, 1: int|null, 2: int|null}>
	 */
	public static function rejectedMapperArguments(): array {
		return [
			'a legacy objectType' => ['legacyType', null, null],
			'register without schema' => [null, null, 7],
			'schema without register' => [null, 3, null],
			'an objectType alongside register+schema' => ['legacyType', 3, 7],
		];
	}//end rejectedMapperArguments()

}//end class
