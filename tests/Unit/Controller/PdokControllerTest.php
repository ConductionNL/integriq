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
use OCP\AppFramework\Http;
use OCP\IRequest;
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

        $controller = new PdokController('openconnector', $this->createMock(IRequest::class), $connector);
        $response   = $controller->suggestAction('Lauriergracht');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($payload, $response->getData());

    }//end testValidSuggestReturnsConnectorPayload()


    /**
     * Build a controller with a permissive default connector mock.
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

        return new PdokController('openconnector', $this->createMock(IRequest::class), $connector);

    }//end makeController()


}//end class
