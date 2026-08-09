<?php
/**
 * Contract tests for TablesBridgeController's discovery endpoints.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\TablesBridgeController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\Tables\TablesSyncAdapter;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `tables` and `columns` are `#[NoAdminRequired]` — any authenticated user can
 * reach them — and they read a Source's credentials on the caller's behalf. The
 * order of the checks is the contract: authentication, then the action
 * authorization, then the parameters. These tests assert that order by proving
 * the adapter is NEVER touched on a rejected request.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */
class TablesBridgeControllerTest extends TestCase
{

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|TablesSyncAdapter
     */
    private $tablesSyncAdapter;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IUserSession
     */
    private $userSession;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|ActionAuthService
     */
    private $actionAuth;

    /**
     * @var TablesBridgeController
     */
    private TablesBridgeController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tablesSyncAdapter = $this->createMock(TablesSyncAdapter::class);
        $this->userSession       = $this->createMock(IUserSession::class);
        $this->actionAuth        = $this->createMock(ActionAuthService::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $this->controller = new TablesBridgeController(
            'openconnector',
            $this->createMock(IRequest::class),
            $this->tablesSyncAdapter,
            $this->createMock(OrObjectService::class),
            $l,
            $this->createMock(LoggerInterface::class),
            $this->userSession,
            $this->actionAuth
        );

    }//end setUp()

    /**
     * An unauthenticated caller is 401 and the Tables adapter is never asked
     * anything.
     *
     * @return void
     */
    public function testTablesWithoutAUserIs401AndNeverTouchesTheAdapter(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->tablesSyncAdapter->expects($this->never())->method('assertEnabled');
        $this->tablesSyncAdapter->expects($this->never())->method('listTablesForEditor');

        $response = $this->controller->tables('source-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Not authenticated', $response->getData()['error']);

    }//end testTablesWithoutAUserIs401AndNeverTouchesTheAdapter()

    /**
     * The action authorization runs BEFORE parameter validation, so a caller
     * without the discover action is stopped even on a request that would have
     * been rejected as malformed anyway.
     *
     * @return void
     */
    public function testTablesRequiresTheDiscoverActionBeforeValidatingParameters(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($user, 'synchronization.tablesBridge.discover');

        $this->tablesSyncAdapter->expects($this->never())->method('listTablesForEditor');

        $response = $this->controller->tables(null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('sourceId is required', $response->getData()['error']);

    }//end testTablesRequiresTheDiscoverActionBeforeValidatingParameters()

    /**
     * An empty-string sourceId is treated as absent, not as a Source named ''.
     *
     * @return void
     */
    public function testTablesRejectsAnEmptyStringSourceId(): void
    {
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->tablesSyncAdapter->expects($this->never())->method('listTablesForEditor');

        $response = $this->controller->tables('');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('sourceId is required', $response->getData()['error']);

    }//end testTablesRejectsAnEmptyStringSourceId()

    /**
     * A non-positive tableId is rejected before any discovery happens. Tables
     * ids are positive integers; `0` is what a non-numeric path segment casts
     * to.
     *
     * @return void
     */
    public function testColumnsRejectsANonPositiveTableId(): void
    {
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->tablesSyncAdapter->expects($this->never())->method('listColumnsForEditor');

        $response = $this->controller->columns(0, 'source-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('tableId must be numeric', $response->getData()['error']);

    }//end testColumnsRejectsANonPositiveTableId()

    /**
     * `columns` checks authentication before the tableId, so an unauthenticated
     * caller gets 401 rather than a validation error that would disclose which
     * ids the endpoint considers well-formed.
     *
     * @return void
     */
    public function testColumnsWithoutAUserIs401NotAValidationError(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->tablesSyncAdapter->expects($this->never())->method('listColumnsForEditor');

        $response = $this->controller->columns(0, null);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Not authenticated', $response->getData()['error']);

    }//end testColumnsWithoutAUserIs401NotAValidationError()
}//end class
