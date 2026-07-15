<?php

/**
 * Unit tests for SharePointOnlineAdapter.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Adapter;

use OCA\OpenConnector\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the SharePoint Online reference adapter (REQ-DCC-001).
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-3
 */
class SharePointOnlineAdapterTest extends TestCase
{

    /**
     * @var CredentialBrokerService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $credentialBroker;

    /**
     * @var IRootFolder|\PHPUnit\Framework\MockObject\MockObject
     */
    private $rootFolder;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var SharePointOnlineAdapter
     */
    private SharePointOnlineAdapter $adapter;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialBroker = $this->createMock(CredentialBrokerService::class);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('cred-uuid-sp');

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->rootFolder  = $this->createMock(IRootFolder::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->adapter = new SharePointOnlineAdapter(
            credentialBroker: $this->credentialBroker,
            appConfig: $appConfig,
            logger: $this->createMock(LoggerInterface::class),
            l10n: $l10n,
            rootFolder: $this->rootFolder,
            userSession: $this->userSession
        );
    }//end setUp()

    /**
     * Declares the document-fetch/document-list capability vocabulary.
     *
     * @return void
     */
    public function testCapabilities(): void
    {
        $this->assertSame(['document-fetch', 'document-list'], $this->adapter->getCapabilities());
    }//end testCapabilities()

    /**
     * `listDocuments()` calls the Graph `drive/root/children` path and
     * normalises the response.
     *
     * @return void
     */
    public function testListDocumentsNormalisesGraphResponse(): void
    {
        $graphResponse = [
            'value' => [
                [
                    'id'                   => 'item-1',
                    'name'                 => 'Report.pdf',
                    'size'                 => 12345,
                    'lastModifiedDateTime' => '2026-01-01T00:00:00Z',
                    'webUrl'               => 'https://contoso.sharepoint.com/Report.pdf',
                ],
            ],
        ];

        $this->credentialBroker->expects($this->once())
            ->method('request')
            ->with(
                'cred-uuid-sp',
                'openconnector',
                'GET',
                $this->stringContains('/v1.0/sites/site-1/drive/root/children'),
                [],
                null,
                null
            )
            ->willReturn(['status' => 200, 'headers' => [], 'body' => json_encode($graphResponse)]);

        $documents = $this->adapter->listDocuments('site-1');

        $this->assertCount(1, $documents);
        $this->assertSame('item-1', $documents[0]['id']);
        $this->assertSame('Report.pdf', $documents[0]['name']);
        $this->assertFalse($documents[0]['isFolder']);
    }//end testListDocumentsNormalisesGraphResponse()

    /**
     * `fetchDocument()` returns null when there is no active user session
     * (nothing to persist the fetched bytes into).
     *
     * @return void
     */
    public function testFetchDocumentReturnsNullWithoutUserSession(): void
    {
        $this->credentialBroker->method('request')
            ->willReturn(['status' => 200, 'headers' => [], 'body' => 'file-bytes']);

        $this->userSession->method('getUser')->willReturn(null);

        $this->assertNull($this->adapter->fetchDocument('site-1', 'item-1', 'Report.pdf'));
    }//end testFetchDocumentReturnsNullWithoutUserSession()

    /**
     * `fetchDocument()` persists the fetched bytes into the user's Files
     * folder under the adapter's target folder.
     *
     * @return void
     */
    public function testFetchDocumentPersistsIntoUserFilesFolder(): void
    {
        $this->credentialBroker->method('request')
            ->willReturn(['status' => 200, 'headers' => [], 'body' => 'file-bytes']);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $userFolder = $this->createMock(\OCP\Files\Folder::class);
        $userFolder->method('nodeExists')->willReturn(false);
        $userFolder->expects($this->once())->method('newFolder')
            ->with('OpenConnector SharePoint Documents');

        $persistedFile = $this->createMock(\OCP\Files\File::class);
        $persistedFile->method('getPath')->willReturn('/alice/files/OpenConnector SharePoint Documents/Report.pdf');
        $persistedFile->method('getSize')->willReturn(10);

        $userFolder->expects($this->once())->method('newFile')
            ->with('OpenConnector SharePoint Documents/Report.pdf', 'file-bytes')
            ->willReturn($persistedFile);

        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

        $result = $this->adapter->fetchDocument('site-1', 'item-1', 'Report.pdf');

        $this->assertNotNull($result);
        $this->assertSame(10, $result['size']);
    }//end testFetchDocumentPersistsIntoUserFilesFolder()

    /**
     * `list()` requires `$filters['siteId']`; missing it returns an empty array.
     *
     * @return void
     */
    public function testListRequiresSiteIdFilter(): void
    {
        $this->credentialBroker->expects($this->never())->method('request');

        $this->assertSame([], $this->adapter->list('register', 'schema', 'object-id', []));
    }//end testListRequiresSiteIdFilter()
}//end class
