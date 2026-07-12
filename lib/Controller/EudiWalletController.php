<?php
/**
 * OpenConnector EudiWalletController.
 *
 * Dedicated protocol controller for the OpenID4VCI (pre-authorized code
 * flow) EUDI wallet credential issuer surface: issuer metadata, app-facing
 * offer creation, wallet-facing offer resolution, token exchange,
 * credential issuance, status-list publish, and revocation.
 *
 * Mirrors {@see \OCA\OpenConnector\Controller\LtiController} /
 * {@see \OCA\OpenConnector\Controller\DSOController}'s dedicated-controller
 * shape (not the generic Endpoint pipeline) — see proposal.md's cited
 * precedent and design.md D-ROUTE.
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
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\AuthenticationException;
use OCA\OpenConnector\Exception\EudiIssuanceException;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\EudiCredentialOfferService;
use OCA\OpenConnector\Service\EudiIssuerKeyService;
use OCA\OpenConnector\Service\EudiStatusListService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the OpenID4VCI EUDI wallet credential issuance protocol
 * endpoints, plus the app-facing offer creation/revocation contract.
 *
 * Every wallet-facing route here is called by an external EUDI wallet or
 * verifier, never by an NC session — authentication is the protocol itself
 * (a valid pre-authorized_code, a wallet key-binding proof, or a
 * consumer-issued JWT bearer token per REQ-CON-001/authorization-jwt
 * REQ-001), which is why every action carries
 * `#[PublicPage]`/`#[NoCSRFRequired]` (hydra-gate-route-auth is satisfied
 * because the protocol check IS the auth body, matching the DSOController/
 * LtiController precedent).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md
 */
class EudiWalletController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                     $appName              The app id.
     * @param IRequest                   $request              The current request.
     * @param EudiCredentialOfferService $offerService         Offer/token/credential/revocation lifecycle.
     * @param EudiIssuerKeyService       $keyService           Issuer signing-key service (metadata JWKS).
     * @param EudiStatusListService      $statusListService    Status-list token publish.
     * @param AuthorizationService       $authorizationService Reused consumer JWT bearer verification (REQ-001).
     * @param LoggerInterface            $logger               Logger for protocol-level rejections.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EudiCredentialOfferService $offerService,
        private readonly EudiIssuerKeyService $keyService,
        private readonly EudiStatusListService $statusListService,
        private readonly AuthorizationService $authorizationService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Map an {@see EudiIssuanceException} to its declared HTTP status.
     *
     * @param EudiIssuanceException $exception The exception to render.
     *
     * @return JSONResponse
     */
    private function renderRejection(EudiIssuanceException $exception): JSONResponse
    {
        $this->logger->info(
            'EudiWalletController: request rejected',
            [
                'message'   => $exception->getMessage(),
                'status'    => $exception->getHttpStatus(),
                'errorCode' => $exception->getErrorCode(),
            ]
        );

        return new JSONResponse(
            data: [
                'error'             => ($exception->getErrorCode() ?? 'invalid_request'),
                'error_description' => $exception->getMessage(),
            ],
            statusCode: $exception->getHttpStatus()
        );

    }//end renderRejection()

    /**
     * Build this instance's base URL (protocol + host, no path).
     *
     * @return string
     */
    private function buildBaseUrl(): string
    {
        return $this->request->getServerProtocol().'://'.$this->request->getServerHost();

    }//end buildBaseUrl()

    /**
     * Extract a Bearer token from the Authorization header.
     *
     * @return string|null The token value, or null when absent/malformed.
     */
    private function extractBearerToken(): ?string
    {
        $header = $this->request->getHeader('Authorization');
        if (str_starts_with($header, 'Bearer ') === false) {
            return null;
        }

        $token = trim(substr($header, strlen('Bearer ')));
        if ($token === '') {
            return null;
        }

        return $token;

    }//end extractBearerToken()

    /**
     * Authenticate the app-facing caller as a registered consumer.
     *
     * Reuses `consumer-management` REQ-CON-001 + `authorization-jwt`
     * REQ-001 verbatim (no new auth mechanism, design.md D-TRUST/proposal.md):
     * requires a `Authorization: Bearer <jwt>` header whose issuer resolves
     * to a registered `consumer` object via
     * {@see AuthorizationService::authorizeJwt()}.
     *
     * @return string|JSONResponse The resolved consumer's uuid, or a 401
     *                              JSONResponse when authentication fails.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-offer-creation-is-consumer-gated-and-app-facing-req-eudi-004
     */
    private function authenticateConsumer(): string|JSONResponse
    {
        $header = $this->request->getHeader('Authorization');
        if (str_starts_with($header, 'Bearer ') === false) {
            return new JSONResponse(
                data: ['error' => 'invalid_token', 'message' => 'Missing bearer token'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $this->authorizationService->authorizeJwt(authorization: $header);
        } catch (AuthenticationException $exception) {
            $this->logger->info(
                'EudiWalletController: consumer authentication failed',
                ['message' => $exception->getMessage()]
            );

            return new JSONResponse(
                data: ['error' => 'invalid_token', 'message' => 'Consumer authentication failed'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $consumer = $this->authorizationService->getResolvedConsumer();
        if ($consumer === null) {
            return new JSONResponse(
                data: ['error' => 'invalid_token', 'message' => 'No consumer could be resolved for this token'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        return (string) ($consumer->getUuid() ?? '');

    }//end authenticateConsumer()

    // =========================================================================
    // REQ-EUDI-003 — issuer metadata
    // =========================================================================

    /**
     * OpenID4VCI Credential Issuer Metadata.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-metadata-endpoint-req-eudi-003
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function issuerMetadata(): JSONResponse
    {
        $organisationId = $this->offerService->resolveOrganisationId();
        $baseUrl        = $this->buildBaseUrl();
        $apiBase        = $baseUrl.'/index.php/apps/openconnector/api/eudi';

        $metadata = [
            'credential_issuer'                   => $baseUrl,
            'credential_endpoint'                 => $apiBase.'/credential',
            'token_endpoint'                      => $apiBase.'/token',
            'credential_configurations_supported' => [
                'edci-diploma'  => [
                    'format'                => 'jwt_vc_json',
                    'scope'                 => 'edci_diploma',
                    'credential_definition' => [
                        'type' => ['VerifiableCredential', 'EuropeanDigitalCredential'],
                    ],
                ],
                'open-badges-3' => [
                    'format' => 'dc+sd-jwt',
                    'scope'  => 'open_badges_3',
                    'vct'    => 'open-badges-3',
                ],
            ],
            'jwks'                                => $this->keyService->getJwks($organisationId),
        ];

        return new JSONResponse(data: $metadata);

    }//end issuerMetadata()

    // =========================================================================
    // REQ-EUDI-004 — app-facing offer creation (consumer-gated)
    // =========================================================================

    /**
     * Create a credential offer (app-facing, consumer-gated).
     *
     * @return JSONResponse `{offerUrl, credentialOfferUri, qrPayload}` on success; 401/400 on failure.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-offer-creation-is-consumer-gated-and-app-facing-req-eudi-004
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function createOffer(): JSONResponse
    {
        $consumerId = $this->authenticateConsumer();
        if ($consumerId instanceof JSONResponse) {
            return $consumerId;
        }

        try {
            $result = $this->offerService->createOffer(data: $this->request->getParams(), consumerId: $consumerId);
        } catch (EudiIssuanceException $exception) {
            return $this->renderRejection(exception: $exception);
        }

        $credentialOfferUri = $this->buildBaseUrl()
            .'/index.php/apps/openconnector/api/eudi/credential-offers/'.$result['uuid'];
        $offerUrl           = 'openid-credential-offer://?credential_offer_uri='.rawurlencode($credentialOfferUri);

        return new JSONResponse(
            data: [
                'offerUrl'           => $offerUrl,
                'credentialOfferUri' => $credentialOfferUri,
                'qrPayload'          => $offerUrl,
            ]
        );

    }//end createOffer()

    /**
     * Revoke a credential offer's issued credential (app-facing, consumer-gated).
     *
     * @param string $id The offer uuid to revoke.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-revocation-flips-one-status-list-bit-and-fires-a-signed-callback-req-eudi-009
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function revoke(string $id): JSONResponse
    {
        $consumerId = $this->authenticateConsumer();
        if ($consumerId instanceof JSONResponse) {
            return $consumerId;
        }

        try {
            $result = $this->offerService->revoke(offerUuid: $id, consumerId: $consumerId);
        } catch (EudiIssuanceException $exception) {
            return $this->renderRejection(exception: $exception);
        }

        return new JSONResponse(data: ['status' => 'revoked', 'alreadyRevoked' => $result['alreadyRevoked']]);

    }//end revoke()

    // =========================================================================
    // REQ-EUDI-005 — wallet-facing offer resolution
    // =========================================================================

    /**
     * Resolve a credential offer for wallet consumption (single-fetch).
     *
     * @param string $id The offer uuid.
     *
     * @return JSONResponse The OpenID4VCI `credential_offer` object, or a
     *                       generic 404 when not found/expired/consumed.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-offer-resolution-is-public-single-fetch-short-ttl-req-eudi-005
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function resolveOffer(string $id): JSONResponse
    {
        $offer = $this->offerService->resolveOfferForWallet($id);
        if ($offer === null) {
            return new JSONResponse(
                data: ['error' => 'not_found', 'message' => 'Credential offer not found or expired'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(data: $offer);

    }//end resolveOffer()

    // =========================================================================
    // REQ-EUDI-006 — token endpoint
    // =========================================================================

    /**
     * Exchange a pre-authorized_code for an access token.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-token-endpoint-issues-a-single-use-pre-authorized-code-grant-req-eudi-006
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function token(): JSONResponse
    {
        try {
            $result = $this->offerService->exchangeToken($this->request->getParams());
        } catch (EudiIssuanceException $exception) {
            return $this->renderRejection(exception: $exception);
        }

        return new JSONResponse(data: $result);

    }//end token()

    // =========================================================================
    // REQ-EUDI-007 — credential endpoint
    // =========================================================================

    /**
     * Issue a credential (Bearer + proof-of-possession).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-endpoint-verifies-proof-of-possession-and-dispatches-by-format-req-eudi-007
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function credential(): JSONResponse
    {
        $token = ($this->extractBearerToken() ?? '');

        try {
            $result = $this->offerService->issueCredential($token, $this->request->getParams());
        } catch (EudiIssuanceException $exception) {
            return $this->renderRejection(exception: $exception);
        }

        return new JSONResponse(data: $result);

    }//end credential()

    // =========================================================================
    // REQ-EUDI-008 — status list publish
    // =========================================================================

    /**
     * Publish an OAuth Status List Token.
     *
     * @param string $id The status list row uuid.
     *
     * @return DataDisplayResponse|JSONResponse The raw compact JWT
     *                                           (`application/statuslist+jwt`) on success; a JSON 404 when not found.
     *
     * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function statusList(string $id): DataDisplayResponse|JSONResponse
    {
        $token = $this->statusListService->getPublishedToken($id);
        if ($token === null) {
            return new JSONResponse(
                data: ['error' => 'not_found', 'message' => 'Status list not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return new DataDisplayResponse(
            data: $token,
            statusCode: Http::STATUS_OK,
            headers: ['Content-Type' => 'application/statuslist+jwt']
        );

    }//end statusList()
}//end class
