<?php

/**
 * Unit tests for DSOController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\DSOController;
use OCA\OpenConnector\Service\DSOParserService;
use OCA\OpenConnector\Service\DSOSignatureVerifierService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the DSO STAM koppelvlak controller.
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
 */
class DSOControllerTest extends TestCase
{

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IRequest
     */
    private $request;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|DSOParserService
     */
    private $parser;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|LoggerInterface
     */
    private $logger;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|DSOSignatureVerifierService
     */
    private $signatureVerifier;

    /**
     * @var DSOController
     */
    private DSOController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request           = $this->createMock(IRequest::class);
        $this->parser            = $this->createMock(DSOParserService::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->signatureVerifier = $this->createMock(DSOSignatureVerifierService::class);

        // Default: signature verification passes, so tests only exercising
        // payload handling do not need to restate this every time.
        $this->signatureVerifier->method('verify')->willReturn(true);

        $this->controller = new DSOController(
            appName: 'openconnector',
            request: $this->request,
            parser: $this->parser,
            logger: $this->logger,
            signatureVerifier: $this->signatureVerifier
        );

    }//end setUp()

    /**
     * Test that a request whose signature fails verification returns 401.
     *
     * @return void
     */
    public function testInvalidSignatureReturns401(): void
    {
        $this->signatureVerifier = $this->createMock(DSOSignatureVerifierService::class);
        $this->signatureVerifier->method('verify')->willReturn(false);
        $this->controller = new DSOController(
            appName: 'openconnector',
            request: $this->request,
            parser: $this->parser,
            logger: $this->logger,
            signatureVerifier: $this->signatureVerifier
        );

        $body = [
            'verzoekId'       => 'dso-12345',
            'type'            => 'aanvraag',
            'indieningsdatum' => '2024-06-15',
            'aanvrager'       => ['bsn' => '999993653'],
            'locatie'         => ['bagAdres' => []],
            'activiteiten'    => [['code' => 'bouwen-01']],
        ];

        $this->request->method('getParams')->willReturn($body);
        $this->request->method('getHeader')->willReturn('');

        $response = $this->controller->receiveVerzoek();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

        $data = $response->getData();
        $this->assertSame('invalid_signature', $data['error']);

    }//end testInvalidSignatureReturns401()

    /**
     * Test that a request with no `X-DSO-Signature` header (which the real
     * verifier will always reject) returns 401.
     *
     * @return void
     */
    public function testMissingSignatureHeaderReturns401(): void
    {
        $this->signatureVerifier = $this->createMock(DSOSignatureVerifierService::class);
        $this->signatureVerifier->method('verify')
            ->willReturnCallback(static fn (?string $sig, string $body): bool => ($sig !== null && $sig !== ''));
        $this->controller = new DSOController(
            appName: 'openconnector',
            request: $this->request,
            parser: $this->parser,
            logger: $this->logger,
            signatureVerifier: $this->signatureVerifier
        );

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getHeader')->willReturn('');

        $response = $this->controller->receiveVerzoek();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testMissingSignatureHeaderReturns401()

    /**
     * Test that a valid verzoek with a verified signature returns 202.
     *
     * @return void
     */
    public function testValidVerzoekWithSignatureReturns202(): void
    {
        $body = [
            'verzoekId'       => 'dso-12345',
            'type'            => 'aanvraag',
            'indieningsdatum' => '2024-06-15',
            'aanvrager'       => ['bsn' => '999993653'],
            'locatie'         => ['bagAdres' => []],
            'activiteiten'    => [['code' => 'bouwen-01']],
        ];

        $this->request->method('getParams')->willReturn($body);
        $this->request->method('getHeader')
            ->willReturnCallback(
                static function (string $header): string {
                    if ($header === 'X-DSO-Signature') {
                        return 'sha256=abc123';
                    }

                    return '';
                }
            );

        $this->parser->method('validatePayload')->willReturn([]);
        $this->parser->method('parseVerzoek')->willReturn(
            array_merge($body, ['status' => 'ontvangen'])
        );

        $response = $this->controller->receiveVerzoek();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

        $data = $response->getData();
        $this->assertArrayHasKey('verzoekId', $data);
        $this->assertSame('ontvangen', $data['status']);

    }//end testValidVerzoekWithSignatureReturns202()

    /**
     * Test that a payload validation failure returns 400.
     *
     * @return void
     */
    public function testValidationFailureReturns400(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getHeader')
            ->willReturnCallback(
                static function (string $header): string {
                    if ($header === 'X-DSO-Signature') {
                        return 'sha256=placeholder';
                    }

                    return '';
                }
            );

        $validationErrors = [
            [
                'field'   => 'activiteiten',
                'error'   => 'required_field_missing',
                'message' => 'Activiteiten is verplicht',
            ],
        ];

        $this->parser->method('validatePayload')->willReturn($validationErrors);

        $response = $this->controller->receiveVerzoek();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

        $data = $response->getData();
        $this->assertSame('validation_failed', $data['error']);
        $this->assertNotEmpty($data['errors']);

    }//end testValidationFailureReturns400()

    /**
     * Test that verzoekId is preserved from the parsed payload.
     *
     * @return void
     */
    public function testVerzoekIdPreservedFromParsedPayload(): void
    {
        $body = ['verzoekId' => 'test-id-999'];

        $this->request->method('getParams')->willReturn($body);
        $this->request->method('getHeader')
            ->willReturnCallback(
                static function (string $header): string {
                    if ($header === 'X-DSO-Signature') {
                        return 'sha256=placeholder';
                    }

                    return '';
                }
            );

        $this->parser->method('validatePayload')->willReturn([]);
        $this->parser->method('parseVerzoek')->willReturn(['verzoekId' => 'test-id-999', 'type' => 'aanvraag']);

        $response = $this->controller->receiveVerzoek();

        $data = $response->getData();
        $this->assertSame('test-id-999', $data['verzoekId']);

    }//end testVerzoekIdPreservedFromParsedPayload()

    /**
     * Test that environment header is accepted without error.
     *
     * @return void
     */
    public function testEnvironmentHeaderTaggedOnVerzoek(): void
    {
        $body = ['verzoekId' => 'dso-env-test'];

        $this->request->method('getParams')->willReturn($body);
        $this->request->method('getHeader')
            ->willReturnCallback(
                static function (string $header): string {
                    if ($header === 'X-DSO-Environment') {
                        return 'pre-productie';
                    }

                    if ($header === 'X-DSO-Signature') {
                        return 'sha256=placeholder';
                    }

                    return '';
                }
            );

        $this->parser->method('validatePayload')->willReturn([]);
        $this->parser->method('parseVerzoek')->willReturn(['verzoekId' => 'dso-env-test', 'type' => 'aanvraag']);

        $response = $this->controller->receiveVerzoek();

        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

    }//end testEnvironmentHeaderTaggedOnVerzoek()
}//end class
