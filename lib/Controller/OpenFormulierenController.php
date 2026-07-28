<?php

/**
 * OpenConnector Open Formulieren Controller.
 *
 * REST controller for the open-formulieren-intake bridge: the signed
 * inbound submission webhook (gated by HMAC, not an NC session — mirrors
 * `PeppolController::inbound()` / `NotifyNlController::inbound()`), a
 * status-read endpoint, and the authenticated handoff-trigger endpoint that
 * executes the declared `ns#Case` handoff under the calling user's own
 * session/RBAC (see design.md §1.1 for why this is a separate, authenticated
 * step rather than automatic at webhook-receipt time).
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
 * @spec openspec/specs/open-formulieren-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\OpenFormulierenException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\OpenFormulierenIntakeService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Controller;
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
 * Signed inbound submission webhook + status read + authenticated handoff trigger.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the handoff-trigger error mapping
 * (REQ-004) legitimately switches on OpenFormulierenException/HandoffException/
 * NotAuthorizedException/Throwable in addition to the controller's normal HTTP/auth
 * collaborators (mirrors PeppolController's error-mapping breadth).
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md
 */
class OpenFormulierenController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                       $appName          App identifier ("openconnector").
     * @param IRequest                     $request          Current request.
     * @param OpenFormulierenIntakeService $intakeService    Ingest / mapping / handoff orchestration.
     * @param WebhookSignatureService      $signatureService HMAC verification for the inbound webhook.
     * @param IUserSession                 $userSession      The user session (status/handoff endpoints).
     * @param ActionAuthService            $actionAuth       The action authorization service.
     * @param IL10N                        $l                The localization service.
     * @param LoggerInterface              $logger           Logger for non-fatal diagnostics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly OpenFormulierenIntakeService $intakeService,
        private readonly WebhookSignatureService $signatureService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Receive a signed Open Formulieren submission.
     *
     * Gated by HMAC (constant-time compare, timestamp tolerance) verified
     * against the active `open-formulieren` source's
     * `configuration.webhookSignature` — an unsigned or tampered submission
     * is rejected 401 BEFORE any state change, no active source fails
     * closed. Expected JSON body: see design.md §5.
     *
     * @return JSONResponse The persisted `openformulieren_submission` record, or a 400/401 error envelope.
     *
     * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-signed-inbound-submission-webhook-req-001
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function inbound(): JSONResponse
    {
        $rawBody = $this->getRawContent();

        try {
            $source = $this->intakeService->resolveActiveSource();
        } catch (OpenFormulierenException) {
            // No source configured => no secret to verify against => fail closed.
            return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
        }

        $webhookConfig = ($source->getObject()['configuration']['webhookSignature'] ?? []);
        $scheme        = ($webhookConfig['scheme'] ?? 'openconnector');
        $secret        = (string) ($webhookConfig['secret'] ?? '');
        $headerName    = ($webhookConfig['header'] ?? 'X-OpenFormulieren-Signature');
        $tolerance     = (int) ($webhookConfig['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS);

        $headerValue = (string) $this->request->getHeader($headerName);

        $verified = $this->signatureService->verify(
            rawBody: $rawBody,
            headerValue: $headerValue,
            config: ['scheme' => $scheme, 'secret' => $secret, 'toleranceSeconds' => $tolerance]
        );

        if ($verified === false) {
            // Undifferentiated error body: never leak which check failed.
            return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
        }

        // Payload access goes through the framework's normalised params (NC decodes
        // a JSON body into params), NOT a second json_decode($rawBody) — signature
        // verification already ran over the exact raw bytes above.
        $body = $this->request->getParams();

        $formSlug = (string) ($body['form']['slug'] ?? '');
        if ($formSlug === '') {
            return new JSONResponse(
                ['error' => 'missing_form_slug', 'message' => $this->l->t('The "form.slug" field is required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        $formUuid       = ($body['form']['uuid'] ?? null);
        $submissionMeta = (array) ($body['submission'] ?? []);
        $values         = (array) ($body['values'] ?? []);
        $attachmentRefs = (array) ($body['attachments'] ?? []);
        $authContext    = ($body['auth'] ?? null);
        if (is_array($authContext) === false) {
            $authContext = null;
        }

        $formUuidValue = null;
        if ($formUuid !== null) {
            $formUuidValue = (string) $formUuid;
        }

        $submission = $this->intakeService->ingest(
            formSlug: $formSlug,
            formUuid: $formUuidValue,
            submissionMeta: $submissionMeta,
            values: $values,
            attachmentRefs: $attachmentRefs,
            authContext: $authContext
        );

        return new JSONResponse($submission->getObject() + ['id' => $submission->getUuid()]);

    }//end inbound()

    /**
     * Read one submission's current status.
     *
     * @param string $id The `openformulieren_submission` uuid.
     *
     * @return JSONResponse The submission record, or a 401/404 error envelope.
     *
     * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-openformulieren-submission-lifecycle-with-per-submission-isolation-req-003
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(string $id=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'open-formulieren.status');

        if ($id === '') {
            return new JSONResponse(
                ['error' => 'missing_id', 'message' => $this->l->t('The submission id is required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->intakeService->getSubmission(submissionUuid: $id);
        } catch (OpenFormulierenException $exception) {
            return new JSONResponse(
                ['error' => 'submission_not_found', 'message' => $exception->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse($result);

    }//end status()

    /**
     * Trigger the declared `submission-to-case` handoff for a `mapped`
     * submission, as the authenticated caller — never a system-account
     * shortcut (design.md §1.1).
     *
     * @param string $id The `openformulieren_submission` uuid.
     *
     * @return JSONResponse The engine's execute() result, or a 400/401/403/404/409 error envelope.
     *
     * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-004
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function handoff(string $id=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'open-formulieren.handoff');

        if ($id === '') {
            return new JSONResponse(
                ['error' => 'missing_id', 'message' => $this->l->t('The submission id is required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->intakeService->handoff(submissionUuid: $id);
            return new JSONResponse($result);
        } catch (OpenFormulierenException $exception) {
            return new JSONResponse(
                ['error' => 'submission_not_ready', 'message' => $exception->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (HandoffException $exception) {
            $status = Http::STATUS_CONFLICT;
            if ($exception->getErrorCode() === HandoffException::NOT_DECLARED) {
                $status = Http::STATUS_NOT_FOUND;
            }

            return new JSONResponse(
                ['error' => $exception->getErrorCode(), 'message' => $exception->getMessage()],
                $status
            );
        } catch (NotAuthorizedException $exception) {
            return new JSONResponse(
                ['error' => 'handoff_not_authorized', 'message' => $exception->getMessage()],
                Http::STATUS_FORBIDDEN
            );
        } catch (Throwable $exception) {
            $this->logger->error(
                '[OpenFormulierenController] handoff failed unexpectedly: '.$exception->getMessage(),
                ['exception' => $exception]
            );
            return new JSONResponse(
                ['error' => 'handoff_failed', 'message' => $exception->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }//end try

    }//end handoff()

    /**
     * Read the raw request body bytes for signature verification.
     *
     * @return string The raw request body.
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
