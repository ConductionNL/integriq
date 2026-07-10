<?php

/**
 * OpenConnector Health Controller — OpenRegister dependency guard tests.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\HealthController;
use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-ADM-003: a missing OpenRegister surfaces a 503 health signal.
 *
 * @spec openspec/changes/licence-and-or-requirement-honesty/specs/app-distribution-metadata/spec.md
 */
class HealthControllerOpenRegisterGuardTest extends TestCase
{
    /**
     * When OpenRegister is disabled, /api/health returns 503 naming the
     * missing dependency (never a bare 500) and never touches the delegate.
     *
     * @return void
     */
    public function testHealthReports503WhenOpenRegisterAbsent(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('openregister')->willReturn(false);

        $controller = new HealthController(
            appName: 'openconnector',
            request: $this->createMock(IRequest::class),
            appManager: $appManager,
            delegate: null
        );

        $response = $controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('unhealthy', $body['status']);
        $this->assertSame('openregister-dependency', $body['checks'][0]['name']);
        $this->assertStringContainsString('OpenRegister', $body['checks'][0]['message']);
    }//end testHealthReports503WhenOpenRegisterAbsent()

    /**
     * When OpenRegister is enabled, the controller delegates to the engine and
     * returns its healthy payload unchanged.
     *
     * @return void
     */
    public function testHealthDelegatesWhenOpenRegisterEnabled(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('openregister')->willReturn(true);

        $delegate = $this->createMock(GenericHealthController::class);
        $delegate->expects($this->once())
            ->method('index')
            ->willReturn(new JSONResponse(['status' => 'healthy'], Http::STATUS_OK));

        $controller = new HealthController(
            appName: 'openconnector',
            request: $this->createMock(IRequest::class),
            appManager: $appManager,
            delegate: $delegate
        );

        $response = $controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('healthy', $response->getData()['status']);
    }//end testHealthDelegatesWhenOpenRegisterEnabled()
}//end class
