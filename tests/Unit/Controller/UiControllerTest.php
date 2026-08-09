<?php

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\UiController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class UiControllerTest extends TestCase
{
    public function testDashboardReturnsTemplateResponse(): void
    {
        $request = $this->createMock(IRequest::class);
        $controller = new UiController('openconnector', $request);

        $response = $controller->dashboard();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('openconnector', $response->getApp());
        $this->assertSame('index', $response->getTemplateName());
    }

    public function testAllRoutesReturnTemplateResponse(): void
    {
        $request = $this->createMock(IRequest::class);
        $controller = new UiController('openconnector', $request);

        $methods = [
            'sources', 'sourcesLogs', 'endpoints', 'endpointsLogs', 'consumers', 'webhooks',
            'jobs', 'jobsLogs', 'mappings', 'synchronizations', 'synchronizationsContracts',
            'synchronizationsLogs', 'cloudEvents', 'cloudEventsEvents', 'cloudEventsLogs',
        ];

        foreach ($methods as $method) {
            $response = $controller->$method();
            $this->assertInstanceOf(TemplateResponse::class, $response, $method . ' should return TemplateResponse');
            $this->assertSame('openconnector', $response->getApp());
            $this->assertSame('index', $response->getTemplateName());
        }
    }

    /**
     * The Approvals, Store and API Products SPA routes.
     *
     * These shipped without any contract test — `testAllRoutesReturnTemplateResponse`
     * above enumerates its methods by hand, so every route added after it was
     * written silently fell outside the assertion. Each is asserted
     * individually rather than being appended to that list, so a failure names
     * the route that broke instead of the loop.
     */
    public function testApprovalsReturnsTheSpaShell(): void
    {
        $response = (new UiController('openconnector', $this->createMock(IRequest::class)))->approvals();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('openconnector', $response->getApp());
        $this->assertSame('index', $response->getTemplateName());
    }

    public function testCatalogReturnsTheSpaShell(): void
    {
        $response = (new UiController('openconnector', $this->createMock(IRequest::class)))->catalog();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('openconnector', $response->getApp());
        $this->assertSame('index', $response->getTemplateName());
    }

    public function testProductsReturnsTheSpaShell(): void
    {
        $response = (new UiController('openconnector', $this->createMock(IRequest::class)))->products();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('openconnector', $response->getApp());
        $this->assertSame('index', $response->getTemplateName());
    }

    /**
     * The two detail routes take an id, and the id is deliberately NOT rendered
     * server-side — it is handed to the client router by the URL. Asserting the
     * template params stay empty is the real contract here: if an id ever
     * started being interpolated into the shell it would become an unescaped
     * reflection point, and "returns a TemplateResponse" would not notice.
     */
    public function testApprovalsIdReturnsTheSpaShellWithoutReflectingTheId(): void
    {
        $response = (new UiController('openconnector', $this->createMock(IRequest::class)))
            ->approvalsId('<script>alert(1)</script>');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('openconnector', $response->getApp());
        $this->assertSame('index', $response->getTemplateName());
        $this->assertSame([], $response->getParams());
    }

    public function testProductsIdReturnsTheSpaShellWithoutReflectingTheId(): void
    {
        $response = (new UiController('openconnector', $this->createMock(IRequest::class)))
            ->productsId('<script>alert(1)</script>');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('openconnector', $response->getApp());
        $this->assertSame('index', $response->getTemplateName());
        $this->assertSame([], $response->getParams());
    }
}


