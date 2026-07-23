<?php

/**
 * OpenConnector Notificaties Subscriber Controller.
 *
 * REST controller for the notificaties-api-subscriber capability: abonnement
 * CRUD (authenticated NC-session, action-RBAC gated) and the inbound ZGW
 * Notificaties API callback (no NC session — consumer-apiKey authenticated,
 * following the PeppolController::inbound()/Psd2Controller::callback()
 * precedent for a dedicated controller route rather than a generic
 * endpoint-runtime target; see design.md Decision 1).
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
 * @spec openspec/specs/notificaties-api-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\AuthenticationException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\NotificatiesSubscriberService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Abonnement CRUD + inbound ZGW Notificaties API callback.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002
 */
class NotificatiesSubscriberController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                        $appName              App identifier ("openconnector").
     * @param IRequest                      $request              Current request.
     * @param NotificatiesSubscriberService $subscriberService    Abonnement lifecycle + notification normalization.
     * @param AuthorizationService          $authorizationService Reused REQ-CON-001/REQ-CON-002 consumer apiKey auth path.
     * @param OrObjectService               $orObjectService      Direct OR access for abonnement listing.
     * @param ActionAuthService             $actionAuth           The action authorization service (ADR-023).
     * @param IUserSession                  $userSession          The user session (CRUD endpoints only).
     * @param IL10N                         $l                    The localization service.
     * @param LoggerInterface               $logger               Logger for non-fatal diagnostics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly NotificatiesSubscriberService $subscriberService,
        private readonly AuthorizationService $authorizationService,
        private readonly OrObjectService $orObjectService,
        private readonly ActionAuthService $actionAuth,
        private readonly IUserSession $userSession,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * List abonnementen.
     *
     * @return JSONResponse `{results: [...]}`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnementen-config-ui-req-008
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'notificaties.list');

        $matches = $this->orObjectService->findAll(
                config: [
                    'filters' => ['register' => 'openconnector', 'schema' => 'notificaties_abonnement'],
                    'limit'   => (int) $this->request->getParam('limit', 50),
                    'offset'  => (int) $this->request->getParam('offset', 0),
                ]
                );
        $results = ($matches['results'] ?? $matches);

        return new JSONResponse(['results' => array_map(static fn ($abonnement) => $abonnement->getObject(), $results)]);

    }//end index()

    /**
     * Register a new abonnement.
     *
     * @return JSONResponse The created abonnement, or a 400 error envelope.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'notificaties.create');

        $data = $this->request->getParams();
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        try {
            $abonnement = $this->subscriberService->createAbonnement(config: $data);

            return new JSONResponse($abonnement->getObject());
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end create()

    /**
     * Update an abonnement's kanalen/filters.
     *
     * @param string $id The abonnement UUID.
     *
     * @return JSONResponse The updated abonnement, or a 400/404 error envelope.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'notificaties.update');

        $data = $this->request->getParams();
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        try {
            $abonnement = $this->subscriberService->updateAbonnement(id: $id, config: $data);

            return new JSONResponse($abonnement->getObject());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Abonnement not found')], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end update()

    /**
     * Delete an abonnement (remote DELETE + cascade-delete companion consumer).
     *
     * @param string $id The abonnement UUID.
     *
     * @return JSONResponse The abonnement's resulting state, or a 404 error envelope.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'notificaties.delete');

        try {
            $abonnement = $this->subscriberService->deleteAbonnement(id: $id);

            return new JSONResponse($abonnement->getObject());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Abonnement not found')], Http::STATUS_NOT_FOUND);
        }

    }//end destroy()

    /**
     * Receive an inbound ZGW Notificaties API notification.
     *
     * No Nextcloud session is involved — the remote Notificaties Routeer
     * Component (NRC) posts here directly, authenticated by echoing the
     * per-abonnement secret back as `authHeaderName` (default `Authorization`,
     * design.md Decision 4 — configurable, not hardcoded). The abonnement is
     * resolved FIRST only to read that per-abonnement header configuration
     * and its companion `consumerId` for a defense-in-depth cross-check
     * (REQ-002 requires *a* matching consumer; this additionally requires it
     * to be *this abonnement's own* consumer) — no side-effecting processing
     * of any kind runs before {@see AuthorizationService::authorizeApiKey()}
     * passes.
     *
     * @param string $abonnementId The abonnement UUID from the route.
     *
     * @return JSONResponse `{received: true}` on success, 401 on auth failure, 400 on a malformed body.
     *
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function callback(string $abonnementId): JSONResponse
    {
        $abonnement = $this->subscriberService->findAbonnement(abonnementId: $abonnementId);
        if ($abonnement === null) {
            // Undifferentiated error: never leak whether the abonnement exists.
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->authenticateCallback(abonnement: $abonnement) === false) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->stripInternalParams(params: $this->request->getParams());

        try {
            $this->subscriberService->handleInboundNotification(abonnementId: $abonnementId, notification: $body);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            $this->logger->error(
                '[NotificatiesSubscriberController] inbound notification processing failed: '.$e->getMessage(),
                ['exception' => $e, 'abonnementId' => $abonnementId]
            );
            return new JSONResponse(['error' => 'processing_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['received' => true]);

    }//end callback()

    /**
     * Verify the callback's `Authorization` (or per-abonnement configured
     * header, Decision 4) against {@see AuthorizationService::authorizeApiKey()},
     * then cross-check the resolved consumer is THIS abonnement's own
     * companion consumer (defense-in-depth beyond REQ-002's literal "*a*
     * matching consumer" text — the presented credential must not merely
     * authenticate SOME consumer, it must be the one bound to this
     * abonnementId).
     *
     * @param ObjectEntity $abonnement The resolved abonnement.
     *
     * @return boolean True when the request is authenticated for this abonnement.
     *
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002
     */
    private function authenticateCallback(ObjectEntity $abonnement): bool
    {
        $abonnementData = $abonnement->getObject();
        $headerName     = (string) ($abonnementData['authHeaderName'] ?? 'Authorization');
        $scheme         = (string) ($abonnementData['authScheme'] ?? '');

        $presented = (string) $this->request->getHeader($headerName);
        if ($scheme !== '' && str_starts_with($presented, $scheme) === true) {
            $presented = substr($presented, strlen($scheme));
        }

        try {
            $this->authorizationService->authorizeApiKey(header: $presented, keys: []);
        } catch (AuthenticationException $e) {
            return false;
        }

        $resolvedConsumer = $this->authorizationService->getResolvedConsumer();
        $consumerId       = (string) ($abonnementData['consumerId'] ?? '');

        return ($resolvedConsumer !== null && $consumerId !== '' && $resolvedConsumer->getUuid() === $consumerId);

    }//end authenticateCallback()

    /**
     * Strip Nextcloud-internal params (e.g. `_route`) from a request's
     * decoded params.
     *
     * @param array $params The raw params from `IRequest::getParams()`.
     *
     * @return array The params with any `_`-prefixed key removed.
     */
    private function stripInternalParams(array $params): array
    {
        foreach (array_keys($params) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($params[$key]);
            }
        }

        return $params;

    }//end stripInternalParams()
}//end class
