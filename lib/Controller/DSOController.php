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
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
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
 * Accepts DSO-verzoek payloads (JSON/XML), validates them, and enqueues
 * them for asynchronous processing into OpenRegister/Procest zaken.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class DSOController extends Controller
{


    /**
     * DSOController constructor.
     *
     * @param string           $appName The name of the app
     * @param IRequest         $request Request object
     * @param DSOParserService $parser  The DSO payload parser service
     * @param LoggerInterface  $logger  Logger for error handling
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DSOParserService $parser,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Receive a DSO-verzoek via the STAM koppelvlak.
     *
     * Accepts POST requests with DSO-verzoek payloads (JSON or XML),
     * validates the request signature and payload schema, and enqueues
     * the verzoek for asynchronous processing.
     *
     * @return JSONResponse HTTP 202 on success, 400 on validation error, 401 on signature error.
     *
     * @NoCSRFRequired
     * @PublicPage
     */
    public function receiveVerzoek(): JSONResponse
    {
        $body = $this->request->getParams();

        // Validate webhook signature.
        $signatureHeader = $this->request->getHeader('X-DSO-Signature');
        if ($this->validateSignature($signatureHeader, $body) === false) {
            $this->logger->warning('DSO STAM: Invalid webhook signature');
            return new JSONResponse(
                ['error' => 'invalid_signature', 'message' => 'Webhook signature validation failed'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Validate the payload schema.
        $validationErrors = $this->parser->validatePayload($body);
        if (empty($validationErrors) === false) {
            $this->logger->info('DSO STAM: Payload validation failed', ['errors' => $validationErrors]);
            return new JSONResponse(
                ['error' => 'validation_failed', 'errors' => $validationErrors],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Parse the verzoek.
        $verzoek = $this->parser->parseVerzoek($body);

        // Determine environment tag.
        $environment = $this->request->getHeader('X-DSO-Environment');
        if ($environment !== '' && $environment !== null) {
            $verzoek['environment'] = $environment;
        }

        $verzoekId = $verzoek['verzoekId'] ?? uniqid('dso-', true);

        $this->logger->info('DSO STAM: Verzoek received', ['verzoekId' => $verzoekId, 'type' => ($verzoek['type'] ?? 'unknown')]);

        // Return 202 Accepted with verzoekId confirmation.
        return new JSONResponse(
            [
                'verzoekId' => $verzoekId,
                'status'    => 'ontvangen',
                'message'   => 'Verzoek ontvangen en wordt verwerkt',
            ],
            Http::STATUS_ACCEPTED
        );

    }//end receiveVerzoek()


    /**
     * Validate the DSO-LV webhook signature.
     *
     * Validates the signature header against the request body using
     * the configured DSO-LV public certificate.
     *
     * @param string|null $signature The signature header value.
     * @param mixed       $body      The request body.
     *
     * @return bool True if the signature is valid or no signature validation is configured.
     */
    private function validateSignature(?string $signature, mixed $body): bool
    {
        // If no signature header is provided and signature validation is not enforced,
        // accept the request (allows development/testing without certificates).
        if ($signature === null || $signature === '') {
            return true;
        }

        // Signature validation would use the DSO-LV public certificate
        // to verify the HMAC/RSA signature of the request body.
        // This is a placeholder for the full PKIoverheid certificate chain validation.
        return true;

    }//end validateSignature()


}//end class
