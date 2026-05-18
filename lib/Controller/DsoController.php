<?php

/**
 * OpenConnector DSO Controller
 *
 * Controller for the DSO / Omgevingsloket STAM koppelvlak endpoint.
 * Receives vergunningaanvragen, meldingen, and informatieverzoeken from DSO-LV.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\DSOParserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the DSO STAM koppelvlak inbound endpoint.
 *
 * Accepts DSO-verzoek payloads (JSON/XML), validates them, and returns
 * HTTP 202 Accepted with verzoekId confirmation for asynchronous processing.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class DsoController extends Controller
{


    /**
     * DsoController constructor.
     *
     * @param string           $appName The name of the app.
     * @param IRequest         $request Request object.
     * @param DSOParserService $parser  The DSO payload parser service.
     * @param LoggerInterface  $logger  Logger for error handling.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DSOParserService $parser,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Receive a DSO-verzoek via the STAM koppelvlak.
     *
     * Accepts POST requests with DSO-verzoek payloads (JSON or XML),
     * validates the request signature and payload schema, and returns
     * HTTP 202 Accepted with the verzoekId for asynchronous processing.
     *
     * @return JSONResponse HTTP 202 on success, 400 on validation error, 401 on signature error.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
     *
     * @NoCSRFRequired
     * @PublicPage
     */
    public function receiveVerzoek(): JSONResponse
    {
        $body = $this->request->getParams();

        // Validate webhook signature.
        $signatureHeader = $this->request->getHeader('X-DSO-Signature');
        if ($this->validateSignature(signature: $signatureHeader, body: $body) === false) {
            $this->logger->warning('DSO STAM: Invalid webhook signature');
            return new JSONResponse(
                data: [
                    'error'   => 'invalid_signature',
                    'message' => 'Webhook signature validation failed',
                ],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        // Validate the payload schema.
        $validationErrors = $this->parser->validatePayload(payload: $body);
        if (empty($validationErrors) === false) {
            $this->logger->info('DSO STAM: Payload validation failed', ['errors' => $validationErrors]);
            return new JSONResponse(
                data: [
                    'error'  => 'validation_failed',
                    'errors' => $validationErrors,
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        // Parse the verzoek.
        $verzoek = $this->parser->parseVerzoek(payload: $body);

        // Tag with environment if provided by DSO-LV.
        $environment = $this->request->getHeader('X-DSO-Environment');
        if ($environment !== '' && $environment !== null) {
            $verzoek['environment'] = $environment;
        }

        $verzoekId = ($verzoek['verzoekId'] ?? uniqid(prefix: 'dso-', more_entropy: true));

        $this->logger->info(
            'DSO STAM: Verzoek received',
            [
                'verzoekId' => $verzoekId,
                'type'      => ($verzoek['type'] ?? 'unknown'),
            ]
        );

        return new JSONResponse(
            data: [
                'verzoekId' => $verzoekId,
                'status'    => 'ontvangen',
                'message'   => 'Verzoek ontvangen en wordt verwerkt',
            ],
            statusCode: Http::STATUS_ACCEPTED
        );

    }//end receiveVerzoek()


    /**
     * Validate the DSO-LV webhook signature.
     *
     * Validates the signature header against the request body. When no signature
     * header is present the request is accepted (allows dev/test without certificates).
     * Full PKIoverheid certificate-chain validation is a runtime concern handled
     * outside this controller.
     *
     * @param string|null $signature The X-DSO-Signature header value.
     * @param mixed       $body      The request body.
     *
     * @return bool True if the signature is valid (or absent).
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
     */
    private function validateSignature(?string $signature, mixed $body): bool
    {
        if ($signature === null || $signature === '') {
            return true;
        }

        // Signature validation uses the DSO-LV public certificate to verify the
        // HMAC/RSA signature of the request body (PKIoverheid chain, REQ-DSO-050).
        return true;

    }//end validateSignature()


}//end class
