<?php

/**
 * OpenConnector promotion controller.
 *
 * Thin, routed wrapper over {@see \OCA\OpenConnector\Service\PromotionService}
 * (environments-and-promotion REQ-002/REQ-003/REQ-005/REQ-006). Both the
 * preview and confirm endpoints are gated by the ADR-023 `environment.promote`
 * action key — distinct from `environment.manage`, which gates
 * {@see EnvironmentController}'s CRUD.
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
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use InvalidArgumentException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\PromotionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Controller exposing promotion preview (REQ-003) and confirmed promotion
 * (REQ-002/REQ-005/REQ-006).
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class PromotionController extends Controller
{
    /**
     * Constructor for the PromotionController.
     *
     * @param string            $appName          The name of the app.
     * @param IRequest          $request          The request object.
     * @param PromotionService  $promotionService The promotion orchestration service.
     * @param IL10N             $l                The localization service.
     * @param IUserSession      $userSession      The user session.
     * @param ActionAuthService $actionAuth       The action authorization service.
     *
     * @return void
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PromotionService $promotionService,
        private readonly IL10N $l,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Non-mutating diff preview (REQ-003): merges the target's own REQ-007
     * classification with the locally-scanned `credentialRefsNeedingRebind`
     * bucket. Nothing is written on either side.
     *
     * `#[NoAdminRequired]` + the ADR-023 `environment.promote` action gate
     * in the body (hydra no-admin-idor gate).
     *
     * @return JSONResponse The merged preview classification.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/environments-and-promotion/spec.md#scenario-preview-reflects-the-targets-own-createsupdatescollisions-classification
     */
    #[NoAdminRequired]
    public function preview(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'environment.promote');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        [$configurationId, $targetEnvironmentSlug, $credentialBindings, $missing] = $this->extractPromotionParams();
        if ($missing !== null) {
            return new JSONResponse(['error' => $missing], Http::STATUS_BAD_REQUEST);
        }

        try {
            $preview = $this->promotionService->preview(
                configurationId: $configurationId,
                targetEnvironmentSlug: $targetEnvironmentSlug,
                credentialBindings: $credentialBindings
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $this->l->t('Promotion preview failed: %s', [$e->getMessage()])], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse($preview);

    }//end preview()

    /**
     * Confirmed promotion (REQ-002/REQ-005/REQ-006). Requires `confirmed:
     * true`, mirroring `configuration-export-import` REQ-008; rejects with
     * 400 otherwise, dispatching nothing and writing no audit object.
     *
     * `#[NoAdminRequired]` + the ADR-023 `environment.promote` action gate
     * in the body (hydra no-admin-idor gate).
     *
     * @return JSONResponse The target's post-import summary plus auditId/callLogId.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/environments-and-promotion/spec.md#scenario-promotion-without-confirmation-is-rejected
     */
    #[NoAdminRequired]
    public function confirm(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'environment.promote');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        $confirmed = $this->request->getParam('confirmed');
        if ($confirmed !== true && $confirmed !== 'true') {
            return new JSONResponse(
                ['error' => $this->l->t('Promotion requires explicit confirmation — preview first, then send confirmed: true')],
                Http::STATUS_BAD_REQUEST
            );
        }

        [$configurationId, $targetEnvironmentSlug, $credentialBindings, $missing] = $this->extractPromotionParams();
        if ($missing !== null) {
            return new JSONResponse(['error' => $missing], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->promotionService->promote(
                configurationId: $configurationId,
                targetEnvironmentSlug: $targetEnvironmentSlug,
                credentialBindings: $credentialBindings,
                actorUid: $user->getUID(),
                fromEnvironmentSlug: $this->resolveFromEnvironmentSlug()
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $this->l->t('Promotion failed: %s', [$e->getMessage()])], Http::STATUS_BAD_GATEWAY);
        }

        return new JSONResponse($result);

    }//end confirm()

    /**
     * Read the optional `fromEnvironmentSlug` request param, defaulting to
     * "local" (the seeded local-instance row) whenever it is absent or blank.
     *
     * @return string The origin environment slug to record on the audit entry.
     */
    private function resolveFromEnvironmentSlug(): string
    {
        $fromEnvironmentSlug = $this->request->getParam('fromEnvironmentSlug', 'local');
        if (is_string($fromEnvironmentSlug) === false || $fromEnvironmentSlug === '') {
            return 'local';
        }

        return $fromEnvironmentSlug;

    }//end resolveFromEnvironmentSlug()

    /**
     * Extract and validate the shared `configurationId`/`targetEnvironmentSlug`/
     * `credentialBindings` request params.
     *
     * @return array{0: string, 1: string, 2: array<int,array<string,mixed>>, 3: string|null}
     *         `[configurationId, targetEnvironmentSlug, credentialBindings, missingParamError]`.
     */
    private function extractPromotionParams(): array
    {
        $configurationId       = $this->request->getParam('configurationId');
        $targetEnvironmentSlug = $this->request->getParam('targetEnvironmentSlug');
        $credentialBindings    = $this->request->getParam('credentialBindings', []);

        if (is_string($configurationId) === false || $configurationId === ''
            || is_string($targetEnvironmentSlug) === false || $targetEnvironmentSlug === ''
        ) {
            return ['', '', [], $this->l->t('Request must carry "configurationId" and "targetEnvironmentSlug"')];
        }

        if (is_array($credentialBindings) === false) {
            $credentialBindings = [];
        }

        return [$configurationId, $targetEnvironmentSlug, $credentialBindings, null];

    }//end extractPromotionParams()
}//end class
