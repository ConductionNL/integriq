<?php

/**
 * Unit tests for ConfigurationController (connector-catalog-ui).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-008--import-requires-explicit-confirmation-after-preview
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\ConfigurationController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\ConfigurationImportPreviewService;
use OCA\OpenConnector\Service\ConfigurationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests export/preview/import auth gating, confirmation guard and
 * delegation to the existing ConfigurationService.
 */
class ConfigurationControllerTest extends TestCase
{
    /**
     * @var ConfigurationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $configService;

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->configService   = $this->createMock(ConfigurationService::class);
        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @param array<string,mixed> $requestParams Values the mocked IRequest returns per param name.
     * @param bool                $isAdmin       Whether the session user passes the admin break-glass.
     * @param bool                $authenticated Whether a session user exists at all.
     *
     * @return ConfigurationController
     */
    private function makeController(array $requestParams=[], bool $isAdmin=true, bool $authenticated=true): ConfigurationController
    {
        $user = null;
        if ($authenticated === true) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('tester');
        }

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $authConfig = $this->createMock(IAppConfig::class);
        $authConfig->method('getValueString')->willReturn('{}');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);
        $groupManager->method('getUserGroupIds')->willReturn([]);

        $actionAuth = new ActionAuthService($authConfig, $groupManager);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default=null) => ($requestParams[$key] ?? $default)
        );
        $request->method('getUploadedFile')->willReturn(null);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new ConfigurationController(
            'openconnector',
            $request,
            $this->configService,
            new ConfigurationImportPreviewService($this->orObjectService),
            $l10n,
            $userSession,
            $actionAuth
        );
    }//end makeController()

    /**
     * REQ-006: export returns the service's redacted document with an
     * attachment disposition, delegating unchanged.
     *
     * @return void
     */
    public function testExportReturnsAttachmentWithServiceDocument(): void
    {
        $document = ['components' => ['sources' => []]];
        $this->configService->expects($this->once())
            ->method('exportConfiguration')
            ->willReturn($document);

        $response = $this->makeController()->export('config-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($document, $response->getData());

        // Response::getHeaders() needs a booted \OC (it appends the CSP
        // header via the server container), so read the raw protected
        // headers property instead — standalone-suite safe.
        $property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $headers  = $property->getValue($response);
        $this->assertArrayHasKey('Content-Disposition', $headers);
        $this->assertStringContainsString('attachment', $headers['Content-Disposition']);
    }//end testExportReturnsAttachmentWithServiceDocument()

    /**
     * REQ-006 scenario: a non-admin without the configuration.export action
     * is rejected with 403 and no export runs.
     *
     * @return void
     */
    public function testExportDeniedForUnmappedNonAdmin(): void
    {
        $this->configService->expects($this->never())->method('exportConfiguration');

        $response = $this->makeController(isAdmin: false)->export('config-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testExportDeniedForUnmappedNonAdmin()

    /**
     * exportRegister returns the service's register bundle with an attachment
     * disposition — proving the routed trigger fires ConfigurationService::
     * exportRegister() and produces the downloadable artefact.
     *
     * @return void
     */
    public function testExportRegisterReturnsAttachmentWithServiceBundle(): void
    {
        $bundle = ['components' => ['endpoints' => ['ep-1' => ['slug' => 'ep-1']]]];
        $this->configService->expects($this->once())
            ->method('exportRegister')
            ->with(registerId: 'reg-1')
            ->willReturn($bundle);

        $response = $this->makeController()->exportRegister('reg-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($bundle, $response->getData());

        $property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $headers  = $property->getValue($response);
        $this->assertArrayHasKey('Content-Disposition', $headers);
        $this->assertStringContainsString('attachment', $headers['Content-Disposition']);
        $this->assertStringContainsString('register-reg-1.json', $headers['Content-Disposition']);
    }//end testExportRegisterReturnsAttachmentWithServiceBundle()

    /**
     * A non-admin without the configuration.export action is rejected with 403
     * and no register export runs.
     *
     * @return void
     */
    public function testExportRegisterDeniedForUnmappedNonAdmin(): void
    {
        $this->configService->expects($this->never())->method('exportRegister');

        $response = $this->makeController(isAdmin: false)->exportRegister('reg-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testExportRegisterDeniedForUnmappedNonAdmin()

    /**
     * REQ-007: preview returns the classification and never writes.
     *
     * @return void
     */
    public function testPreviewImportClassifiesWithoutWriting(): void
    {
        $this->configService->expects($this->never())->method('importConfiguration');
        $this->orObjectService->expects($this->never())->method('saveObject');

        $controller = $this->makeController(
            [
                'document' => [
                    'components' => [
                        'sources' => ['new-source' => ['slug' => 'new-source']],
                    ],
                ],
            ]
        );
        $response = $controller->previewImport();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(1, $data['creates']);
        $this->assertSame('new-source', $data['creates'][0]['slug']);
        $this->assertSame([], $data['updates']);
    }//end testPreviewImportClassifiesWithoutWriting()

    /**
     * Preview without a document body is a 400.
     *
     * @return void
     */
    public function testPreviewImportWithoutDocumentReturns400(): void
    {
        $response = $this->makeController()->previewImport();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testPreviewImportWithoutDocumentReturns400()

    /**
     * A document missing top-level `components` is a 400 (mirrors
     * ConfigurationService's InvalidArgumentException).
     *
     * @return void
     */
    public function testPreviewImportMissingComponentsReturns400(): void
    {
        $controller = $this->makeController(['document' => ['no-components' => true]]);

        $response = $controller->previewImport();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testPreviewImportMissingComponentsReturns400()

    /**
     * REQ-008 scenario: import with `confirmed` omitted is rejected with 400
     * and nothing is written.
     *
     * @return void
     */
    public function testImportWithoutConfirmationReturns400(): void
    {
        $this->configService->expects($this->never())->method('importConfiguration');

        $controller = $this->makeController(
            ['document' => ['components' => []]]
        );
        $response = $controller->import();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testImportWithoutConfirmationReturns400()

    /**
     * REQ-008 scenario: import with confirmed:false is also rejected.
     *
     * @return void
     */
    public function testImportWithConfirmedFalseReturns400(): void
    {
        $this->configService->expects($this->never())->method('importConfiguration');

        $controller = $this->makeController(
            [
                'document'  => ['components' => []],
                'confirmed' => false,
            ]
        );
        $response = $controller->import();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testImportWithConfirmedFalseReturns400()

    /**
     * REQ-008 scenario: confirmed import delegates unchanged to the existing
     * ConfigurationService::importConfiguration() and reports what was written.
     *
     * @return void
     */
    public function testConfirmedImportDelegatesToExistingPipeline(): void
    {
        $document = [
            'components' => [
                'sources' => ['new-source' => ['slug' => 'new-source']],
            ],
        ];

        $imported = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'new-source'], 'src-uuid');
        $this->configService->expects($this->once())
            ->method('importConfiguration')
            ->with($document)
            ->willReturn(
                [
                    'sources'          => ['new-source' => $imported],
                    'mappings'         => [],
                    'rules'            => [],
                    'endpoints'        => [],
                    'synchronizations' => [],
                    'jobs'             => [],
                ]
            );

        $controller = $this->makeController(
            [
                'document'  => $document,
                'confirmed' => true,
            ]
        );
        $response = $controller->import();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(['new-source'], $data['written']['sources']);
        // REQ-009: the credential re-entry flag rides along in the summary.
        $this->assertCount(1, $data['credentialsNeedingReentry']);
        $this->assertSame('new-source', $data['credentialsNeedingReentry'][0]['slug']);
    }//end testConfirmedImportDelegatesToExistingPipeline()

    /**
     * REQ-008: the configuration.import action gates preview AND import for
     * a non-admin without a matrix mapping.
     *
     * @return void
     */
    public function testImportAndPreviewDeniedForUnmappedNonAdmin(): void
    {
        $this->configService->expects($this->never())->method('importConfiguration');

        $controller = $this->makeController(
            [
                'document'  => ['components' => []],
                'confirmed' => true,
            ],
            isAdmin: false
        );

        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->previewImport()->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->import()->getStatus());
    }//end testImportAndPreviewDeniedForUnmappedNonAdmin()

    /**
     * Unauthenticated requests get 401 on all three endpoints.
     *
     * @return void
     */
    public function testUnauthenticatedReturns401(): void
    {
        $controller = $this->makeController(authenticated: false);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->export('x')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->exportRegister('x')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->previewImport()->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->import()->getStatus());
    }//end testUnauthenticatedReturns401()
}//end class
