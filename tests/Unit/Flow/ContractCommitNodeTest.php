<?php

/**
 * Unit tests for ContractCommitNode (`openconnector.contract-commit`).
 *
 * The core semantics under test: `skip` / `invalid` / undecided items pass
 * through untouched, `create` and `update` build correctly-stamped contract
 * payloads, the whole page persists in ONE `persistBulk()` call — none at all
 * for an empty batch — and an `update` without its contract uuid follows the
 * step's `onError` policy instead of quietly creating a duplicate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use OCA\Integriq\Flow\ContractCommitNode;
use OCA\Integriq\Flow\FlowNodeSupport;
use OCA\Integriq\Flow\FlowOwner;
use OCA\Integriq\Service\SynchronizationContractService;
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
use UnexpectedValueException;

/**
 * Tests for the contract-commit flow node.
 */
class ContractCommitNodeTest extends TestCase {

	/**
	 * The contract store double.
	 *
	 * @var SynchronizationContractService&MockObject
	 */
	private $contractService;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * The node under test.
	 *
	 * @var ContractCommitNode
	 */
	private ContractCommitNode $node;

	/**
	 * Build the node with doubles for everything it delegates to.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->contractService = $this->createMock(SynchronizationContractService::class);
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

		$this->node = new ContractCommitNode(
			synchronizationContractService: $this->contractService,
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
		$this->assertSame('openconnector.contract-commit', $this->node->getId());
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());
		$this->assertNotSame('', $this->node->getIcon());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($this->node->isAvailableForScope(-1));

	}//end testPaletteMetadata()

	/**
	 * The config vocabulary is pinned, and the form describes only known keys.
	 *
	 * @return void
	 */
	public function testConfigVocabularyIsPinned(): void {
		$this->assertSame(
			['synchronization', 'contractPosition', 'targetIdPosition', 'targetHashPosition', 'onError'],
			$this->node->configKeys()
		);

		foreach ($this->node->configForm() as $field) {
			$this->assertContains($field['key'], $this->node->configKeys());
			$this->assertNotSame('', (string)($field['label'] ?? ''));
		}

	}//end testConfigVocabularyIsPinned()

	/**
	 * A step naming no synchronization is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMissingSynchronization(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/synchronization/');

		$this->node->validateConfig([]);

	}//end testValidateRejectsMissingSynchronization()

	/**
	 * An empty decision path is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsEmptyContractPosition(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/contractPosition/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'contractPosition' => ' ']);

	}//end testValidateRejectsEmptyContractPosition()

	/**
	 * An empty input list persists nothing and returns nothing.
	 *
	 * @return void
	 */
	public function testEmptyInputCommitsNothing(): void {
		$this->contractService->expects($this->never())->method('persistBulk');

		$this->assertSame([], $this->node->execute([], ['synchronization' => 'demo-sync'], $this->context()));

	}//end testEmptyInputCommitsNothing()

	/**
	 * A failed bulk upsert raises — a half-persisted page must never report
	 * itself committed.
	 *
	 * @return void
	 */
	public function testFailedBulkUpsertRaises(): void {
		$this->givenOwner();

		$this->contractService->method('persistBulk')
			->willThrowException(new RuntimeException('database gone'));

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/bulk contract upsert/');

		$this->node->execute(
			[
				[
					'json' => [
						'uuid' => 'obj-1',
						'contract' => ['outcome' => 'create', 'originId' => 'o-1', 'originHash' => 'h-1'],
					],
				],
			],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

	}//end testFailedBulkUpsertRaises()

	/**
	 * Custom positions are honoured, and a create with no written uuid anywhere
	 * commits a null targetId rather than an empty string.
	 *
	 * @return void
	 */
	public function testCustomPositionsAndMissingTargetId(): void {
		$this->givenOwner();

		$batches = [];
		$this->contractService->expects($this->once())
			->method('persistBulk')
			->willReturnCallback(
				static function (array $contracts) use (&$batches): array {
					$batches[] = $contracts;

					return $contracts;
				}
			);

		$out = $this->node->execute(
			[
				[
					'json' => [
						'written' => ['id' => 'obj-9'],
						'decision' => ['outcome' => 'create', 'originId' => 'o-1', 'originHash' => 'h-1'],
					],
				],
				[
					'json' => [
						'decision' => ['outcome' => 'create', 'originId' => 'o-2', 'originHash' => 'h-2'],
					],
				],
			],
			[
				'synchronization' => 'demo-sync',
				'contractPosition' => 'decision',
				'targetIdPosition' => 'written.id',
			],
			$this->context()
		);

		$this->assertCount(1, $batches);
		$this->assertSame('obj-9', $batches[0][0]['targetId']);
		$this->assertNull($batches[0][1]['targetId']);
		$this->assertSame('created', $batches[0][1]['targetLastAction']);

		$this->assertTrue($out[0]['json']['decision']['committed']);
		$this->assertTrue($out[1]['json']['decision']['committed']);
		$this->assertArrayNotHasKey('contract', $out[0]['json']);

	}//end testCustomPositionsAndMissingTargetId()

	/**
	 * Creates and updates commit in ONE bulk call; skips pass through untouched.
	 *
	 * @return void
	 */
	public function testCommitsThePageInOneBulkCall(): void {
		$this->givenOwner();

		$batches = [];
		$this->contractService->expects($this->once())
			->method('persistBulk')
			->willReturnCallback(
				static function (array $contracts) use (&$batches): array {
					$batches[] = $contracts;

					return $contracts;
				}
			);

		$out = $this->node->execute(
			[
				[
					'json' => [
						'uuid' => 'obj-1',
						'contract' => ['outcome' => 'create', 'originId' => 'o-1', 'originHash' => 'h-1'],
					],
				],
				[
					'json' => [
						'uuid' => 'obj-2',
						'contract' => [
							'outcome' => 'update',
							'originId' => 'o-2',
							'originHash' => 'h-2',
							'contractUuid' => 'c-2',
							'targetId' => 't-2',
						],
					],
				],
				[
					'json' => [
						'uuid' => 'obj-3',
						'contract' => ['outcome' => 'skip', 'originId' => 'o-3', 'originHash' => 'h-3'],
					],
				],
				['json' => ['uuid' => 'obj-4']],
			],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

		$this->assertCount(1, $batches);
		$this->assertCount(2, $batches[0]);

		$create = $batches[0][0];
		$this->assertNotSame('', (string)$create['uuid']);
		$this->assertSame('demo-sync', $create['synchronizationId']);
		$this->assertSame('o-1', $create['originId']);
		$this->assertSame('h-1', $create['originHash']);
		$this->assertSame('obj-1', $create['targetId']);
		$this->assertSame('created', $create['targetLastAction']);
		$this->assertNotSame('', (string)$create['sourceLastChecked']);
		$this->assertNotSame('', (string)$create['sourceLastSynced']);
		$this->assertNotSame('', (string)$create['targetLastSynced']);

		$update = $batches[0][1];
		$this->assertSame('c-2', $update['uuid']);
		$this->assertSame('t-2', $update['targetId']);
		$this->assertSame('updated', $update['targetLastAction']);

		$this->assertCount(4, $out);
		$this->assertTrue($out[0]['json']['contract']['committed']);
		$this->assertSame($create['uuid'], $out[0]['json']['contract']['contractUuid']);
		$this->assertTrue($out[1]['json']['contract']['committed']);
		$this->assertSame('c-2', $out[1]['json']['contract']['contractUuid']);
		$this->assertArrayNotHasKey('committed', $out[2]['json']['contract']);
		$this->assertArrayNotHasKey('contract', $out[3]['json']);

	}//end testCommitsThePageInOneBulkCall()

	/**
	 * A page of skips makes no service call and passes every item through.
	 *
	 * @return void
	 */
	public function testEmptyBatchMakesNoServiceCall(): void {
		$this->givenOwner();

		$this->contractService->expects($this->never())->method('persistBulk');

		$out = $this->node->execute(
			[
				['json' => ['uuid' => 'obj-1', 'contract' => ['outcome' => 'skip']]],
				['json' => ['uuid' => 'obj-2', 'contract' => ['outcome' => 'invalid']]],
			],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

		$this->assertCount(2, $out);
		$this->assertArrayNotHasKey('committed', $out[0]['json']['contract']);
		$this->assertArrayNotHasKey('committed', $out[1]['json']['contract']);

	}//end testEmptyBatchMakesNoServiceCall()

	/**
	 * An update without its contract uuid raises under the default `stop`.
	 *
	 * @return void
	 */
	public function testUpdateWithoutContractUuidRaisesUnderStop(): void {
		$this->givenOwner();

		$this->contractService->expects($this->never())->method('persistBulk');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/duplicate/');

		$this->node->execute(
			[['json' => ['uuid' => 'obj-1', 'contract' => ['outcome' => 'update', 'originId' => 'o-1']]]],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

	}//end testUpdateWithoutContractUuidRaisesUnderStop()

	/**
	 * Under `continue` that same item carries error state while the rest commit.
	 *
	 * @return void
	 */
	public function testUpdateWithoutContractUuidContinuesWithErrorState(): void {
		$this->givenOwner();

		$batches = [];
		$this->contractService->expects($this->once())
			->method('persistBulk')
			->willReturnCallback(
				static function (array $contracts) use (&$batches): array {
					$batches[] = $contracts;

					return $contracts;
				}
			);

		$out = $this->node->execute(
			[
				['json' => ['uuid' => 'obj-1', 'contract' => ['outcome' => 'update', 'originId' => 'o-1']]],
				[
					'json' => [
						'uuid' => 'obj-2',
						'contract' => ['outcome' => 'create', 'originId' => 'o-2', 'originHash' => 'h-2'],
					],
				],
			],
			['synchronization' => 'demo-sync', 'onError' => 'continue'],
			$this->context()
		);

		$this->assertCount(1, $batches);
		$this->assertCount(1, $batches[0]);
		$this->assertSame('o-2', $batches[0][0]['originId']);

		$this->assertSame('contract', $out[0]['json'][FlowNodeSupport::ERROR_KEY]['kind']);
		$this->assertArrayNotHasKey('committed', $out[0]['json']['contract']);
		$this->assertTrue($out[1]['json']['contract']['committed']);

	}//end testUpdateWithoutContractUuidContinuesWithErrorState()

	/**
	 * An empty `targetHashPosition` is rejected at save, like its siblings.
	 *
	 * @return void
	 */
	public function testValidateRejectsEmptyTargetHashPosition(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/targetHashPosition/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'targetHashPosition' => ' ']);

	}//end testValidateRejectsEmptyTargetHashPosition()

	/**
	 * With a `targetHashPosition` set, the committed contract carries the
	 * legacy engine's hash of the MAPPED object — and nothing else.
	 *
	 * The expected value is built the way `SynchronizationService::updateTarget()`
	 * builds it: `md5(serialize(...))` over the value AS IT STANDS. The test
	 * also pins that the recipe does NOT key-sort, by asserting the stored hash
	 * differs from the sorted variant — without that second assertion the first
	 * one passes for either recipe, and the whole point is byte parity with
	 * contracts the legacy engine wrote.
	 *
	 * @return void
	 */
	public function testTargetHashIsTheLegacyHashOfTheMappedObject(): void {
		$this->givenOwner();

		// Deliberately NOT in key order, so sorted and unsorted differ.
		$mapped = ['title' => 'A tender', 'cycle' => '8.5', 'archived' => false];
		$sorted = $mapped;
		ksort($sorted);

		$batches = [];
		$this->contractService->expects($this->once())
			->method('persistBulk')
			->willReturnCallback(
				static function (array $contracts) use (&$batches): array {
					$batches[] = $contracts;

					return $contracts;
				}
			);

		$this->node->execute(
			[
				[
					'json' => [
						'uuid' => 'obj-1',
						'target' => $mapped,
						'contract' => ['outcome' => 'create', 'originId' => 'o-1', 'originHash' => 'h-1'],
					],
				],
			],
			[
				'synchronization' => 'demo-sync',
				'targetHashPosition' => 'target',
			],
			$this->context()
		);

		$this->assertSame(md5(serialize($mapped)), $batches[0][0]['targetHash']);
		$this->assertNotSame(md5(serialize($sorted)), $batches[0][0]['targetHash']);

	}//end testTargetHashIsTheLegacyHashOfTheMappedObject()

	/**
	 * A nested `targetHashPosition` resolves, and hashing the WRITTEN object
	 * instead of the mapped one is what the key exists to avoid.
	 *
	 * The second assertion is the guard rail: a written object carries
	 * `@self.updated`, so its hash moves on every pass. Pinning that the two
	 * hashes differ keeps a future edit from quietly repointing the path at
	 * `written` and reintroducing the never-skips defect.
	 *
	 * @return void
	 */
	public function testNestedPathResolvesAndDiffersFromTheWrittenObject(): void {
		$this->givenOwner();

		$mapped = ['title' => 'A tender'];
		$written = ['title' => 'A tender', '@self' => ['updated' => '2026-08-19T17:27:50+00:00']];

		$batches = [];
		$this->contractService->expects($this->once())
			->method('persistBulk')
			->willReturnCallback(
				static function (array $contracts) use (&$batches): array {
					$batches[] = $contracts;

					return $contracts;
				}
			);

		$this->node->execute(
			[
				[
					'json' => [
						'uuid' => 'obj-1',
						'payload' => ['mapped' => $mapped],
						'written' => $written,
						'contract' => ['outcome' => 'create', 'originId' => 'o-1', 'originHash' => 'h-1'],
					],
				],
			],
			[
				'synchronization' => 'demo-sync',
				'targetHashPosition' => 'payload.mapped',
			],
			$this->context()
		);

		$this->assertSame(md5(serialize($mapped)), $batches[0][0]['targetHash']);
		$this->assertNotSame(md5(serialize($written)), $batches[0][0]['targetHash']);

	}//end testNestedPathResolvesAndDiffersFromTheWrittenObject()

	/**
	 * No `targetHashPosition` means no `targetHash` key at all — the behaviour
	 * every flow authored before this key existed keeps.
	 *
	 * @return void
	 */
	public function testNoTargetHashPositionCommitsNoTargetHash(): void {
		$this->givenOwner();

		$batches = [];
		$this->contractService->expects($this->once())
			->method('persistBulk')
			->willReturnCallback(
				static function (array $contracts) use (&$batches): array {
					$batches[] = $contracts;

					return $contracts;
				}
			);

		$this->node->execute(
			[
				[
					'json' => [
						'uuid' => 'obj-1',
						'target' => ['title' => 'A tender'],
						'contract' => ['outcome' => 'create', 'originId' => 'o-1', 'originHash' => 'h-1'],
					],
				],
			],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

		$this->assertArrayNotHasKey('targetHash', $batches[0][0]);

	}//end testNoTargetHashPositionCommitsNoTargetHash()

	/**
	 * A path that resolves to nothing, or to a scalar, stores NO hash.
	 *
	 * Storing `md5(serialize(null))` would be a constant every item shares, so
	 * the match step would read "unchanged" off objects it has never compared.
	 * Omitting the key is the honest answer.
	 *
	 * @return void
	 */
	public function testUnresolvableOrScalarTargetHashPathStoresNoHash(): void {
		$this->givenOwner();

		$batches = [];
		$this->contractService->expects($this->once())
			->method('persistBulk')
			->willReturnCallback(
				static function (array $contracts) use (&$batches): array {
					$batches[] = $contracts;

					return $contracts;
				}
			);

		$this->node->execute(
			[
				[
					'json' => [
						'uuid' => 'obj-1',
						'contract' => ['outcome' => 'create', 'originId' => 'o-1', 'originHash' => 'h-1'],
					],
				],
				[
					'json' => [
						'uuid' => 'obj-2',
						'target' => 'not-an-array',
						'contract' => ['outcome' => 'create', 'originId' => 'o-2', 'originHash' => 'h-2'],
					],
				],
			],
			['synchronization' => 'demo-sync', 'targetHashPosition' => 'target'],
			$this->context()
		);

		$this->assertArrayNotHasKey('targetHash', $batches[0][0]);
		$this->assertArrayNotHasKey('targetHash', $batches[0][1]);

	}//end testUnresolvableOrScalarTargetHashPathStoresNoHash()

	/**
	 * A run context naming an owner and a step.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['triggeredBy' => 'alice', 'stepId' => 'step-commit'];
	}//end context()

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
}//end class
