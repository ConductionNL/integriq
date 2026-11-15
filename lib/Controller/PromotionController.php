<?php

/**
 * Integriq promotion controller.
 *
 * Thin, routed wrapper over {@see PromotionServiceInterface}
 * (environments-and-promotion REQ-002/REQ-003/REQ-005/REQ-006). Both the
 * preview and confirm endpoints are gated by the ADR-023 `environment.promote`
 * action key — distinct from `environment.manage`, which gates
 * {@see EnvironmentController}'s CRUD.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\PromotionServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * Controller exposing promotion preview (REQ-003) and confirmed promotion
 * (REQ-002/REQ-005/REQ-006).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- a controller wires its services, session, action check and l10n
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class PromotionController extends Controller {
	/**
	 * Constructor for the PromotionController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param PromotionServiceInterface $promotionService The promotion orchestration service.
	 * @param IL10N $l The localization service.
	 * @param IUserSession $userSession The user session.
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 *
	 * @return void
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PromotionServiceInterface $promotionService,
		private readonly IL10N $l,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
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
	public function preview(): JSONResponse {
		$promoter = $this->promoter();
		if ($promoter instanceof JSONResponse) {
			return $promoter;
		}

		$promotion = $this->promotionRequest();
		if (is_string($promotion) === true) {
			return new JSONResponse(['error' => $promotion], Http::STATUS_BAD_REQUEST);
		}

		return $this->runPreview(promotion: $promotion);
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
	public function confirm(): JSONResponse {
		$promoter = $this->promoter();
		if ($promoter instanceof JSONResponse) {
			return $promoter;
		}

		$unconfirmed = $this->unconfirmed();
		if ($unconfirmed !== null) {
			return $unconfirmed;
		}

		$promotion = $this->promotionRequest();
		if (is_string($promotion) === true) {
			return new JSONResponse(['error' => $promotion], Http::STATUS_BAD_REQUEST);
		}

		return $this->runPromotion(promotion: $promotion, promoter: $promoter);
	}//end confirm()

	/**
	 * The signed-in user, once they may run the `environment.promote` action.
	 *
	 * @return IUser|JSONResponse The promoter, or 401 without a session, or 403 when the action is refused.
	 */
	private function promoter(): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'environment.promote');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return $user;
	}//end promoter()

	/**
	 * Run the non-mutating preview of a checked request.
	 *
	 * @param PromotionRequest $promotion The configuration, target and bindings.
	 *
	 * @return JSONResponse The merged preview classification, or a 400/502 error.
	 */
	private function runPreview(PromotionRequest $promotion): JSONResponse {
		try {
			$preview = $this->promotionService->preview(
				configurationId: $promotion->configurationId,
				targetEnvironmentSlug: $promotion->targetEnvironmentSlug,
				credentialBindings: $promotion->credentialBindings
			);
		} catch (Throwable $e) {
			return $this->previewFailure(exception: $e);
		}

		return new JSONResponse($preview);
	}//end runPreview()

	/**
	 * Run a confirmed promotion of a checked request.
	 *
	 * @param PromotionRequest $promotion The configuration, target and bindings.
	 * @param IUser            $promoter  The user the audit entry records.
	 *
	 * @return JSONResponse The target's post-import summary plus auditId/callLogId, or a 400/502 error.
	 */
	private function runPromotion(PromotionRequest $promotion, IUser $promoter): JSONResponse {
		try {
			$result = $this->promotionService->promote(
				configurationId: $promotion->configurationId,
				targetEnvironmentSlug: $promotion->targetEnvironmentSlug,
				credentialBindings: $promotion->credentialBindings,
				actorUid: $promoter->getUID(),
				fromEnvironmentSlug: $this->resolveFromEnvironmentSlug(),
				overrideFailingTests: $this->overrideFailingTests()
			);
		} catch (Throwable $e) {
			return $this->promotionFailure(exception: $e);
		}

		return new JSONResponse($result);
	}//end runPromotion()

	/**
	 * The answer for a promotion request without an explicit confirmation.
	 *
	 * @return JSONResponse|null 400 unless `confirmed` is true (or "true"), else null.
	 */
	private function unconfirmed(): JSONResponse|null {
		$confirmed = $this->request->getParam('confirmed');
		if ($confirmed !== true && $confirmed !== 'true') {
			return new JSONResponse(
				['error' => $this->l->t('Promotion requires explicit confirmation — preview first, then send confirmed: true')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end unconfirmed()

	/**
	 * Answer a preview that failed: 400 for a request the service refused, else 502.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse The error envelope.
	 */
	private function previewFailure(Throwable $exception): JSONResponse {
		if ($exception instanceof InvalidArgumentException) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['error' => $this->l->t('Promotion preview failed: %s', [$exception->getMessage()])], Http::STATUS_BAD_GATEWAY);
	}//end previewFailure()

	/**
	 * Answer a promotion that failed: 400 for a request the service refused, else 502.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse The error envelope.
	 */
	private function promotionFailure(Throwable $exception): JSONResponse {
		if ($exception instanceof InvalidArgumentException) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['error' => $this->l->t('Promotion failed: %s', [$exception->getMessage()])], Http::STATUS_BAD_GATEWAY);
	}//end promotionFailure()

	/**
	 * Whether the promoter accepted failing test cases: `overrideFailingTests` true (or "true").
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-the-promotion-preview-shows-failing-cases-before-a-change-goes-live-req-irt-005
	 */
	private function overrideFailingTests(): bool {
		$override = $this->request->getParam('overrideFailingTests', false);

		return $override === true || $override === 'true';
	}//end overrideFailingTests()

	/**
	 * Read the optional `fromEnvironmentSlug` request param, defaulting to
	 * "local" (the seeded local-instance row) whenever it is absent or blank.
	 *
	 * @return string The origin environment slug to record on the audit entry.
	 */
	private function resolveFromEnvironmentSlug(): string {
		$fromEnvironmentSlug = $this->request->getParam('fromEnvironmentSlug', 'local');
		if (is_string($fromEnvironmentSlug) === false || $fromEnvironmentSlug === '') {
			return 'local';
		}

		return $fromEnvironmentSlug;
	}//end resolveFromEnvironmentSlug()

	/**
	 * The shared `configurationId`/`targetEnvironmentSlug`/`credentialBindings`
	 * request params, checked.
	 *
	 * @return PromotionRequest|string The request, or the missing-param error.
	 */
	private function promotionRequest(): PromotionRequest|string {
		$configurationId = $this->request->getParam('configurationId');
		$targetEnvironmentSlug = $this->request->getParam('targetEnvironmentSlug');
		if ($this->isFilled(value: $configurationId) === false || $this->isFilled(value: $targetEnvironmentSlug) === false) {
			return $this->l->t('Request must carry "configurationId" and "targetEnvironmentSlug"');
		}

		$promotion = new PromotionRequest(configurationId: $configurationId, targetEnvironmentSlug: $targetEnvironmentSlug);
		$credentialBindings = $this->request->getParam('credentialBindings', []);
		if (is_array($credentialBindings) === true) {
			$promotion->credentialBindings = $credentialBindings;
		}

		return $promotion;
	}//end promotionRequest()

	/**
	 * Whether a request param is a non-empty string.
	 *
	 * @param mixed $value The param.
	 *
	 * @return bool True for a non-empty string.
	 *
	 * @psalm-assert-if-true non-empty-string $value
	 * @phpstan-assert-if-true non-empty-string $value
	 */
	private function isFilled(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isFilled()
}//end class
