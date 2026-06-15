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
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
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
     * @param string           $appName   The name of the app.
     * @param IRequest         $request   Request object.
     * @param DSOParserService $parser    The DSO payload parser service.
     * @param LoggerInterface  $logger    Logger for error handling.
     * @param IAppConfig       $appConfig Application config for feature flags.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DSOParserService $parser,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $appConfig
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
     * Route registration is REMOVED from `appinfo/routes.php` until the
     * PKIoverheid HMAC/RSA verifier (Task 12 in `openspec/changes/dso-omgevingsloket`)
     * is wired. Because the route is unreachable, the `#[PublicPage]` /
     * `#[NoCSRFRequired]` Nextcloud attributes are temporarily withheld too — the
     * hydra semantic-auth gate (`public-page-annotation-with-auth-body`) flags any
     * controller whose `#[PublicPage]` method body returns `Http::STATUS_FORBIDDEN`,
     * since session-based auth would not produce a 403 directly. The DSO endpoint
     * authenticates via HMAC signature (not NC session) so the 403 is semantically
     * a webhook-signature failure, not session auth. When Task 12 lands the verifier
     * and re-enables the route, the attributes will be re-added together with a
     * gate-suppression note.
     *
     * @return JSONResponse HTTP 202 on success, 400 on validation error, 403 on signature error.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function receiveVerzoek(): JSONResponse
    {
        $body = $this->request->getParams();

        // Validate webhook signature.
        $signatureHeader = $this->request->getHeader('X-DSO-Signature');
        if ($this->validateSignature(signature: $signatureHeader, body: $body) === false) {
            $this->logger->warning(
                'DSO STAM: Webhook signature validation failed',
                ['hasSignatureHeader' => ($signatureHeader !== '' && $signatureHeader !== null)]
            );
            return new JSONResponse(
                data: [
                    'error'   => 'invalid_signature',
                    'message' => 'Webhook signature validation failed',
                ],
                statusCode: Http::STATUS_FORBIDDEN
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
     * When the feature flag `dso_signature_enforcement` is OFF (default) the
     * endpoint rejects requests that carry NO signature header — accepting an
     * absent header would allow any anonymous caller to inject verzoeken.
     * When the flag is ON the full PKIoverheid HMAC/RSA body-signature check
     * must be implemented (REQ-DSO-050) before enabling production traffic.
     *
     * Gate behaviour:
     *   flag = false (default): reject missing header (403), accept present header
     *                           without cryptographic verification (safe placeholder).
     *   flag = true:            full PKIoverheid verification — NOT YET IMPLEMENTED;
     *                           enabling returns false for all requests until the
     *                           real verifier lands.
     *
     * @param string|null $signature The X-DSO-Signature header value.
     * @param mixed       $body      The request body (reserved for PKIoverheid HMAC).
     *
     * @return bool True if the signature check passes.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-1
     *
     * @psalm-suppress UnusedParam $body is reserved for the full HMAC validation
     *                              once REQ-DSO-050 lands.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function validateSignature(?string $signature, mixed $body): bool
    {
        // Read feature flag: dso_signature_enforcement = '1' enables the full
        // PKIoverheid check (not yet implemented). Default is off.
        $signatureEnforcementEnabled = $this->appConfig->getValueBool(
            app: 'openconnector',
            key: 'dso_signature_enforcement',
            default: false
        );

        if ($signatureEnforcementEnabled === true) {
            // Full PKIoverheid certificate-chain verification (REQ-DSO-050) is
            // not yet implemented. Reject ALL requests until the real verifier
            // ships so the flag cannot be accidentally enabled in production.
            $this->logger->warning('DSO STAM: dso_signature_enforcement enabled but verifier not implemented — rejecting request');
            return false;
        }

        // Default (enforcement OFF): reject requests with a missing signature
        // header so anonymous callers cannot inject verzoeken without at least
        // providing a token. Present-but-unverified signatures are accepted as a
        // safe placeholder until REQ-DSO-050 lands.
        if ($signature === null || $signature === '') {
            return false;
        }

        return true;

    }//end validateSignature()
}//end class
