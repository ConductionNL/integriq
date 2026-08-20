<?php

/**
 * Regression tests locking the write-once run-log behaviour of
 * SynchronizationLogService after the OpenRegister cutover.
 *
 * The `synchronization_log` OpenRegister schema is append-only: the run-log row
 * must be INSERTed exactly once, in its final state. These tests pin that the
 * service writes via ObjectService::saveObject (scoped to register+schema, as a
 * CREATE — no uuid parameter), exactly once, and that a repeated finalize is a
 * no-op. No live database is used: the OpenRegister ObjectService is mocked.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationRunLog;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Write-once / append-only regression coverage for the run-log service.
 */
final class SynchronizationLogServiceTest extends TestCase {

	/**
	 * Build an OpenRegister ObjectEntity carrying the given body + uuid.
	 *
	 * @param array $body The object body.
	 * @param string $uuid The object uuid.
	 *
	 * @return ObjectEntity The stub entity.
	 */
	private function entity(array $body, string $uuid = 'log-stored-uuid'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($body);

		return $entity;
	}//end entity()

	/**
	 * Construct the service with a logged-in user + session.
	 *
	 * @param OrObjectService $objectService The OR service mock.
	 *
	 * @return SynchronizationLogService The constructed service.
	 */
	private function makeService(OrObjectService $objectService): SynchronizationLogService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$session = $this->createMock(ISession::class);
		$session->method('getId')->willReturn('sess-1');

		return new SynchronizationLogService($objectService, $userSession, $session);
	}//end makeService()

	/**
	 * update() writes the run-log exactly once, via saveObject scoped to the
	 * `openconnector`/`synchronization_log` register+schema, as a CREATE
	 * (no uuid parameter — the append-only schema forbids an UPDATE).
	 *
	 * @return void
	 */
	public function testUpdateWritesRunLogOnceAsCreate(): void {
		$captured = [];
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function ($object, $register, $schema, $uuid = null) use (&$captured): ObjectEntity {
					$captured = ['register' => $register, 'schema' => $schema, 'uuid' => $uuid];
					return $this->entity($object);
				}
			);

		$service = $this->makeService($objectService);
		$log = $service->createFromArray(['message' => 'Running']);

		$this->assertFalse($log->isPersisted());

		$service->update($log);

		$this->assertSame('openconnector', $captured['register']);
		$this->assertSame('synchronization_log', $captured['schema']);
		$this->assertNull($captured['uuid']);
		$this->assertTrue($log->isPersisted());

	}//end testUpdateWritesRunLogOnceAsCreate()

	/**
	 * A second update()/persist() for an already-persisted run-log is a no-op:
	 * the append-only schema means the row is never written twice.
	 *
	 * @return void
	 */
	public function testUpdateIsWriteOnceNoOpOnSecondCall(): void {
		$objectService = $this->createMock(OrObjectService::class);
		// Exactly one write across two finalize calls.
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				fn ($object): ObjectEntity => $this->entity($object)
			);

		$service = $this->makeService($objectService);
		$log = $service->createFromArray(['message' => 'Running']);

		$service->update($log);
		// persist() is the finalize alias; must NOT trigger a second write.
		$service->persist($log);

	}//end testUpdateIsWriteOnceNoOpOnSecondCall()

	/**
	 * createFromArray() builds an in-memory, unpersisted handle (no write yet),
	 * so the engine can reference its uuid before the single finalize INSERT.
	 *
	 * @return void
	 */
	public function testCreateFromArrayDoesNotWrite(): void {
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->expects($this->never())->method('saveObject');

		$service = $this->makeService($objectService);
		$log = $service->createFromArray(['message' => 'building']);

		$this->assertInstanceOf(SynchronizationRunLog::class, $log);
		$this->assertFalse($log->isPersisted());

	}//end testCreateFromArrayDoesNotWrite()

	/**
	 * Reference compaction drops empty-string entries, not just nulls.
	 *
	 * `resolveContractId()` returned a bare string unchanged, so an entry of
	 * `''` — which references nothing, exactly like a null — survived into the
	 * persisted `contracts` list. `Flow\SynchronizationRunNode::objectsFrom()`
	 * then fans a degenerate object out of it. The `logs` branch already
	 * filtered `''`, so the two lists disagreed on the same input.
	 *
	 * The `_embed.contracts` pairing is asserted too: it is aligned by
	 * position, so dropping a contract without dropping its embedded partner
	 * would misalign every later entry.
	 *
	 * @return void
	 */
	public function testCompactionDropsEmptyStringReferences(): void {
		$captured = [];
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->method('saveObject')
			->willReturnCallback(
				function ($object, $register, $schema, $uuid = null) use (&$captured): ObjectEntity {
					$captured = $object;
					return $this->entity($object);
				}
			);

		$service = $this->makeService($objectService);
		$log = $service->createFromArray(
			[
				'message' => 'done',
				'result' => [
					'contracts' => ['uuid-a', '', null, 'uuid-b'],
					'logs' => ['log-a', '', null],
					'_embed' => ['contracts' => ['embed-a', 'embed-empty', 'embed-null', 'embed-b']],
				],
			]
		);

		$service->update($log);

		$this->assertSame(['uuid-a', 'uuid-b'], $captured['result']['contracts']);
		$this->assertSame(['log-a'], $captured['result']['logs']);
		$this->assertSame(['embed-a', 'embed-b'], $captured['result']['_embed']['contracts']);

	}//end testCompactionDropsEmptyStringReferences()

}//end class
