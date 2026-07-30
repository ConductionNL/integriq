<?php
/**
 * OpenConnector EudiIssuerKeyAdminController.
 *
 * Admin-gated (Beheer > Authenticatie) generate/rotate/status endpoints for
 * the EUDI wallet credential issuer's own signing key, mirroring
 * {@see \OCA\OpenConnector\Controller\LtiController}'s Phase 4 tenant-wide
 * key management shape and scholiq's `KeyAdminController` pattern
 * (design.md D-KEY).
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
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\EudiCredentialOfferService;
use OCA\OpenConnector\Service\EudiIssuerKeyService;
use OCA\OpenConnector\Settings\OpenConnectorAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Beheer > Authenticatie: EUDI issuer key section — admin-gated
 * generate/rotate/status. Every method here is guarded by
 * `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` (CSRF-protected,
 * NC-session-authenticated admin only), never `#[PublicPage]`.
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
 */
class EudiIssuerKeyAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                     $appName      The app id.
     * @param IRequest                   $request      The current request.
     * @param EudiIssuerKeyService       $keyService   Issuer signing-key lifecycle.
     * @param EudiCredentialOfferService $offerService Used only to resolve the active organisation id.
     * @param LoggerInterface            $logger       Logger for key lifecycle failures.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly EudiIssuerKeyService $keyService,
        private readonly EudiCredentialOfferService $offerService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Resolve the organisation this admin's key operations apply to
     * (design.md D-KEY organisation scoping — falls back to the single
     * default-organisation scope when OpenRegister/organisation-bridge is
     * unavailable).
     *
     * @return string|null
     */
    private function resolveOrganisationId(): ?string
    {
        return $this->offerService->resolveOrganisationId();

    }//end resolveOrganisationId()

    /**
     * Current issuer key status for the active organisation (public
     * material only — kid, algorithm, and archived-key count).
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function status(): JSONResponse
    {
        $organisationId = $this->resolveOrganisationId();

        try {
            $active = $this->keyService->resolveActiveKey($organisationId);
        } catch (Throwable $exception) {
            $this->logger->error(
                'EudiIssuerKeyAdminController: failed to resolve active key',
                ['exception' => $exception->getMessage()]
            );

            return new JSONResponse(
                data: ['error' => 'Unable to resolve the current issuer key'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(
            data: [
                'kid'       => $active['kid'],
                'algorithm' => EudiIssuerKeyService::ALGORITHM,
            ]
        );

    }//end status()

    /**
     * Generate the issuer signing key for the active organisation. Admin-gated, CSRF-protected.
     *
     * @return JSONResponse The new (redacted) key entry.
     *
     * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function generateKey(): JSONResponse
    {
        try {
            $entry = $this->keyService->generateKey($this->resolveOrganisationId());
        } catch (Throwable $exception) {
            $this->logger->error(
                'EudiIssuerKeyAdminController: key generation failed',
                ['exception' => $exception->getMessage()]
            );

            return new JSONResponse(
                data: ['error' => 'Key generation failed'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $entry);

    }//end generateKey()

    /**
     * Rotate the issuer signing key for the active organisation. Admin-gated, CSRF-protected.
     *
     * @return JSONResponse The new (redacted) key entry.
     *
     * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function rotateKey(): JSONResponse
    {
        try {
            $entry = $this->keyService->rotateKey($this->resolveOrganisationId());
        } catch (Throwable $exception) {
            $this->logger->error(
                'EudiIssuerKeyAdminController: key rotation failed',
                ['exception' => $exception->getMessage()]
            );

            return new JSONResponse(
                data: ['error' => 'Key rotation failed'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $entry);

    }//end rotateKey()
}//end class
