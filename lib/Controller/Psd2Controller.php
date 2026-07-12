<?php

/**
 * OpenConnector PSD2 Controller.
 *
 * REST controller for the psd2-ais-bank-feed-connector: the redirect-based
 * SCA connect/callback endpoints and account discovery. The scheduled
 * transaction sync is NOT triggered from here — it runs via BankfeedSyncJob
 * (job-scheduling) per design.md. All endpoints are action-RBAC gated
 * (ADR-023, default admin-only) because a consent grants read access to real
 * bank data.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\Psd2ProviderException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\BankfeedSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * PSD2 SCA connect/callback + account discovery endpoints.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#requirement-redirect-based-sca-consent-flow-req-002
 */
class Psd2Controller extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName     App identifier ("openconnector").
     * @param IRequest            $request     Current request.
     * @param BankfeedSyncService $syncService SCA flow + discovery + sync logic.
     * @param IUserSession        $userSession The user session.
     * @param ActionAuthService   $actionAuth  The action authorization service (ADR-023).
     * @param IL10N               $l           The localization service.
     * @param LoggerInterface     $logger      Logger for non-fatal diagnostics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly BankfeedSyncService $syncService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Start the redirect-based SCA flow: returns the bank SCA redirect URL.
     *
     * @param string $sourceSlug    The PSD2 aggregator source slug.
     * @param string $institutionId The aggregator institution (bank) identifier.
     * @param string $redirectUrl   Where the operator's browser returns after bank SCA.
     *
     * @return JSONResponse `{redirectUrl, reference, connectionId}` or a 400/502 error envelope.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#scenario-connect-returns-a-bank-sca-redirect-url
     */
    #[NoAdminRequired]
    public function connect(string $sourceSlug='', string $institutionId='', string $redirectUrl=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'bankfeed.connect');

        if ($sourceSlug === '' || $institutionId === '' || $redirectUrl === '') {
            return new JSONResponse(
                [
                    'error'   => 'missing_parameters',
                    'message' => $this->l->t('Connect a bank account').': sourceSlug, institutionId and redirectUrl are required.',
                ],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->syncService->connect(
                sourceSlug: $sourceSlug,
                institutionId: $institutionId,
                redirectUrl: $redirectUrl
            );

            return new JSONResponse($result);
        } catch (Psd2ProviderException $exception) {
            $this->logger->warning(
                '[Psd2Controller] connect failed: '.$exception->getMessage(),
                ['sourceSlug' => $sourceSlug]
            );
            return new JSONResponse(
                ['error' => 'psd2_connect_failed', 'message' => $exception->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }//end try

    }//end connect()

    /**
     * Complete the SCA flow after the bank redirected the operator back.
     *
     * Validates the `ref` against the pending requisition created at connect
     * time (CSRF/mix-up defence) and only redirects to the `redirectUrl`
     * registered at connect time (open-redirect defence). `#[NoCSRFRequired]`
     * is present because the browser arrives here from an external bank
     * redirect and cannot carry an NC request token; the in-body reference
     * validation + action RBAC are the auth body for this route.
     *
     * @param string $ref The aggregator consent reference from the redirect.
     *
     * @return RedirectResponse|JSONResponse Redirect to the registered return URL, or a 400/502 error envelope.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#scenario-callback-finalises-consent-and-stores-only-the-reference
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function callback(string $ref=''): RedirectResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'bankfeed.connect');

        if ($ref === '') {
            return new JSONResponse(
                ['error' => 'missing_reference', 'message' => 'The consent reference (ref) is required.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->syncService->finaliseConsent(reference: $ref);
        } catch (Psd2ProviderException $exception) {
            // Unknown/replayed reference or aggregator failure: reject with an
            // error envelope — never redirect anywhere not registered at
            // connect time (open-redirect defence).
            $this->logger->warning('[Psd2Controller] callback rejected: '.$exception->getMessage(), ['ref' => $ref]);
            return new JSONResponse(
                ['error' => 'psd2_callback_rejected', 'message' => $exception->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }

        $separator = '?';
        if (str_contains($result['redirectUrl'], '?') === true) {
            $separator = '&';
        }

        return new RedirectResponse(
            $result['redirectUrl'].$separator.'connectionId='.rawurlencode($result['connectionId']).'&consent=granted'
        );

    }//end callback()

    /**
     * Discover (or re-discover) the accounts authorised by an active consent.
     *
     * @param string $connectionId The bankfeed connection identifier.
     *
     * @return JSONResponse `{accounts: [...]}` or a 400/502 error envelope.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#scenario-accounts-are-discovered-and-recorded-for-an-active-connection
     */
    #[NoAdminRequired]
    public function discoverAccounts(string $connectionId=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'bankfeed.discover');

        if ($connectionId === '') {
            return new JSONResponse(
                ['error' => 'missing_connection', 'message' => 'The connectionId is required.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $accounts = $this->syncService->discoverAccounts(connectionId: $connectionId);

            return new JSONResponse(['accounts' => $accounts]);
        } catch (Psd2ProviderException $exception) {
            $this->logger->warning(
                '[Psd2Controller] account discovery failed: '.$exception->getMessage(),
                ['connectionId' => $connectionId]
            );
            return new JSONResponse(
                ['error' => 'discovery_failed', 'message' => $this->l->t('Account discovery failed').': '.$exception->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }//end try

    }//end discoverAccounts()
}//end class
