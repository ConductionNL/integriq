<?php

/**
 * OpenConnector Forms Bridge discovery controller.
 *
 * Read-only endpoints backing the synchronization editor's `nextcloud-form`
 * kind: a feature-detection status flag plus form/question discovery, so
 * `sync-editor-ui`'s form picker and field-mapping helper never talk to the
 * Forms API directly. Mirrors `TablesBridgeController` method-for-method
 * (design.md Nextcloud Integration).
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
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\FormsConfigException;
use OCA\OpenConnector\Exception\FormsFeatureDisabledException;
use OCA\OpenConnector\Exception\FormsNotFoundException;
use OCA\OpenConnector\Exception\FormsPermissionDeniedException;
use OCA\OpenConnector\Exception\FormsUpstreamException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\Forms\FormsSyncAdapter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * `nextcloud-form` feature-status + form/question discovery for the sync editor.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 */
class FormsBridgeController extends Controller
{
    /**
     * Constructor.
     *
     * @param string            $appName          The app id.
     * @param IRequest          $request          The HTTP request.
     * @param FormsSyncAdapter  $formsSyncAdapter The Forms sync adapter (feature detection + discovery).
     * @param OrObjectService   $orObjectService  The OR object service (Source lookups).
     * @param IL10N             $l                The localization service.
     * @param LoggerInterface   $logger           The logger.
     * @param IUserSession      $userSession      The user session.
     * @param ActionAuthService $actionAuth       The action authorization service.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly FormsSyncAdapter $formsSyncAdapter,
        private readonly OrObjectService $orObjectService,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * GET /api/synchronizations/forms-bridge/status — whether `nextcloud-form`
     * is available for the acting user (Forms app enabled).
     *
     * Backs the sync editor's kind selector (`sync-editor-ui` REQ-SYNCUI-008):
     * `nextcloud-form` is only offered when this reports `enabled: true`.
     *
     * @return JSONResponse `{"enabled": bool}`.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(['enabled' => $this->formsSyncAdapter->isEnabled(user: $user)]);

    }//end status()

    /**
     * GET /api/synchronizations/forms-bridge/forms — list the forms
     * accessible to a Source's configured identity.
     *
     * @param string|null $sourceId The `Source` id whose credentials list the forms.
     *
     * @return JSONResponse `{"results": [...]}`, or a mapped error.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function forms(?string $sourceId=null): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'synchronization.formsBridge.discover');

        if ($sourceId === null || $sourceId === '') {
            return new JSONResponse(['error' => $this->l->t('sourceId is required')], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->formsSyncAdapter->assertEnabled(user: $user);
            $source = $this->findSourceObject(sourceId: $sourceId);
            $forms  = $this->formsSyncAdapter->listFormsForEditor(source: $source);

            return new JSONResponse(['results' => $forms]);
        } catch (\Throwable $exception) {
            return $this->mapException(exception: $exception);
        }

    }//end forms()

    /**
     * GET /api/synchronizations/forms-bridge/forms/{formId}/questions — list a
     * form's questions with type metadata for the mapping helper.
     *
     * @param int         $formId   The Forms form id.
     * @param string|null $sourceId The `Source` id whose credentials read the questions.
     *
     * @return JSONResponse `{"results": [...]}`, or a mapped error.
     *
     * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function questions(int $formId, ?string $sourceId=null): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'synchronization.formsBridge.discover');

        if ($sourceId === null || $sourceId === '') {
            return new JSONResponse(['error' => $this->l->t('sourceId is required')], Http::STATUS_BAD_REQUEST);
        }

        if ($formId <= 0) {
            return new JSONResponse(['error' => $this->l->t('formId must be numeric')], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->formsSyncAdapter->assertEnabled(user: $user);
            $source    = $this->findSourceObject(sourceId: $sourceId);
            $questions = $this->formsSyncAdapter->listQuestionsForEditor(source: $source, formId: $formId);

            return new JSONResponse(['results' => $questions]);
        } catch (\Throwable $exception) {
            return $this->mapException(exception: $exception);
        }

    }//end questions()

    /**
     * Resolve a Source by id, admin-context (the acting user need not have
     * direct OR access to the admin-only `source` schema — the engine reads
     * it on their behalf), mirroring `TablesBridgeController::findSourceObject()`.
     *
     * @param string $sourceId The OpenRegister id/uuid of the Source.
     *
     * @return ObjectEntity
     *
     * @throws FormsNotFoundException When no Source matches the id.
     */
    private function findSourceObject(string $sourceId): ObjectEntity
    {
        try {
            return $this->orObjectService->find(
                id: $sourceId,
                register: 'openconnector',
                schema: 'source',
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $exception) {
            throw new FormsNotFoundException(message: 'Source not found: '.$sourceId);
        }

    }//end findSourceObject()

    /**
     * Map a thrown exception to the same error-code table `TablesBridgeController`
     * uses (409/404/exception-code/exception-code/502).
     *
     * @param \Throwable $exception The exception to map.
     *
     * @return JSONResponse
     */
    private function mapException(\Throwable $exception): JSONResponse
    {
        if ($exception instanceof FormsFeatureDisabledException) {
            $this->logger->info('FormsBridgeController: Forms app not enabled', ['message' => $exception->getMessage()]);
            return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_CONFLICT);
        }

        if ($exception instanceof FormsNotFoundException) {
            return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
        }

        if ($exception instanceof FormsPermissionDeniedException) {
            return new JSONResponse(['error' => $exception->getMessage()], $exception->getCode());
        }

        if ($exception instanceof FormsConfigException) {
            return new JSONResponse(['error' => $exception->getMessage()], $exception->getCode());
        }

        if ($exception instanceof FormsUpstreamException) {
            $this->logger->warning('FormsBridgeController: upstream Forms failure', ['message' => $exception->getMessage()]);
            return new JSONResponse(['error' => $this->l->t('Upstream Forms call failed')], Http::STATUS_BAD_GATEWAY);
        }

        $this->logger->error('FormsBridgeController: unexpected error', ['message' => $exception->getMessage()]);
        return new JSONResponse(['error' => $this->l->t('Unexpected error')], Http::STATUS_BAD_GATEWAY);

    }//end mapException()
}//end class
