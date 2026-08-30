<?php

/**
 * Unit tests for ContractSweepNode (`openconnector.contract-sweep`).
 *
 * The core semantics under test: the collected target ids and the
 * completeness/force flags reach `deleteInvalidObjects()` exactly as
 * configured, the single summary item surfaces the service's own guard
 * verdict, and an unresolved `fetchComplete` path fails CLOSED.
 *
 * The service double is a real subclass rather than a PHPUnit mock: the
 * node reads the by-reference `guardInfo` output parameter, and a mock's
 * invocation layer cannot write one back to the caller.
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
use OCA\Integriq\Flow\ContractSweepNode;
use OCA\Integriq\Flow\FlowNodeSupport;
use OCA\Integriq\Flow\FlowOwner;
use OCA\Integriq\Service\SynchronizationService;
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
use Throwable;
use UnexpectedValueException;

/**
 * Tests for the contract-sweep flow node.
 */
class ContractSweepNodeTest extends TestCase {

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
	 * @var ContractSweepNode
	 */
	private ContractSweepNode $node;

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
		$urlGenerator->method('imagePath')->willReturn('/apps/integriq/img/flow-synchronization-run.svg');

		$this->node = new ContractSweepNode(
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
		$this->assertSame('openconnector.contract-sweep', $this->node->getId());
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
			['synchronization', 'targetIdsPosition', 'fetchComplete', 'force', 'onError'],
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

		$this->node->validateConfig(['force' => true]);

	}//end testValidateRejectsMissingSynchronization()

	/**
	 * A non-boolean `force` is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsMalformedForce(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/force/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'force' => 'yes']);

	}//end testValidateRejectsMalformedForce()

	/**
	 * An empty `fetchComplete` is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsEmptyFetchComplete(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/fetchComplete/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'fetchComplete' => ' ']);

	}//end testValidateRejectsEmptyFetchComplete()

	/**
	 * A blank `targetIdsPosition` is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateRejectsEmptyTargetIdsPosition(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/targetIdsPosition/');

		$this->node->validateConfig(['synchronization' => 'demo-sync', 'targetIdsPosition' => '']);

	}//end testValidateRejectsEmptyTargetIdsPosition()

	/**
	 * Under `continue` a failed sweep lands as ONE explicit error item, and a
	 * failure that is already a node failure passes through unchanged.
	 *
	 * @return void
	 */
	public function testFailingSweepContinuesWithErrorItem(): void {
		$this->givenOwner();

		$this->synchronizationService->throwOnDelete = new FlowNodeException(
			message: 'sweep exploded',
			details: ['kind' => 'sweep']
		);

		$out = $this->node->execute(
			[['json' => ['uuid' => 'u-1']]],
			['synchronization' => 'demo-sync', 'onError' => 'continue'],
			$this->context()
		);

		$this->assertCount(1, $out);
		$this->assertArrayNotHasKey('sweep', $out[0]['json']);

		$error = $out[0]['json'][FlowNodeSupport::ERROR_KEY];
		$this->assertSame('sweep', $error['kind']);
		$this->assertSame('sweep exploded', $error['message']);
		$this->assertSame('step-sweep', $error['step']);
		$this->assertSame('openconnector.contract-sweep', $error['node']);
		$this->assertSame('demo-sync', $error['synchronization']);

	}//end testFailingSweepContinuesWithErrorItem()

	/**
	 * The collected ids and flags reach the guarded deletion; the summary
	 * carries the service's verdict.
	 *
	 * @return void
	 */
	public function testSweepPassesCollectedIdsAndFlags(): void {
		$this->givenOwner();

		$this->synchronizationService->guardToWrite = [
			'guarded' => false,
			'reason' => null,
			'ratio' => 0.1,
			'threshold' => 0.5,
			'candidateCount' => 2,
			'totalContracts' => 20,
		];
		$this->synchronizationService->deleteReturn = 2;

		$out = $this->node->execute(
			[
				['json' => ['uuid' => 'u-1']],
				['json' => ['uuid' => 'u-2']],
				['json' => ['uuid' => 'u-1']],
				['json' => ['name' => 'no uuid on this one']],
			],
			['synchronization' => 'demo-sync', 'force' => true],
			$this->context()
		);

		$received = $this->synchronizationService->received['deleteInvalidObjects'];
		$this->assertSame(['u-1', 'u-2'], $received['synchronizedTargetIds']);
		$this->assertTrue($received['forceDeletion']);
		$this->assertTrue($received['fetchComplete']);
		$this->assertFalse($received['deleteRestriction']);
		$this->assertSame('Demo synchronization', $received['synchronization']['name']);
		$this->assertSame('demo-sync', $this->synchronizationService->received['getSynchronization']);

		$this->assertCount(1, $out);
		$sweep = $out[0]['json']['sweep'];
		$this->assertSame(2, $sweep['swept']);
		$this->assertFalse($sweep['guarded']);
		$this->assertNull($sweep['guardReason']);
		$this->assertSame(0.1, $sweep['ratio']);
		$this->assertSame(0.5, $sweep['threshold']);
		$this->assertSame(2, $sweep['candidateCount']);
		$this->assertSame(20, $sweep['totalContracts']);
		$this->assertSame(2, $sweep['targetIds']);
		$this->assertTrue($sweep['fetchComplete']);

	}//end testSweepPassesCollectedIdsAndFlags()

	/**
	 * An unresolved `fetchComplete` path fails CLOSED, and the guarded skip is
	 * surfaced — never a silent zero.
	 *
	 * @return void
	 */
	public function testUnresolvedFetchCompleteFailsClosedAndSurfacesTheGuard(): void {
		$this->givenOwner();

		$this->synchronizationService->guardToWrite = [
			'guarded' => true,
			'reason' => 'fetch_incomplete',
			'ratio' => null,
			'threshold' => null,
			'candidateCount' => null,
			'totalContracts' => null,
		];
		$this->synchronizationService->deleteReturn = 0;

		$out = $this->node->execute(
			[['json' => ['uuid' => 'u-1']]],
			['synchronization' => 'demo-sync', 'fetchComplete' => 'fetchInfo.complete'],
			$this->context()
		);

		$received = $this->synchronizationService->received['deleteInvalidObjects'];
		$this->assertFalse($received['fetchComplete']);

		$sweep = $out[0]['json']['sweep'];
		$this->assertSame(0, $sweep['swept']);
		$this->assertTrue($sweep['guarded']);
		$this->assertSame('fetch_incomplete', $sweep['guardReason']);
		$this->assertFalse($sweep['fetchComplete']);

	}//end testUnresolvedFetchCompleteFailsClosedAndSurfacesTheGuard()

	/**
	 * A failing sweep raises under the default `stop` policy.
	 *
	 * @return void
	 */
	public function testFailingSweepRaisesUnderStop(): void {
		$this->givenOwner();

		$this->synchronizationService->throwOnDelete = new RuntimeException('database gone');

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessageMatches('/database gone/');

		$this->node->execute(
			[['json' => ['uuid' => 'u-1']]],
			['synchronization' => 'demo-sync'],
			$this->context()
		);

	}//end testFailingSweepRaisesUnderStop()

	/**
	 * An empty input sweeps nothing and returns nothing.
	 *
	 * @return void
	 */
	public function testEmptyInputSweepsNothing(): void {
		$this->assertSame([], $this->node->execute([], ['synchronization' => 'demo-sync'], $this->context()));
		$this->assertArrayNotHasKey('deleteInvalidObjects', $this->synchronizationService->received);

	}//end testEmptyInputSweepsNothing()

	/**
	 * A run context naming an owner and a step.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['triggeredBy' => 'alice', 'stepId' => 'step-sweep'];
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
			 * The guard verdict `deleteInvalidObjects()` writes back.
			 *
			 * @var array|null
			 */
			public ?array $guardToWrite = null;

			/**
			 * The deletion count `deleteInvalidObjects()` returns.
			 *
			 * @var int
			 */
			public int $deleteReturn = 0;

			/**
			 * When set, `deleteInvalidObjects()` throws this instead.
			 *
			 * @var Throwable|null
			 */
			public ?Throwable $throwOnDelete = null;

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
			 */
			public function getSynchronization(null|string|int $id = null, array $filters = []): ObjectEntity {
				$this->received['getSynchronization'] = $id;

				$entity = new ObjectEntity();
				$entity->setUuid('33333333-3333-3333-3333-333333333333');
				$entity->setObject(['name' => 'Demo synchronization', 'targetType' => 'register/schema']);

				return $entity;
			}//end getSynchronization()

			/**
			 * Record the arguments, write the guard verdict back, and return
			 * the configured count.
			 *
			 * @param array|ObjectEntity $synchronization The synchronization.
			 * @param array|null $synchronizedTargetIds The still-valid target ids.
			 * @param bool $deleteRestriction Restriction flag.
			 * @param array $data Restriction data.
			 * @param bool $fetchComplete Completeness verdict.
			 * @param bool $forceDeletion Ratio-guard override.
			 * @param array|null $guardInfo By-reference guard verdict.
			 *
			 * @return int The configured deletion count.
			 *
			 * @throws Throwable When the test configured a failure.
			 */
			public function deleteInvalidObjects(
				array|ObjectEntity $synchronization,
				?array $synchronizedTargetIds = [],
				bool $deleteRestriction = false,
				array $data = [],
				bool $fetchComplete = true,
				bool $forceDeletion = false,
				?array &$guardInfo = null,
			): int {
				if ($this->throwOnDelete !== null) {
					throw $this->throwOnDelete;
				}

				$this->received['deleteInvalidObjects'] = [
					'synchronization' => $synchronization,
					'synchronizedTargetIds' => $synchronizedTargetIds,
					'deleteRestriction' => $deleteRestriction,
					'data' => $data,
					'fetchComplete' => $fetchComplete,
					'forceDeletion' => $forceDeletion,
				];

				$guardInfo = $this->guardToWrite;

				return $this->deleteReturn;
			}//end deleteInvalidObjects()
		};
	}//end serviceDouble()
}//end class
