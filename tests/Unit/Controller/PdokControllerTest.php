<?php

/**
 * OpenConnector PDOK Controller Test
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Connectors\PdokConnector;
use OCA\OpenConnector\Controller\PdokController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PdokController parameter validation + delegation.
 */
class PdokControllerTest extends TestCase
{


    /**
     * Missing `q` on suggest returns 400 with the documented error envelope.
     *
     * @return void
     */
    public function testSuggestMissingQueryReturns400(): void
    {
        $response = $this->makeController()->suggestAction('');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('missing_query', $body['error']);
        $this->assertSame('pdok.error.missing_query', $body['message_key']);

    }//end testSuggestMissingQueryReturns400()


    /**
     * Missing `id` on lookup returns 400.
     *
     * @return void
     */
    public function testLookupMissingIdReturns400(): void
    {
        $response = $this->makeController()->lookupAction('');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testLookupMissingIdReturns400()


    /**
     * Missing lat/lng on reverse returns 400.
     *
     * @return void
     */
    public function testReverseMissingCoordinatesReturns400(): void
    {
        $response = $this->makeController()->reverseAction(null, null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('missing_coordinates', $body['error']);
        $this->assertSame('pdok.error.missing_coordinates', $body['message_key']);

    }//end testReverseMissingCoordinatesReturns400()


    /**
     * Valid suggest call returns 200 with the connector payload.
     *
     * @return void
     */
    public function testValidSuggestReturnsConnectorPayload(): void
    {
        $payload = ['docs' => [['pdokId' => 'x']], 'numFound' => 1];

        $connector = $this->createMock(PdokConnector::class);
        $connector->method('suggest')->willReturn($payload);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testadmin');

        $controller = $this->buildController($connector, $user, true);
        $response   = $controller->suggestAction('Lauriergracht');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());

    }//end testValidSuggestReturnsConnectorPayload()


    /**
     * Build a controller with a permissive default connector mock and an
     * admin-flagged test user so all action-auth checks pass.
     *
     * @return PdokController
     */
    private function makeController(): PdokController
    {
        $connector = $this->createMock(PdokConnector::class);
        $connector->method('suggest')->willReturn(['docs' => [], 'numFound' => 0]);
        $connector->method('lookup')->willReturn(['docs' => [], 'numFound' => 0]);
        $connector->method('free')->willReturn(['docs' => [], 'numFound' => 0]);
        $connector->method('reverse')->willReturn(['docs' => [], 'numFound' => 0]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testadmin');

        return $this->buildController($connector, $user, true);

    }//end makeController()


    /**
     * Instantiate PdokController with all required constructor arguments.
     *
     * PdokController checks userSession->getUser() before every action. Pass a
     * mock IUser (or null) via $authenticatedUser to control that check.
     * ActionAuthService is wired with mocks whose IGroupManager returns no groups,
     * so non-admin users may fail requireAction() — pass $isAdmin=true to skip.
     *
     * @param  PdokConnector $connector         The connector mock to inject.
     * @param  IUser|null    $authenticatedUser  User returned by userSession->getUser().
     * @param  bool          $isAdmin            Whether IUser::isAdmin should return true.
     * @return PdokController
     */
    private function buildController(PdokConnector $connector, ?IUser $authenticatedUser = null, bool $isAdmin = true): PdokController
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($authenticatedUser);

        $appConfig   = $this->createMock(IAppConfig::class);
        // Allow any action key by returning null (no config = default-open or admin-only).
        $appConfig->method('getValueArray')->willReturn([]);

        $groupMgr   = $this->createMock(IGroupManager::class);
        // Make the test user be in the admin group so requireAction() passes.
        if ($authenticatedUser !== null && $isAdmin === true) {
            $groupMgr->method('isAdmin')->willReturn(true);
        }

        $actionAuth  = new ActionAuthService($appConfig, $groupMgr);
        $l10n        = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new PdokController(
            'openconnector',
            $this->createMock(IRequest::class),
            $connector,
            $userSession,
            $actionAuth,
            $l10n
        );

    }//end buildController()


}//end class
