<?php
/**
 * Unit tests for the array-based SynchronizationContractLogService.
 *
 * Covers the W14 Tier 2 cleanup: createFromArray()/update()/insert() now take
 * and return arrays end-to-end (no more SynchronizationContractLog VO).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\SynchronizationContractLogService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Session\Exceptions\SessionNotAvailableException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Append-only contract log write-path tests.
 */
class SynchronizationContractLogServiceTest extends TestCase
{

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var ISession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $session;

    /**
     * @var SynchronizationContractLogService
     */
    private SynchronizationContractLogService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->session         = $this->createMock(ISession::class);

        $this->service = new SynchronizationContractLogService(
            $this->orObjectService,
            $this->userSession,
            $this->session
        );
    }//end setUp()


    /**
     * createFromArray() auto-fills uuid, synchronizationLogId default, expires
     * default, and userId when a user session is active.
     *
     * @return void
     */
    public function testCreateFromArrayAutoFillsSystemFields(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->session->method('getId')->willReturn('session-1');

        $payload = $this->service->createFromArray(['synchronizationContractId' => 'c-1']);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['uuid']);
        $this->assertNotEmpty($payload['uuid']);
        $this->assertSame('alice', $payload['userId']);
        $this->assertSame('session-1', $payload['sessionId']);
        $this->assertSame('n.a.', $payload['synchronizationLogId']);
        $this->assertArrayHasKey('expires', $payload);
        $this->assertSame('c-1', $payload['synchronizationContractId']);
    }//end testCreateFromArrayAutoFillsSystemFields()


    /**
     * createFromArray() preserves a caller-supplied uuid (does not generate a
     * new one).
     *
     * @return void
     */
    public function testCreateFromArrayPreservesCallerUuid(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->session->method('getId')->willReturn('session-1');

        $payload = $this->service->createFromArray([
            'uuid'                      => 'caller-uuid',
            'synchronizationContractId' => 'c-1',
        ]);

        $this->assertSame('caller-uuid', $payload['uuid']);
    }//end testCreateFromArrayPreservesCallerUuid()


    /**
     * createFromArray() preserves a caller-supplied synchronizationLogId.
     *
     * @return void
     */
    public function testCreateFromArrayPreservesCallerSynchronizationLogId(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->session->method('getId')->willReturn('session-1');

        $payload = $this->service->createFromArray([
            'synchronizationLogId' => 'log-42',
        ]);

        $this->assertSame('log-42', $payload['synchronizationLogId']);
    }//end testCreateFromArrayPreservesCallerSynchronizationLogId()


    /**
     * createFromArray() guards a missing session (job context throws
     * SessionNotAvailableException — must set sessionId to null, not throw).
     *
     * @return void
     */
    public function testCreateFromArrayGuardsAgainstMissingSession(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->session->method('getId')->willThrowException(
            new SessionNotAvailableException()
        );

        $payload = $this->service->createFromArray([]);

        $this->assertNull($payload['sessionId']);
    }//end testCreateFromArrayGuardsAgainstMissingSession()


    /**
     * update() persists the contract log via OR saveObject with NO uuid arg
     * (CREATE), honouring the append-only schema.
     *
     * @return void
     */
    public function testUpdatePersistsAsCreateWithoutUuidArg(): void
    {
        $observedUuid = 'sentinel';
        $observedBody = null;

        $this->orObjectService->method('saveObject')
            ->willReturnCallback(function (
                $object,
                ?string $register = null,
                ?string $schema = null,
                ?string $uuid = null
            ) use (&$observedUuid, &$observedBody) {
                $observedUuid = $uuid;
                $observedBody = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'log-1');
            });

        $log = ['uuid' => 'log-1', 'synchronizationContractId' => 'c-1', 'message' => 'ok'];
        $this->service->update($log);

        $this->assertNull(
            $observedUuid,
            'append-only schema requires CREATE (no uuid arg)'
        );
        $this->assertIsArray($observedBody);
        $this->assertArrayNotHasKey(
            'id',
            $observedBody,
            'legacy int id must be stripped'
        );
    }//end testUpdatePersistsAsCreateWithoutUuidArg()


    /**
     * update() is a no-op on a uuid already persisted in this request
     * (append-only invariant).
     *
     * @return void
     */
    public function testUpdateIsNoOpForAlreadyPersistedUuid(): void
    {
        $this->orObjectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(function (
                $object,
                ?string $register = null,
                ?string $schema = null,
                ?string $uuid = null
            ) {
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'log-1');
            });

        $log = ['uuid' => 'log-1', 'synchronizationContractId' => 'c-1'];
        $this->service->update($log);
        // Second + third calls must NOT trigger saveObject.
        $this->service->update($log);
        $this->service->update($log);
    }//end testUpdateIsNoOpForAlreadyPersistedUuid()


    /**
     * update() strips null values from the persisted payload.
     *
     * @return void
     */
    public function testUpdateStripsNullValuesFromPayload(): void
    {
        $observedBody = null;
        $this->orObjectService->method('saveObject')
            ->willReturnCallback(function (
                $object,
                ?string $register = null,
                ?string $schema = null,
                ?string $uuid = null
            ) use (&$observedBody) {
                $observedBody = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'log-1');
            });

        $log = [
            'uuid'                      => 'log-1',
            'synchronizationContractId' => 'c-1',
            'message'                   => null,
            'targetResult'              => null,
        ];
        $this->service->update($log);

        $this->assertArrayNotHasKey('message', $observedBody);
        $this->assertArrayNotHasKey('targetResult', $observedBody);
    }//end testUpdateStripsNullValuesFromPayload()


    /**
     * insert() delegates to update() — same write-once semantics.
     *
     * @return void
     */
    public function testInsertDelegatesToUpdate(): void
    {
        $this->orObjectService->expects($this->once())->method('saveObject')
            ->willReturnCallback(function ($object) {
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'log-1');
            });

        $log = ['uuid' => 'log-1', 'synchronizationContractId' => 'c-1'];
        $this->service->insert($log);
        // A second insert + update must still only trigger saveObject once.
        $this->service->insert($log);
        $this->service->update($log);
    }//end testInsertDelegatesToUpdate()


}//end class
