<?php

/**
 * OpenConnector Cardfeed Controller.
 *
 * REST controller for the corporate-card-feed connector: the enroll +
 * card-discovery endpoint. The scheduled transaction sync is NOT triggered from
 * here — it runs via CardfeedSyncJob (job-scheduling) per design.md. The enroll
 * endpoint is action-RBAC gated (ADR-023, default admin-only) because enrollment
 * grants access to real card data.
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
 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-source-enrollment-and-card-discovery-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\CardfeedProviderException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\CardfeedSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Corporate-card enroll + discovery endpoint.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-source-enrollment-and-card-discovery-req-002
 */
class CardfeedController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName     App identifier ("openconnector").
     * @param IRequest            $request     Current request.
     * @param CardfeedSyncService $syncService Enroll + discovery + sync logic.
     * @param IUserSession        $userSession The user session.
     * @param ActionAuthService   $actionAuth  The action authorization service (ADR-023).
     * @param IL10N               $l           The localization service.
     * @param LoggerInterface     $logger      Logger for non-fatal diagnostics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CardfeedSyncService $syncService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Enroll a card program source: discover its cards and record a cardfeed_account.
     *
     * Idempotent: re-enrolling updates the card set in place on the existing
     * account (REQ-002).
     *
     * @param string $sourceSlug The cardfeed source slug.
     *
     * @return JSONResponse `{accountId, cardfeedSourceSlug, cards, lifecycleState}` or a 400/502 error envelope.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/corporate-card-feed/spec.md#scenario-enrollment-discovers-and-records-cards-idempotently
     */
    #[NoAdminRequired]
    public function enroll(string $sourceSlug=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'cardfeed.enroll');

        if ($sourceSlug === '') {
            return new JSONResponse(
                [
                    'error'   => 'missing_parameters',
                    'message' => $this->l->t('Enroll a card program').': sourceSlug is required.',
                ],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->syncService->enrollSource(sourceSlug: $sourceSlug);

            return new JSONResponse($result);
        } catch (CardfeedProviderException $exception) {
            $this->logger->warning(
                '[CardfeedController] enroll failed: '.$exception->getMessage(),
                ['sourceSlug' => $sourceSlug]
            );
            return new JSONResponse(
                ['error' => 'cardfeed_enroll_failed', 'message' => $this->l->t('Card enrollment failed').': '.$exception->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }//end try

    }//end enroll()
}//end class
