<?php

/**
 * The six curated agent tools, as OpenRegister sees them and as they behave.
 *
 * Real classes wherever integriq owns them: OpenRegister's own
 * AttributeToolScanner reads the catalogue, the real ActionAuthService runs the
 * matrix from the real seed, the real ApprovalVerdictVerifier checks real
 * Ed25519 signatures from a Hermiq fake that answers the contract, and every
 * record written is validated against the merged register. The service paths
 * the tools delegate to are doubles: what is asserted is that they are called
 * (or not) and with whom.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Mcp;

use InvalidArgumentException;
use OCA\Integriq\Mcp\IntegriqAgentTools;
use OCA\Integriq\Mcp\IntegriqScannableServices;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\AgentTools\AgentActionRefusedException;
use OCA\Integriq\Service\AgentTools\AgentActionStore;
use OCA\Integriq\Service\AgentTools\ApprovalVerdictVerifier;
use OCA\Integriq\Service\AgentTools\DeadLetterProjection;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\SourceTestService;
use OCA\Integriq\Service\SyncItemDeadLetterService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\AttributeToolScanner;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An in-memory OpenRegister: objects by schema and uuid.
 */
class InMemoryObjects extends ObjectService {

	/** @var array<string,array<string,array<string,mixed>>> */
	public array $objects = [];

	private int $next = 0;

	public function __construct() {
	}

	public function seed(string $schema, string $uuid, array $data): void {
		$this->objects[$schema][$uuid] = $data;
	}

	public function find($id, ?string $register=null, ?string $schema=null, bool $_rbac=true, bool $_multitenancy=true, bool $_render=true, bool $_audit=true, ?array $_extend=[]): ?ObjectEntity {
		if (isset($this->objects[$schema][$id]) === false) {
			throw new DoesNotExistException('missing');
		}

		return $this->entity(uuid: (string)$id, data: $this->objects[$schema][$id]);
	}

	public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
		$filters = $config['filters'];
		$rows    = [];
		foreach (($this->objects[$filters['schema']] ?? []) as $uuid => $data) {
			if (($data['status'] ?? null) !== $filters['status']) {
				continue;
			}

			if (isset($filters['synchronization']) === true && ($data['synchronization'] ?? null) !== $filters['synchronization']) {
				continue;
			}

			$rows[] = $this->entity(uuid: $uuid, data: $data);
		}

		return ['results' => $rows, 'total' => count($rows)];
	}

	public function saveObject($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false, bool $_validation=true): ObjectEntity {
		if ($uuid === null) {
			$this->next++;
			$uuid = sprintf('00000000-0000-4000-8000-%012d', $this->next);
		}

		$this->objects[$schema][$uuid] = $object;
		return $this->entity(uuid: $uuid, data: $object);
	}

	/** @return array<int,array<string,mixed>> */
	public function actions(): array {
		return array_values($this->objects['agent_action'] ?? []);
	}

	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}
}

class IntegriqAgentToolsTest extends TestCase {

	private const SYNC   = '7b3c4d50-0000-4000-8000-000000000001';
	private const DL_A   = '5d1e2f30-0000-4000-8000-00000000d001';
	private const DL_B   = '5d1e2f30-0000-4000-8000-00000000d002';
	private const DL_C   = '5d1e2f30-0000-4000-8000-00000000d003';
	private const AGENT  = 'a1f0c2d3-0000-4000-8000-00000000a001';
	private const SOURCE = '9c8b7a60-0000-4000-8000-000000000001';

	private InMemoryObjects $objects;
	private FakeHermiq $hermiq;
	private string $uid = 'admin';

	/** @var SynchronizationService&MockObject */
	private SynchronizationService $synchronization;

	/** @var SyncItemDeadLetterService&MockObject */
	private SyncItemDeadLetterService $syncDeadLetters;

	/** @var EventService&MockObject */
	private EventService $events;

	/** @var SourceTestService&MockObject */
	private SourceTestService $sourceTest;

	protected function setUp(): void {
		$this->objects = new InMemoryObjects();
		$this->objects->seed('synchronization', self::SYNC, ['name' => 'Nightly zaken']);
		$this->objects->seed('source', self::SOURCE, ['name' => 'Supplier API']);
		foreach ([self::DL_A, self::DL_B, self::DL_C] as $i => $id) {
			$this->objects->seed('sync_item_dead_letter', $id, [
				'synchronization' => self::SYNC,
				'phase'           => 'write',
				'error'           => str_repeat('429 Too Many Requests from upstream. ', 20),
				'payload'         => ['bsn' => '999990019', 'secret' => 'raw upstream data'],
				'status'          => 'failed',
				'retryCount'      => $i,
				'attempts'        => [['at' => '2026-09-30T01:00:00+02:00']],
				'created'         => '2026-09-30T01:00:00+02:00',
			]);
		}

		$this->objects->seed('event_message', 'e1', [
			'subscription' => 's1', 'status' => 'failed', 'payload' => ['x' => 1],
			'lastResponse' => 'HTTP 500 body with data', 'retryCount' => 3, 'created' => '2026-09-30T01:00:00+02:00',
		]);

		$this->hermiq          = new FakeHermiq();
		$this->synchronization = $this->createMock(SynchronizationService::class);
		$this->syncDeadLetters = $this->createMock(SyncItemDeadLetterService::class);
		$this->events          = $this->createMock(EventService::class);
		$this->sourceTest      = $this->createMock(SourceTestService::class);
	}

	private function tools(): IntegriqAgentTools {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		// The real matrix, read from an app config that holds nothing, so the seed applies.
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($app === 'hermiq' && $key === 'approval_verdict_public_key') ? $this->hermiq->publicKey() : $default
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('getUserGroupIds')->willReturn(['griffie']);

		return new IntegriqAgentTools(
			userSession: $session,
			actionAuth: new ActionAuthService(appConfig: $config, groupManager: $groups),
			objectService: $this->objects,
			synchronization: $this->synchronization,
			sourceTest: $this->sourceTest,
			syncDeadLetters: $this->syncDeadLetters,
			events: $this->events,
			store: new AgentActionStore(objectService: $this->objects),
			verifier: new ApprovalVerdictVerifier(client: $this->hermiq, appConfig: $config),
			projection: new DeadLetterProjection()
		);
	}

	private function approve(array $staged, string $approvalId, string $status='approved', string $decidedBy='beheerder', string $agent=self::AGENT): void {
		$this->hermiq->approvals[$approvalId] = [
			'toolId' => $staged['tool'], 'binding' => $staged['binding'], 'status' => $status,
			'decidedBy' => $decidedBy, 'agent' => $agent,
		];
	}

	private function assertEveryRecordValidates(): void {
		foreach ($this->objects->actions() as $record) {
			$this->assertSame([], RegisterSchemaValidator::errors('agent_action', $record), json_encode($record));
		}
	}

	// ---- REQ-MCP-105 / REQ-MCP-101: the catalogue, read by OpenRegister's own scanner.

	public function testOpenRegisterFindsExactlySixToolsWithTheirScopeAndHints(): void {
		$this->assertSame([IntegriqAgentTools::class], (new IntegriqScannableServices())->getScannableServiceClasses());

		$tools = (new AttributeToolScanner())->scanClasses(appId: 'integriq', classNames: [IntegriqAgentTools::class], logger: new NullLogger());
		$byId  = array_column($tools, null, 'id');
		ksort($byId);
		$this->assertSame(
			['integriq.discardDeadLetters', 'integriq.listDeadLetters', 'integriq.replayDeadLetters', 'integriq.runSynchronization', 'integriq.testSource', 'integriq.testSynchronization'],
			array_keys($byId)
		);

		$this->assertSame('update', $byId['integriq.runSynchronization']['scope']);
		$this->assertSame('update', $byId['integriq.replayDeadLetters']['scope']);
		$this->assertSame('delete', $byId['integriq.discardDeadLetters']['scope']);
		$this->assertTrue($byId['integriq.discardDeadLetters']['destructiveHint']);
		foreach (['testSynchronization', 'testSource', 'listDeadLetters'] as $read) {
			$this->assertSame('read', $byId['integriq.' . $read]['scope']);
			$this->assertTrue($byId['integriq.' . $read]['readOnlyHint']);
		}

		foreach (['runSynchronization', 'replayDeadLetters', 'discardDeadLetters'] as $gated) {
			$this->assertStringContainsString('Needs approval', $byId['integriq.' . $gated]['description']);
		}

		foreach (IntegriqAgentTools::REACH as $name => $reach) {
			$this->assertStringContainsString('Reach: ' . $reach . '.', $byId['integriq.' . $name]['description']);
		}

		$this->assertSame('external', IntegriqAgentTools::REACH['runSynchronization']);
		$this->assertSame('external', IntegriqAgentTools::REACH['testSynchronization']);
		$this->assertSame('external', IntegriqAgentTools::REACH['testSource']);
		$this->assertSame('external', IntegriqAgentTools::REACH['replayDeadLetters']);
		$this->assertSame('instance', IntegriqAgentTools::REACH['discardDeadLetters']);
	}

	public function testNoDeadLetterSchemaDeclaresTheMcpDialect(): void {
		$schemas = RegisterSchemaValidator::descriptor()['components']['schemas'];
		foreach (['sync_item_dead_letter', 'event_message'] as $slug) {
			$this->assertArrayNotHasKey('x-openregister-mcp', $schemas[$slug], $slug);
		}
	}

	public function testTheSeedGainsTheTwoDeadLetterActionsAdminOnly(): void {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/actions.seed.json'), true)['actions'];
		$this->assertSame(['admin'], $seed['sync-dead-letter.replay']);
		$this->assertSame(['admin'], $seed['sync-dead-letter.discard']);
		$this->assertSame(['admin'], $seed['synchronization.run']);
	}

	public function testForceDeletionIsRefusedAndNothingIsStaged(): void {
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->tools()->runSynchronization(synchronizationId: self::SYNC, agentId: self::AGENT, forceDeletion: true);
		} finally {
			$this->assertSame([], $this->objects->actions());
		}
	}

	public function testAToolTakesIdsNeverContent(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->tools()->replayDeadLetters(ids: [['payload' => 'x']], agentId: self::AGENT);
	}

	// ---- REQ-MCP-106: the matrix first, and gate parity.

	public function testTheMatrixDeniesBeforeAnythingIsStagedAndTheDenialIsRecorded(): void {
		$this->uid = 'griffie';
		try {
			$this->tools()->runSynchronization(synchronizationId: self::SYNC, agentId: self::AGENT);
			$this->fail('The matrix let a non-admin run a synchronization.');
		} catch (OCSForbiddenException $e) {
			$this->assertStringContainsString('synchronization.run', $e->getMessage());
		}

		$actions = $this->objects->actions();
		$this->assertCount(1, $actions);
		$this->assertSame('denied', $actions[0]['outcome']);
		$this->assertSame(self::AGENT, $actions[0]['agent']);
		$this->assertSame('griffie', $actions[0]['grantingUser']);
		$this->assertSame('integriq.runSynchronization', $actions[0]['tool']);
		$this->assertEveryRecordValidates();
	}

	public function testATestRunTheAppRefusesFailsTheSameThroughTheTool(): void {
		$refusal = new \Exception('Source answered 401: the credential is wrong', 400);
		$this->synchronization->expects($this->once())->method('synchronize')
			->with($this->anything(), true, false)
			->willThrowException($refusal);

		try {
			$this->tools()->testSynchronization(synchronizationId: self::SYNC, agentId: self::AGENT);
			$this->fail('The refusal was swallowed.');
		} catch (\Exception $e) {
			$this->assertSame($refusal, $e);
		}

		$this->assertSame('failed', $this->objects->actions()[0]['outcome']);
		$this->assertEveryRecordValidates();
	}

	public function testATestRunAnswersCountsOnly(): void {
		$this->synchronization->method('synchronize')->willReturn([
			'result' => ['objects' => ['found' => 3, 'created' => 1], 'contracts' => [['object' => ['bsn' => '1']]]],
		]);
		$answer = $this->tools()->testSynchronization(synchronizationId: self::SYNC, agentId: self::AGENT);
		$this->assertSame(['synchronization' => self::SYNC, 'objects' => ['found' => 3, 'created' => 1]], $answer);
	}

	public function testASourceTestAnswersStatusNeverTheBody(): void {
		$this->sourceTest->expects($this->once())->method('run')->willReturn([
			'outcome' => 'response', 'result' => ['body' => 'secret upstream'], 'statusCode' => 200, 'statusMessage' => 'OK', 'error' => '',
		]);
		$answer = $this->tools()->testSource(sourceId: self::SOURCE, agentId: self::AGENT);
		$this->assertSame(['source', 'outcome', 'statusCode', 'statusMessage', 'error'], array_keys($answer));
		$this->assertStringNotContainsString('secret upstream', json_encode($answer));
	}

	// ---- REQ-MCP-107: two phases, a verdict bound to the batch.

	public function testPhaseOneStagesTheBatchAndRunsNothing(): void {
		$this->syncDeadLetters->expects($this->never())->method('replayMessage');
		$staged = $this->tools()->replayDeadLetters(ids: [self::DL_A, self::DL_B], agentId: self::AGENT);

		$this->assertSame('staged', $staged['status']);
		$this->assertSame([self::DL_A, self::DL_B], $staged['targetIds']);
		$this->assertSame(IntegriqAgentTools::binding(proposalId: $staged['proposal'], toolId: 'integriq.replayDeadLetters', ids: [self::DL_B, self::DL_A]), $staged['binding']);
		$this->assertSame('staged', $this->objects->objects['agent_action'][$staged['proposal']]['outcome']);
		$this->assertEveryRecordValidates();
	}

	public function testAnApprovedBatchReplaysEachIdAsTheGrantingUserAndIsTraceable(): void {
		$tools  = $this->tools();
		$staged = $tools->replayDeadLetters(ids: [self::DL_A, self::DL_B], agentId: self::AGENT);
		$this->approve(staged: $staged, approvalId: 'appr-1');

		$replayed = [];
		$this->syncDeadLetters->expects($this->exactly(2))->method('replayMessage')
			->willReturnCallback(function (string $id, string $actorUid) use (&$replayed): ObjectEntity {
				$replayed[] = [$id, $actorUid];
				return new ObjectEntity();
			});

		$done = $tools->replayDeadLetters(ids: [self::DL_A, self::DL_B], agentId: self::AGENT, proposalId: $staged['proposal'], approvalId: 'appr-1');

		$this->assertSame([[self::DL_A, 'admin'], [self::DL_B, 'admin']], $replayed);
		$this->assertSame('executed', $done['status']);
		$this->assertSame('beheerder', $done['approvedBy']);

		// "agent A proposed, approver B approved, on behalf of user C" (REQ-MCP-108).
		$trail = $this->objects->objects['agent_action'][$staged['proposal']];
		$this->assertSame('executed', $trail['outcome']);
		$this->assertSame(self::AGENT, $trail['agent']);
		$this->assertSame('admin', $trail['grantingUser']);
		$this->assertSame('beheerder', $trail['approvedBy']);
		$this->assertSame('appr-1', $trail['approval']);
		$this->assertSame('integriq.replayDeadLetters', $trail['tool']);
		$this->assertCount(2, $trail['results']);
		$this->assertEveryRecordValidates();
	}

	public function testOneApprovalRunsItsBatchOnce(): void {
		$tools  = $this->tools();
		$staged = $tools->replayDeadLetters(ids: [self::DL_A], agentId: self::AGENT);
		$this->approve(staged: $staged, approvalId: 'appr-1');
		$this->syncDeadLetters->expects($this->once())->method('replayMessage')->willReturn(new ObjectEntity());

		$tools->replayDeadLetters(ids: [self::DL_A], agentId: self::AGENT, proposalId: $staged['proposal'], approvalId: 'appr-1');
		$this->expectException(AgentActionRefusedException::class);
		$tools->replayDeadLetters(ids: [self::DL_A], agentId: self::AGENT, proposalId: $staged['proposal'], approvalId: 'appr-1');
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public static function refusals(): array {
		return [
			'no token'                  => [static fn (self $t, array $a, array $b): array => [$b['proposal'], null]],
			'a token for another batch' => [static fn (self $t, array $a, array $b): array => [$b['proposal'], 'appr-a']],
			'a rejected batch'          => [static fn (self $t, array $a, array $b): array => [$b['proposal'], 'appr-rejected']],
			'approved by the agent'     => [static fn (self $t, array $a, array $b): array => [$b['proposal'], 'appr-self']],
			'a stale verdict'           => [static fn (self $t, array $a, array $b): array => [$b['proposal'], 'appr-b-stale']],
			'a forged verdict'          => [static fn (self $t, array $a, array $b): array => [$b['proposal'], 'appr-b-forged']],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testPhaseTwoRefusesAndTheBatchStaysStaged(callable $pick): void {
		$tools = $this->tools();
		$a     = $tools->replayDeadLetters(ids: [self::DL_A], agentId: self::AGENT);
		$b     = $tools->replayDeadLetters(ids: [self::DL_B, self::DL_C], agentId: self::AGENT);
		$this->approve(staged: $a, approvalId: 'appr-a');
		$this->approve(staged: $b, approvalId: 'appr-rejected', status: 'rejected');
		$this->approve(staged: $b, approvalId: 'appr-self', decidedBy: self::AGENT);
		$this->approve(staged: $b, approvalId: 'appr-b-stale');
		$this->approve(staged: $b, approvalId: 'appr-b-forged', decidedBy: 'nobody');
		[$proposal, $approval] = $pick($this, $a, $b);
		if ($approval === 'appr-b-stale') {
			$this->hermiq->clockSkew = -(ApprovalVerdictVerifier::MAX_AGE_SECONDS + 60);
		}

		if ($approval === 'appr-b-forged') {
			$this->hermiq->approvals['appr-b-forged']['status'] = 'pending';
			$this->hermiq->tamper = static fn (array $v): array => (['approved' => true, 'reason' => 'approved'] + $v);
		}

		$this->syncDeadLetters->expects($this->never())->method('replayMessage');
		try {
			$tools->replayDeadLetters(ids: [self::DL_B, self::DL_C], agentId: self::AGENT, proposalId: $proposal, approvalId: $approval);
			$this->fail('Phase 2 ran without a valid verdict.');
		} catch (AgentActionRefusedException $e) {
			$this->assertNotSame('', $e->reason);
		}

		$this->assertSame('staged', $this->objects->objects['agent_action'][$b['proposal']]['outcome']);
		$refused = array_values(array_filter($this->objects->actions(), static fn (array $r): bool => $r['outcome'] === 'refused'));
		$this->assertCount(1, $refused);
		$this->assertSame($b['proposal'], $refused[0]['proposal']);
		$this->assertSame(self::AGENT, $refused[0]['agent']);
		$this->assertEveryRecordValidates();
	}

	public function testAnotherAgentCannotRunAStagedBatch(): void {
		$tools  = $this->tools();
		$staged = $tools->replayDeadLetters(ids: [self::DL_A], agentId: self::AGENT);
		$this->approve(staged: $staged, approvalId: 'appr-1');
		$this->syncDeadLetters->expects($this->never())->method('replayMessage');
		$this->expectException(AgentActionRefusedException::class);
		$tools->replayDeadLetters(ids: [self::DL_A], agentId: 'other-agent', proposalId: $staged['proposal'], approvalId: 'appr-1');
	}

	public function testAnUnapprovedRunNeverRunsAndStaysAuditableAsProposed(): void {
		$this->synchronization->expects($this->never())->method('synchronize');
		$staged = $this->tools()->runSynchronization(synchronizationId: self::SYNC, agentId: self::AGENT);
		$this->objects->objects['agent_action'][$staged['proposal']]['at'] = '2026-01-01T00:00:00+00:00';
		$this->approve(staged: $staged, approvalId: 'appr-late');

		try {
			$this->tools()->runSynchronization(synchronizationId: self::SYNC, agentId: self::AGENT, proposalId: $staged['proposal'], approvalId: 'appr-late');
			$this->fail('An expired batch ran.');
		} catch (AgentActionRefusedException $e) {
			$this->assertSame('proposal-expired', $e->reason);
		}

		$this->assertSame('staged', $this->objects->objects['agent_action'][$staged['proposal']]['outcome']);
	}

	public function testAnApprovedRunUsesTheGuardedPathWithoutTheOverride(): void {
		$tools  = $this->tools();
		$staged = $tools->runSynchronization(synchronizationId: self::SYNC, agentId: self::AGENT);
		$this->approve(staged: $staged, approvalId: 'appr-run');
		$this->synchronization->expects($this->once())->method('synchronize')
			->with($this->anything(), false, false, null, null, null, null, null, false)
			->willReturn(['result' => ['objects' => ['found' => 14]]]);

		$done = $tools->runSynchronization(synchronizationId: self::SYNC, agentId: self::AGENT, proposalId: $staged['proposal'], approvalId: 'appr-run');
		$this->assertSame(['found' => 14], $done['results'][0]['objects']);
	}

	public function testAnApprovedDiscardOfEventDeadLettersUsesTheEventPath(): void {
		$tools  = $this->tools();
		$staged = $tools->discardDeadLetters(ids: ['e1'], store: 'event', agentId: self::AGENT);
		$this->approve(staged: $staged, approvalId: 'appr-d');
		$this->events->expects($this->once())->method('discardMessage')->with('e1', 'admin')->willReturn(new ObjectEntity());
		$this->syncDeadLetters->expects($this->never())->method('discardMessage');
		$tools->discardDeadLetters(ids: ['e1'], store: 'event', agentId: self::AGENT, proposalId: $staged['proposal'], approvalId: 'appr-d');
	}

	// ---- REQ-MCP-109: payload-free.

	public function testTheDeadLetterListCarriesExactlyTheProjection(): void {
		$sync  = $this->tools()->listDeadLetters(synchronization: self::SYNC, agentId: self::AGENT);
		$event = $this->tools()->listDeadLetters(store: 'event', agentId: self::AGENT);

		$this->assertSame(3, $sync['total']);
		foreach (array_merge($sync['results'], $event['results']) as $row) {
			$this->assertSame(DeadLetterProjection::KEYS, array_keys($row));
			$this->assertStringNotContainsString('999990019', (string)json_encode($row));
			$this->assertStringNotContainsString('HTTP 500 body', (string)json_encode($row));
		}

		$this->assertSame(DeadLetterProjection::ERROR_LENGTH + 1, mb_strlen($sync['results'][0]['error']));
		$this->assertSame(1, $sync['results'][0]['attempts']);
		$this->assertSame('event', $event['results'][0]['store']);
		$this->assertNull($event['results'][0]['error']);
	}
}
