<?php

/**
 * Unit tests for SourcePaginateNode (`openconnector.source-paginate`).
 *
 * The core semantics under test: ONE item per page with truthful page numbers
 * and counts, the engine's completeness verdict riding on every page, a
 * ceiling that refuses rather than truncates, and a rate limit that leaves the
 * node as a SUSPENSION rather than as an empty success.
 *
 * The service double is a real subclass rather than a PHPUnit mock, for the
 * same reason `ContractSweepNodeTest` uses one: the node reads the
 * by-reference `fetchInfo` output parameter of `getAllObjectsFromApi()`, and a
 * mock's invocation layer cannot write one back to the caller. A mock would
 * therefore report an empty verdict on every call — a green test for a node
 * that never carried the verdict at all.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Flow;

use OCA\OpenConnector\Exception\FlowNodeException;
use OCA\OpenConnector\Flow\FlowNodeSupport;
use OCA\OpenConnector\Flow\FlowOwner;
use OCA\OpenConnector\Flow\SourcePaginateNode;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;
use UnexpectedValueException;

/**
 * Tests for the source-paginate flow node.
 */
class SourcePaginateNodeTest extends TestCase {

	/**
	 * The engine double, a real subclass (see file docblock).
	 *
	 * @var SynchronizationService
	 */
	private SynchronizationService $synchronizationService;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * The node under test.
	 *
	 * @var SourcePaginateNode
	 */
	private SourcePaginateNode $node;

	/**
	 * Build the node over the subclass double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->synchronizationService = $this->serviceDouble();
		$this->userManager = $this->createMock(IUserManager::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/openconnector/img/flow-synchronization-run.svg');

		$this->node = new SourcePaginateNode(
			synchronizationService: $this->synchronizationService,
			flowOwner: new FlowOwner(
				userManager: $this->userManager,
				userSession: $this->createMock(IUserSession::class),
				l10n: $l10n
			),
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * The palette metadata is present and app-namespaced.
	 *
	 * @return void
	 */
	public function testPaletteMetadata(): void {
		$this->assertSame('openconnector.source-paginate', $this->node->getId());
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());
		$this->assertNotSame('', $this->node->getIcon());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($this->node->isAvailableForScope(-1));

	}//end testPaletteMetadata()

	/**
	 * The config vocabulary is pinned exactly.
	 *
	 * Pinned with assertSame rather than assertContains because the point of
	 * `configKeys()` is that the node reads EVERY key it accepts. A key added
	 * here without a reader is a knob an operator turns for nothing.
	 *
	 * @return void
	 */
	public function testConfigVocabularyIsPinned(): void {
		$this->assertSame(
			['synchronization', 'pageSize', 'maxPages', 'output', 'onError'],
			$this->node->configKeys()
		);

	}//end testConfigVocabularyIsPinned()

	/**
	 * v1 declares no `concurrency` key, because it bounds no fetch.
	 *
	 * The delegate — `getAllObjectsFromApi()` — dispatches its own page
	 * prefetch internally and returns one flat list, so there is nothing here
	 * for `FlowConcurrency` to bound. A key the node accepted and never read
	 * would read as a working control and change nothing.
	 *
	 * @return void
	 */
	public function testNoConcurrencyKnobIsAdvertisedInV1(): void {
		$this->assertNotContains('concurrency', $this->node->configKeys());

		foreach ($this->node->configForm() as $field) {
			$this->assertNotSame('concurrency', $field['key']);
		}

	}//end testNoConcurrencyKnobIsAdvertisedInV1()

	/**
	 * The form describes only keys the node reads, and the synchronization
	 * field is a real picker rather than a bare uuid box.
	 *
	 * @return void
	 */
	public function testConfigFormDescribesOnlyKnownKeys(): void {
		$byKey = [];
		foreach ($this->node->configForm() as $field) {
			$this->assertContains($field['key'], $this->node->configKeys());
			$this->assertNotSame('', (string)($field['label'] ?? ''));
			$this->assertNotSame('', (string)($field['help'] ?? ''));
			$byKey[$field['key']] = $field;
		}

		$this->assertSame('select', $byKey['synchronization']['type']);
		$this->assertTrue($byKey['synchronization']['required']);
		$this->assertNotSame('', (string)($byKey['synchronization']['optionsFrom'] ?? ''));

	}//end testConfigFormDescribesOnlyKnownKeys()

	/**
	 * A step naming no synchronization is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMissingSynchronization(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/synchronization/');

		$this->node->validateConfig(['pageSize' => 10]);

	}//end testValidateRejectsMissingSynchronization()

	/**
	 * An inline synchronization definition is rejected: this step names an
	 * already-configured synchronization and never creates one.
	 *
	 * @return void
	 */
	public function testValidateRejectsInlineSynchronization(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/inline definition/');

		$this->node->validateConfig(['synchronization' => ['name' => 'invented here']]);

	}//end testValidateRejectsInlineSynchronization()

	/**
	 * A page size that is not a whole number is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMalformedPageSize(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/pageSize/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'pageSize' => '100']);

	}//end testValidateRejectsMalformedPageSize()

	/**
	 * A zero page ceiling is rejected: it would emit nothing while reporting
	 * success.
	 *
	 * @return void
	 */
	public function testValidateRejectsNonPositiveMaxPages(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/maxPages/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'maxPages' => 0]);

	}//end testValidateRejectsNonPositiveMaxPages()

	/**
	 * An output key claiming a reserved item key is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsReservedOutputKey(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/reserved/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'output' => 'binary']);

	}//end testValidateRejectsReservedOutputKey()

	/**
	 * An unknown `onError` policy is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsUnknownOnErrorPolicy(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/onError/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'onError' => 'shrug']);

	}//end testValidateRejectsUnknownOnErrorPolicy()

	/**
	 * A step naming a URL instead of a synchronization is refused by the
	 * shared guard.
	 *
	 * @return void
	 */
	public function testValidateRejectsAForbiddenTargetField(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'url' => 'https://example.test/api']);

	}//end testValidateRejectsAForbiddenTargetField()

	/**
	 * The fetch is chunked into pages: one item per page, truthful numbers.
	 *
	 * Five objects at a page size of two is three pages of 2/2/1 — the last
	 * page is deliberately short rather than padded, and `count` reports what
	 * the page actually carries.
	 *
	 * @return void
	 */
	public function testEmitsOneItemPerPageWithTruthfulCounts(): void {
		$this->givenOwner();
		$this->synchronizationService->objects = $this->objects(5);
		$this->synchronizationService->fetchInfoToWrite = [
			'complete' => true,
			'pagesFetched' => 2,
			'failureReason' => null,
		];

		$out = $this->node->execute(
			[['json' => ['trigger' => 'manual'], 'binary' => ['file' => 'x']]],
			['synchronization' => 'demo-sync', 'pageSize' => 2, 'output' => 'page'],
			$this->context()
		);

		$this->assertCount(3, $out);
		$this->assertSame([1, 2, 3], array_map(static fn (array $item): int => $item['json']['page']['page'], $out));
		$this->assertSame([2, 2, 1], array_map(static fn (array $item): int => $item['json']['page']['count'], $out));
		$this->assertSame([3, 3, 3], array_map(static fn (array $item): int => $item['json']['page']['pages'], $out));

		$first = $out[0]['json']['page'];
		$this->assertSame([['id' => 'obj-0'], ['id' => 'obj-1']], $first['results']);
		$this->assertSame(2, $first['pageSize']);
		$this->assertSame('demo-sync', $first['synchronization']);

		// The trigger's own record and binaries survive beside the page.
		$this->assertSame('manual', $out[0]['json']['trigger']);
		$this->assertSame(['file' => 'x'], $out[0]['binary']);
		$this->assertSame(['item' => 0], $out[2]['pairedItem']);

		// The seam is addressed exactly as its signature declares it.
		$this->assertSame('demo-sync', $this->synchronizationService->received['getSynchronization']);
		$received = $this->synchronizationService->received['getAllObjectsFromApi'];
		$this->assertFalse($received['isTest']);
		$this->assertNull($received['data']);
		$this->assertNull($received['resolvedSource']);
		$this->assertSame('Demo synchronization', $received['synchronization']['name']);

	}//end testEmitsOneItemPerPageWithTruthfulCounts()

	/**
	 * The engine's own completeness verdict rides on every page.
	 *
	 * This is what the stale sweep downstream refuses to delete without: a
	 * partial pass that looked complete would let it delete every object it
	 * simply did not reach.
	 *
	 * @return void
	 */
	public function testEveryPageCarriesTheFetchCompletenessVerdict(): void {
		$this->givenOwner();
		$this->synchronizationService->objects = $this->objects(3);
		$this->synchronizationService->fetchInfoToWrite = [
			'complete' => false,
			'pagesFetched' => 7,
			'failureReason' => 'page_fetch_failed',
		];

		$out = $this->node->execute(
			[['json' => []]],
			['synchronization' => 'demo-sync', 'pageSize' => 2],
			$this->context()
		);

		$this->assertCount(2, $out);
		foreach ($out as $item) {
			$this->assertFalse($item['json']['fetchInfo']['complete']);
			$this->assertSame(7, $item['json']['fetchInfo']['pagesFetched']);
			$this->assertSame('page_fetch_failed', $item['json']['fetchInfo']['failureReason']);
		}

	}//end testEveryPageCarriesTheFetchCompletenessVerdict()

	/**
	 * A seam that writes back no verdict at all is read as a complete pass of
	 * zero pages — the pre-`fetchInfo` behaviour, not a silent `false` that
	 * would block every sweep.
	 *
	 * @return void
	 */
	public function testAnAbsentVerdictDefaultsToComplete(): void {
		$this->givenOwner();
		$this->synchronizationService->objects = $this->objects(1);
		$this->synchronizationService->fetchInfoToWrite = null;

		$out = $this->node->execute([['json' => []]], ['synchronization' => 'demo-sync'], $this->context());

		$this->assertTrue($out[0]['json']['fetchInfo']['complete']);
		$this->assertSame(0, $out[0]['json']['fetchInfo']['pagesFetched']);
		$this->assertNull($out[0]['json']['fetchInfo']['failureReason']);

	}//end testAnAbsentVerdictDefaultsToComplete()

	/**
	 * With no output key the page REPLACES the record — the shape the
	 * downstream page steps read directly.
	 *
	 * @return void
	 */
	public function testWithoutAnOutputKeyThePageIsTheItem(): void {
		$this->givenOwner();
		$this->synchronizationService->objects = $this->objects(2);

		$out = $this->node->execute(
			[['json' => ['trigger' => 'schedule']]],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

		$this->assertCount(1, $out);
		$this->assertArrayNotHasKey('trigger', $out[0]['json']);
		$this->assertSame(2, $out[0]['json']['count']);
		$this->assertSame(1, $out[0]['json']['page']);
		$this->assertSame(SourcePaginateNode::DEFAULT_PAGE_SIZE, $out[0]['json']['pageSize']);

	}//end testWithoutAnOutputKeyThePageIsTheItem()

	/**
	 * A source that returned nothing still emits exactly one page.
	 *
	 * Emitting nothing would make "fetched, and the source is empty"
	 * indistinguishable from "never fetched" — and the stale sweep downstream
	 * turns exactly that distinction into a decision about deleting objects.
	 *
	 * @return void
	 */
	public function testAnEmptySourceStillEmitsOnePage(): void {
		$this->givenOwner();
		$this->synchronizationService->objects = [];

		$out = $this->node->execute([['json' => []]], ['synchronization' => 'demo-sync'], $this->context());

		$this->assertCount(1, $out);
		$this->assertSame(1, $out[0]['json']['page']);
		$this->assertSame(1, $out[0]['json']['pages']);
		$this->assertSame(0, $out[0]['json']['count']);
		$this->assertSame([], $out[0]['json']['results']);
		$this->assertTrue($out[0]['json']['fetchInfo']['complete']);

	}//end testAnEmptySourceStillEmitsOnePage()

	/**
	 * A fetch above the page ceiling RAISES and returns no items at all.
	 *
	 * A shortened page list is indistinguishable from a complete one at every
	 * downstream step, and the step after this one deletes what it did not
	 * see.
	 *
	 * @return void
	 */
	public function testAFetchAboveTheCeilingRaisesRatherThanTruncating(): void {
		$this->givenOwner();
		$this->synchronizationService->objects = $this->objects(10);

		try {
			$this->node->execute(
				[['json' => []]],
				['synchronization' => 'demo-sync', 'pageSize' => 1, 'maxPages' => 4],
				$this->context()
			);
			$this->fail('A fetch above the ceiling must raise.');
		} catch (FlowNodeException $exception) {
			$details = $exception->getDetails();
			$this->assertSame('ceiling', $details['kind']);
			$this->assertSame(10, $details['pageCount']);
			$this->assertSame(4, $details['maxPages']);
			$this->assertStringContainsString('step-paginate', $exception->getMessage());
			$this->assertStringContainsString('No truncated list is returned', $exception->getMessage());
		}

	}//end testAFetchAboveTheCeilingRaisesRatherThanTruncating()

	/**
	 * A failed fetch raises under the default `stop` policy, naming the
	 * synchronization and the underlying cause.
	 *
	 * @return void
	 */
	public function testAFailedFetchRaisesUnderStop(): void {
		$this->givenOwner();
		$this->synchronizationService->throwOnFetch = new RuntimeException('connection refused');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/connection refused/');

		$this->node->execute([['json' => []]], ['synchronization' => 'demo-sync'], $this->context());

	}//end testAFailedFetchRaisesUnderStop()

	/**
	 * An unresolvable synchronization is a fetch failure too — the node never
	 * invents one.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSynchronizationFails(): void {
		$this->givenOwner();
		$this->synchronizationService->throwOnResolve = new RuntimeException('no such synchronization');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/no such synchronization/');

		$this->node->execute([['json' => []]], ['synchronization' => 'ghost'], $this->context());

	}//end testAnUnresolvableSynchronizationFails()

	/**
	 * Under `continue` a failed fetch lands as ONE explicit error item, and
	 * that item is NOT shaped like a page.
	 *
	 * @return void
	 */
	public function testAFailedFetchContinuesWithAnErrorItem(): void {
		$this->givenOwner();
		$this->synchronizationService->throwOnFetch = new RuntimeException('gateway timeout');

		$out = $this->node->execute(
			[['json' => ['trigger' => 'manual'], 'binary' => ['f' => 1]]],
			['synchronization' => 'demo-sync', 'onError' => 'continue'],
			$this->context()
		);

		$this->assertCount(1, $out);
		$this->assertArrayNotHasKey('results', $out[0]['json']);
		$this->assertSame('manual', $out[0]['json']['trigger']);
		$this->assertSame(['f' => 1], $out[0]['binary']);

		$error = $out[0]['json'][FlowNodeSupport::ERROR_KEY];
		$this->assertSame('fetch', $error['kind']);
		$this->assertSame('step-paginate', $error['step']);
		$this->assertSame('openconnector.source-paginate', $error['node']);
		$this->assertSame('demo-sync', $error['synchronization']);
		$this->assertStringContainsString('gateway timeout', $error['message']);

	}//end testAFailedFetchContinuesWithAnErrorItem()

	/**
	 * `dead_letter` is absorbed like `continue`: the item carries explicit
	 * error state and the capture itself is engine-side wiring.
	 *
	 * @return void
	 */
	public function testDeadLetterIsAbsorbedLikeContinue(): void {
		$this->givenOwner();
		$this->synchronizationService->throwOnFetch = new RuntimeException('boom');

		$out = $this->node->execute(
			[['json' => []]],
			['synchronization' => 'demo-sync', 'onError' => 'dead_letter'],
			$this->context()
		);

		$this->assertCount(1, $out);
		$this->assertArrayHasKey(FlowNodeSupport::ERROR_KEY, $out[0]['json']);

	}//end testDeadLetterIsAbsorbedLikeContinue()

	/**
	 * A rate limit SUSPENDS the run — it is never absorbed into an empty
	 * success, not even under `onError: continue`.
	 *
	 * This is the assertion that keeps the node out of the class of bug where
	 * a refused shard reads as "this source had nothing". The bounds are the
	 * shared ones (`FlowRateLimit`), so a source-supplied reset is honoured
	 * and a reset in the past is floored rather than spun on.
	 *
	 * @return void
	 */
	public function testARateLimitSuspendsTheRunEvenUnderContinue(): void {
		$this->givenOwner();
		$reset = (time() + 300);
		$this->synchronizationService->throwOnFetch = new TooManyRequestsHttpException(
			message: 'Rate Limit on Source has been exceeded.',
			headers: ['X-RateLimit-Reset' => $reset]
		);

		try {
			$this->node->execute(
				[['json' => []]],
				['synchronization' => 'demo-sync', 'onError' => 'continue'],
				$this->context()
			);
			$this->fail('A rate limit must suspend the run, not produce items.');
		} catch (FlowSuspension $suspension) {
			$resumeAt = $suspension->getResumeAt();
			$this->assertNotNull($resumeAt, 'A suspension with no resumeAt is one nothing can wake.');
			$this->assertEqualsWithDelta($reset, $resumeAt->getTimestamp(), 2);
			$this->assertStringContainsString('rate limited', $suspension->getMessage());
			$this->assertStringContainsString('demo-sync', $suspension->getMessage());
		}

	}//end testARateLimitSuspendsTheRunEvenUnderContinue()

	/**
	 * Every input item gets its own fetch and its own pages, paired back to
	 * the item that caused them.
	 *
	 * @return void
	 */
	public function testEachInputItemFansOutIntoItsOwnPages(): void {
		$this->givenOwner();
		$this->synchronizationService->objects = $this->objects(3);

		$out = $this->node->execute(
			[['json' => ['n' => 1]], ['json' => ['n' => 2]]],
			['synchronization' => 'demo-sync', 'pageSize' => 2],
			$this->context()
		);

		$this->assertCount(4, $out);
		$this->assertSame([0, 0, 1, 1], array_map(
			static fn (array $item): int => $item['pairedItem']['item'],
			$out
		));
		$this->assertSame([1, 2, 1, 2], array_map(
			static fn (array $item): int => $item['json']['page'],
			$out
		));

	}//end testEachInputItemFansOutIntoItsOwnPages()

	/**
	 * An empty branch fetches nothing and produces nothing — the filter
	 * contract, not a failure. It short-circuits before the owner is resolved.
	 *
	 * @return void
	 */
	public function testAnEmptyBranchFetchesNothing(): void {
		$this->assertSame([], $this->node->execute([], ['synchronization' => 'demo-sync'], $this->context()));
		$this->assertArrayNotHasKey('getAllObjectsFromApi', $this->synchronizationService->received);

	}//end testAnEmptyBranchFetchesNothing()

	/**
	 * A run that cannot be attributed to an owner never reaches the source.
	 *
	 * @return void
	 */
	public function testAnUnattributedRunNeverFetches(): void {
		$this->userManager->method('get')->willReturn(null);

		$this->expectException(Throwable::class);

		try {
			$this->node->execute([['json' => []]], ['synchronization' => 'demo-sync'], $this->context());
		} finally {
			$this->assertArrayNotHasKey('getAllObjectsFromApi', $this->synchronizationService->received);
		}

	}//end testAnUnattributedRunNeverFetches()

	/**
	 * A run context naming an owner and a step.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['triggeredBy' => 'alice', 'stepId' => 'step-paginate'];
	}//end context()

	/**
	 * A list of distinguishable source objects.
	 *
	 * @param int $count How many to build.
	 *
	 * @return array<int, array<string, string>> The objects.
	 */
	private function objects(int $count): array {
		$objects = [];
		for ($index = 0; $index < $count; $index++) {
			$objects[] = ['id' => 'obj-' . $index];
		}

		return $objects;
	}//end objects()

	/**
	 * Make the user manager resolve the run owner.
	 *
	 * @return IUser The owner double.
	 */
	private function givenOwner(): IUser {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->willReturn($owner);

		return $owner;
	}//end givenOwner()

	/**
	 * Build the recording subclass double.
	 *
	 * @return SynchronizationService The double.
	 */
	private function serviceDouble(): SynchronizationService {
		return new class extends SynchronizationService {

			/**
			 * The calls this double received, keyed by method.
			 *
			 * @var array<string, mixed>
			 */
			public array $received = [];

			/**
			 * The objects `getAllObjectsFromApi()` returns.
			 *
			 * @var array<int, mixed>
			 */
			public array $objects = [];

			/**
			 * The verdict `getAllObjectsFromApi()` writes back, or null to
			 * write nothing at all.
			 *
			 * @var array|null
			 */
			public ?array $fetchInfoToWrite = null;

			/**
			 * When set, `getAllObjectsFromApi()` throws this instead.
			 *
			 * @var Throwable|null
			 */
			public ?Throwable $throwOnFetch = null;

			/**
			 * When set, `getSynchronization()` throws this instead.
			 *
			 * @var Throwable|null
			 */
			public ?Throwable $throwOnResolve = null;

			/**
			 * Bypass the real constructor: nothing the overridden methods
			 * touch needs the real dependencies.
			 */
			public function __construct() {

			}//end __construct()

			/**
			 * Resolve a fixed synchronization object, recording the id.
			 *
			 * @param string|int|null $id The requested id.
			 * @param array $filters Unused here.
			 *
			 * @return ObjectEntity The synchronization object.
			 *
			 * @throws Throwable When the test configured a failure.
			 */
			public function getSynchronization(null|string|int $id = null, array $filters = []): ObjectEntity {
				$this->received['getSynchronization'] = $id;

				if ($this->throwOnResolve !== null) {
					throw $this->throwOnResolve;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('44444444-4444-4444-4444-444444444444');
				$entity->setObject(['name' => 'Demo synchronization', 'sourceType' => 'api']);

				return $entity;
			}//end getSynchronization()

			/**
			 * Record the arguments, write the verdict back, and return the
			 * configured objects.
			 *
			 * @param array $synchronization The synchronization payload.
			 * @param bool|null $isTest Whether this is a test run.
			 * @param array|null $data Data merged into every object.
			 * @param array|null $fetchInfo By-reference completeness verdict.
			 * @param array|null $resolvedSource A pre-resolved source.
			 *
			 * @return array The configured objects.
			 *
			 * @throws Throwable When the test configured a failure.
			 */
			public function getAllObjectsFromApi(
				array $synchronization,
				?bool $isTest = false,
				?array $data = null,
				?array &$fetchInfo = null,
				?array $resolvedSource = null,
			): array {
				$this->received['getAllObjectsFromApi'] = [
					'synchronization' => $synchronization,
					'isTest' => $isTest,
					'data' => $data,
					'resolvedSource' => $resolvedSource,
				];

				if ($this->throwOnFetch !== null) {
					throw $this->throwOnFetch;
				}

				if ($this->fetchInfoToWrite !== null) {
					$fetchInfo = $this->fetchInfoToWrite;
				}

				return $this->objects;
			}//end getAllObjectsFromApi()
		};
	}//end serviceDouble()
}//end class
