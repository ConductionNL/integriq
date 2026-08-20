<?php

/**
 * Unit tests for SynchronizationRunNode (`openconnector.synchronization-run`).
 *
 * The output shape is the part consumers must plan for, so most of this file
 * is about it: one item per synchronised object, the run counts on EVERY item,
 * exactly one summary-only item for a zero-object run (never zero items), and
 * a ceiling that raises rather than truncating.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Flow;

use OCA\OpenConnector\Exception\FlowNodeException;
use OCA\OpenConnector\Flow\FlowNodeSupport;
use OCA\OpenConnector\Flow\FlowOwner;
use OCA\OpenConnector\Flow\SynchronizationRunNode;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * Tests for the synchronisation-run flow node.
 */
class SynchronizationRunNodeTest extends TestCase {

	/**
	 * The synchronisation engine double.
	 *
	 * @var SynchronizationService&MockObject
	 */
	private $synchronizationService;

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * The user session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The node under test.
	 *
	 * @var SynchronizationRunNode
	 */
	private SynchronizationRunNode $node;

	/**
	 * Build the node with doubles for everything it delegates to.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->synchronizationService = $this->createMock(SynchronizationService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

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

		$this->node = new SynchronizationRunNode(
			synchronizationService: $this->synchronizationService,
			flowOwner: new FlowOwner(
				userManager: $this->userManager,
				userSession: $this->userSession,
				l10n: $l10n
			),
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->logger
		);

	}//end setUp()

	/**
	 * The palette metadata is present and app-namespaced.
	 *
	 * @return void
	 */
	public function testPaletteMetadata(): void {
		$this->assertSame('openconnector.synchronization-run', $this->node->getId());
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());
		$this->assertNotSame('', $this->node->getIcon());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($this->node->isAvailableForScope(-1));

	}//end testPaletteMetadata()


	/**
	 * The declared vocabulary is what the node actually reads.
	 *
	 * The preflight refuses keys outside this list and the flow editor renders
	 * one field per entry, so a key the node reads but does not declare becomes
	 * unreachable through both — this pins the two against each other.
	 *
	 * @return void
	 */
	public function testConfigKeysNameTheVocabularyTheNodeReads(): void {
		$this->assertSame(
			['synchronization', 'force', 'output', 'maxItems', 'onError'],
			$this->node->configKeys()
		);

	}//end testConfigKeysNameTheVocabularyTheNodeReads()

	/**
	 * Every form field edits a key the node reads, and `synchronization` is a
	 * picker fed by the app's own listing — never a bare uuid box.
	 *
	 * @return void
	 */
	public function testConfigFormDescribesOnlyKeysTheNodeReads(): void {
		$form = $this->node->configForm();
		$keys = $this->node->configKeys();

		$this->assertNotSame([], $form);
		$byKey = [];
		foreach ($form as $field) {
			$this->assertContains($field['key'], $keys);
			$byKey[$field['key']] = $field;
		}

		$this->assertSame('select', $byKey['synchronization']['type']);
		$this->assertTrue($byKey['synchronization']['required']);
		$this->assertNotSame('', (string) ($byKey['synchronization']['optionsFrom'] ?? ''));

	}//end testConfigFormDescribesOnlyKeysTheNodeReads()

	/**
	 * An inline synchronization definition is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsInlineDefinition(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/inline definition/');

		$this->node->validateConfig(['synchronization' => ['name' => 'inline']]);

	}//end testValidateRejectsInlineDefinition()

	/**
	 * A step naming no synchronization is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMissingSynchronization(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/synchronization/');

		$this->node->validateConfig(['output' => 'syncResult']);

	}//end testValidateRejectsMissingSynchronization()

	/**
	 * A credential- or owner-bearing field is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsForbiddenFields(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(['synchronization' => 'demo', 'apiKey' => 'nope']);

	}//end testValidateRejectsForbiddenFields()

	/**
	 * A malformed `maxItems` is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMalformedMaxItems(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/maxItems/');

		$this->node->validateConfig(['synchronization' => 'demo', 'maxItems' => 0]);

	}//end testValidateRejectsMalformedMaxItems()

	/**
	 * Three synchronised objects produce three items, each carrying the summary.
	 *
	 * @return void
	 */
	public function testThreeObjectsProduceThreeItems(): void {
		$this->givenSynchronization();
		$this->givenOwner();

		$this->synchronizationService->method('synchronize')->willReturn(
			$this->runLog(
				contracts: [
					['uuid' => 'c-1', 'originId' => 'o-1', 'targetId' => 't-1', 'targetLastAction' => 'create'],
					['uuid' => 'c-2', 'originId' => 'o-2', 'targetId' => 't-2', 'targetLastAction' => 'update'],
					['uuid' => 'c-3', 'originId' => 'o-3', 'targetId' => 't-3', 'targetLastAction' => null],
				],
				counts: ['found' => 3, 'created' => 1, 'updated' => 1, 'skipped' => 1]
			)
		);

		$out = $this->node->execute(
			[['json' => ['trigger' => 'manual']]],
			['synchronization' => 'demo-sync', 'output' => 'syncResult'],
			$this->context()
		);

		$this->assertCount(3, $out);

		foreach ($out as $item) {
			$this->assertSame(['item' => 0], $item['pairedItem']);
			$this->assertSame(3, $item['json']['syncResult']['summary']['found']);
			$this->assertSame(1, $item['json']['syncResult']['summary']['created']);
			$this->assertFalse($item['json']['syncResult']['summaryOnly']);
		}

		$this->assertSame('t-1', $out[0]['json']['syncResult']['objectId']);
		$this->assertSame('create', $out[0]['json']['syncResult']['outcome']);
		$this->assertSame('update', $out[1]['json']['syncResult']['outcome']);
		$this->assertSame('unchanged', $out[2]['json']['syncResult']['outcome']);
		$this->assertSame('o-1', $out[0]['json']['syncResult']['object']['originId']);

	}//end testThreeObjectsProduceThreeItems()

	/**
	 * A zero-object run emits exactly one summary-only item, never nothing.
	 *
	 * @return void
	 */
	public function testZeroObjectsEmitsOneSummaryOnlyItem(): void {
		$this->givenSynchronization();
		$this->givenOwner();

		$this->synchronizationService->method('synchronize')->willReturn(
			$this->runLog(contracts: [], counts: [])
		);

		$out = $this->node->execute(
			[['json' => []]],
			['synchronization' => 'demo-sync', 'output' => 'syncResult'],
			$this->context()
		);

		$this->assertCount(1, $out);
		$this->assertTrue($out[0]['json']['syncResult']['summaryOnly']);
		$this->assertSame(0, $out[0]['json']['syncResult']['summary']['found']);
		$this->assertArrayNotHasKey('objectId', $out[0]['json']['syncResult']);

	}//end testZeroObjectsEmitsOneSummaryOnlyItem()

	/**
	 * Exceeding the ceiling raises loudly and returns no truncated subset.
	 *
	 * @return void
	 */
	public function testCeilingBreachRaisesAndNeverTruncates(): void {
		$this->givenSynchronization();
		$this->givenOwner();

		$this->synchronizationService->method('synchronize')->willReturn(
			$this->runLog(contracts: $this->contracts(count: 10000), counts: ['found' => 10000, 'created' => 10000])
		);

		$caught = null;
		try {
			$this->node->execute(
				[['json' => []]],
				['synchronization' => 'demo-sync', 'output' => 'syncResult'],
				$this->context()
			);
		} catch (FlowNodeException $exception) {
			$caught = $exception;
		}

		$this->assertInstanceOf(FlowNodeException::class, $caught);
		$this->assertStringContainsString('10000', $caught->getMessage());
		$this->assertStringContainsString('1000', $caught->getMessage());
		$this->assertStringContainsString('step-sync', $caught->getMessage());
		$this->assertStringContainsString('demo-sync', $caught->getMessage());
		$this->assertStringContainsString('WERE synchronised', $caught->getMessage());
		$this->assertSame(10000, $caught->getDetails()['objectCount']);
		$this->assertSame(1000, $caught->getDetails()['maxItems']);
		$this->assertTrue($caught->getDetails()['synchronised']);

	}//end testCeilingBreachRaisesAndNeverTruncates()

	/**
	 * A raised ceiling emits the full list and logs the growth warning.
	 *
	 * @return void
	 */
	public function testRaisedCeilingEmitsEverythingAndWarns(): void {
		$this->givenSynchronization();
		$this->givenOwner();

		$this->synchronizationService->method('synchronize')->willReturn(
			$this->runLog(contracts: $this->contracts(count: 300), counts: ['found' => 300, 'created' => 300])
		);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$out = $this->node->execute(
			[['json' => []]],
			['synchronization' => 'demo-sync', 'output' => 'syncResult', 'maxItems' => 20000],
			$this->context()
		);

		$this->assertCount(300, $out);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('300', $warnings[0]);
		$this->assertStringContainsString('step-sync', $warnings[0]);
		$this->assertStringContainsString('demo-sync', $warnings[0]);

	}//end testRaisedCeilingEmitsEverythingAndWarns()

	/**
	 * A fan-out below the warning threshold logs nothing.
	 *
	 * @return void
	 */
	public function testSmallFanOutDoesNotWarn(): void {
		$this->givenSynchronization();
		$this->givenOwner();

		$this->synchronizationService->method('synchronize')->willReturn(
			$this->runLog(contracts: $this->contracts(count: 10), counts: ['found' => 10])
		);

		$this->logger->expects($this->never())->method('warning');

		$out = $this->node->execute([['json' => []]], ['synchronization' => 'demo-sync'], $this->context());

		$this->assertCount(10, $out);

	}//end testSmallFanOutDoesNotWarn()

	/**
	 * A failing synchronisation raises so `onError` decides.
	 *
	 * @return void
	 */
	public function testFailedSynchronizationRaises(): void {
		$this->givenSynchronization();
		$this->givenOwner();

		$this->synchronizationService->method('synchronize')
			->willThrowException(new RuntimeException('source unreachable'));

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/source unreachable/');

		$this->node->execute([['json' => []]], ['synchronization' => 'demo-sync'], $this->context());

	}//end testFailedSynchronizationRaises()

	/**
	 * Under `continue` a failure lands as explicit error state, not a summary.
	 *
	 * @return void
	 */
	public function testContinuePolicyCarriesExplicitErrorState(): void {
		$this->givenSynchronization();
		$this->givenOwner();

		$this->synchronizationService->method('synchronize')
			->willThrowException(new RuntimeException('source unreachable'));

		$out = $this->node->execute(
			[['json' => []]],
			['synchronization' => 'demo-sync', 'output' => 'syncResult', 'onError' => 'continue'],
			$this->context()
		);

		$this->assertCount(1, $out);
		$this->assertArrayNotHasKey('syncResult', $out[0]['json']);
		$this->assertSame('demo-sync', $out[0]['json'][FlowNodeSupport::ERROR_KEY]['synchronization']);
		$this->assertSame('step-sync', $out[0]['json'][FlowNodeSupport::ERROR_KEY]['step']);

	}//end testContinuePolicyCarriesExplicitErrorState()

	/**
	 * An unattributed run refuses and starts no synchronisation.
	 *
	 * @return void
	 */
	public function testUnattributedRunStartsNoSynchronization(): void {
		$this->synchronizationService->expects($this->never())->method('synchronize');
		$this->synchronizationService->expects($this->never())->method('getSynchronization');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/unattributed/');

		$this->node->execute([['json' => []]], ['synchronization' => 'demo-sync'], []);

	}//end testUnattributedRunStartsNoSynchronization()

	/**
	 * An unresolvable synchronization raises and starts nothing.
	 *
	 * @return void
	 */
	public function testUnknownSynchronizationRaises(): void {
		$this->givenOwner();
		$this->synchronizationService->method('getSynchronization')
			->willThrowException(new RuntimeException('does not exist'));
		$this->synchronizationService->expects($this->never())->method('synchronize');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/no-such-sync/');

		$this->node->execute([['json' => []]], ['synchronization' => 'no-such-sync'], $this->context());

	}//end testUnknownSynchronizationRaises()

	/**
	 * An empty input list runs nothing and returns nothing.
	 *
	 * @return void
	 */
	public function testEmptyInputRunsNothing(): void {
		$this->synchronizationService->expects($this->never())->method('synchronize');

		$this->assertSame([], $this->node->execute([], ['synchronization' => 'demo-sync'], $this->context()));

	}//end testEmptyInputRunsNothing()

	/**
	 * A run context naming an owner and a step.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['triggeredBy' => 'alice', 'stepId' => 'step-sync'];
	}//end context()

	/**
	 * Make the service resolve a Synchronization.
	 *
	 * @return void
	 */
	private function givenSynchronization(): void {
		$synchronization = new ObjectEntity();
		$synchronization->setUuid('33333333-3333-3333-3333-333333333333');
		$synchronization->setObject(['name' => 'Demo synchronization']);

		$this->synchronizationService->method('getSynchronization')->willReturn($synchronization);

	}//end givenSynchronization()

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
	 * Build a run log shaped as `SynchronizationService::synchronize()` returns one.
	 *
	 * @param array $contracts The resolved contract payloads.
	 * @param array $counts The object counts.
	 *
	 * @return array<string, mixed> The run log.
	 */
	private function runLog(array $contracts, array $counts): array {
		$ids = array_map(
			static function (array $contract) {
				return ($contract['uuid'] ?? null);
			},
			$contracts
		);

		return [
			'uuid' => '44444444-4444-4444-4444-444444444444',
			'message' => 'Success',
			'synchronizationId' => '33333333-3333-3333-3333-333333333333',
			'result' => [
				'objects' => array_merge(
					[
						'found' => 0,
						'skipped' => 0,
						'created' => 0,
						'updated' => 0,
						'deleted' => 0,
						'invalid' => 0,
					],
					$counts
				),
				'contracts' => $ids,
				'logs' => [],
				'_embed' => ['contracts' => $contracts],
			],
		];

	}//end runLog()

	/**
	 * Build N synthetic contract payloads.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, array<string, mixed>> The contracts.
	 */
	private function contracts(int $count): array {
		$contracts = [];
		for ($index = 0; $index < $count; $index++) {
			$contracts[] = [
				'uuid' => 'c-' . $index,
				'originId' => 'o-' . $index,
				'targetId' => 't-' . $index,
				'targetLastAction' => 'create',
			];
		}

		return $contracts;
	}//end contracts()

}//end class
