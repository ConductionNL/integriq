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
}


