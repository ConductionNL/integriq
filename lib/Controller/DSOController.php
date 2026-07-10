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
use OCA\OpenConnector\Service\DSOSignatureVerifierService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
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
class DSOController extends Controller
{
    /**
     * DSOController constructor.
     *
     * @param string                      $appName           The name of the app.
     * @param IRequest                    $request           Request object.
     * @param DSOParserService            $parser            The DSO payload parser service.
     * @param LoggerInterface             $logger            Logger for error handling.
     * @param DSOSignatureVerifierService $signatureVerifier PKIoverheid / HMAC webhook signature verifier.
     *
     * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DSOParserService $parser,
        private readonly LoggerInterface $logger,
        private readonly DSOSignatureVerifierService $signatureVerifier
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
     * The route is registered in `appinfo/routes.php` guarded by
     * {@see DSOSignatureVerifierService}: every request MUST carry an
     * `X-DSO-Signature` header that cryptographically verifies (HMAC
     * shared-secret in pre-production, PKIoverheid certificate-chain RSA in
     * production) before the payload is parsed. The `#[PublicPage]` /
     * `#[NoCSRFRequired]` attributes are present because the endpoint
     * authenticates via webhook signature, not NC session — the hydra
     * semantic-auth gate (`public-page-annotation-with-auth-body`) is
     * satisfied because the signature check IS the auth body for this route.
     *
     * @return JSONResponse HTTP 202 on success, 400 on validation error, 401 on signature error.
     *
     * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-4
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function receiveVerzoek(): JSONResponse
    {
        $rawBody = $this->getRawContent();
        $body    = $this->request->getParams();

        // Validate webhook signature over the exact raw body bytes.
        $signatureHeader = $this->request->getHeader('X-DSO-Signature');
        if ($this->signatureVerifier->verify(signatureHeader: $signatureHeader, rawBody: $rawBody) === false) {
            $this->logger->warning(
                'DSO STAM: Webhook signature validation failed',
                ['hasSignatureHeader' => ($signatureHeader !== '' && $signatureHeader !== null)]
            );
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
     * Read the raw request body bytes for signature verification.
     *
     * Signature verification MUST run over the exact bytes DSO-LV signed,
     * not `$this->request->getParams()` (which is decoded/normalized and
     * would desync from the signed payload for non-form content types).
     *
     * @return string The raw request body.
     *
     * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
     */
    private function getRawContent(): string
    {
        $content = file_get_contents(filename: 'php://input');
        if ($content === false) {
            return '';
        }

        return $content;

    }//end getRawContent()
}//end class
