<?php

/**
 * Integriq API Design Check Controller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Service\ApiDesign\ApiDesignFindingStore;
use OCA\Integriq\Service\ApiDesign\ApiProductDesignCheck;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An administrator checks an API product against the design rules, and waives a finding.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class ApiDesignCheckController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                $appName     The app id.
	 * @param IRequest              $request     The request.
	 * @param ApiProductDesignCheck $check       Runs the check.
	 * @param ApiDesignFindingStore $store       Waives a finding.
	 * @param IUserSession          $userSession Who waives.
	 * @param ITimeFactory          $time        When.
	 * @param IL10N                 $l10n        Messages.
	 * @param LoggerInterface       $logger      Logs a check that could not run.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ApiProductDesignCheck $check,
		private readonly ApiDesignFindingStore $store,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Check a product: its findings by severity, and the rules a person checks.
	 *
	 * @param string $productId The api_product uuid.
	 *
	 * @return JSONResponse 200 with findings and manual rules, or 409 when the description cannot be checked.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function check(string $productId): JSONResponse {
		try {
			return new JSONResponse($this->check->checkProduct(productId: $productId));
		} catch (RuntimeException $e) {
			$this->logger->warning('[integriq] API design check could not run', ['errorClass' => get_class($e)]);
			return new JSONResponse(
				['error' => $this->l10n->t('The API product could not be checked: %s', [$e->getMessage()])],
				Http::STATUS_CONFLICT
			);
		}

	}//end check()

	/**
	 * Waive an open finding with a reason.
	 *
	 * @param string $findingId The finding uuid.
	 * @param string $reason    Why it is accepted.
	 *
	 * @return JSONResponse 200 with the waived finding, or 400 without a reason or for a finding that is not open.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function waive(string $findingId, string $reason = ''): JSONResponse {
		try {
			$finding = $this->store->waive(
				findingId: $findingId,
				reason: $reason,
				userId: (string)$this->userSession->getUser()?->getUID(),
				waivedAt: $this->time->getDateTime()->format(DATE_ATOM)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($finding);

	}//end waive()
}//end class
