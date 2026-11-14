<?php

/**
 * Integriq OutboundRecipientsController.
 *
 * The preview of a message's recipients: the send screen hands over the
 * standing recipients, the additions and the suppressions, and gets back what
 * the send would record and who the transport would receive, before anything
 * leaves. It runs the send's own resolution, writes no record and calls no
 * transport.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-resolved-list-is-shown-before-the-send-req-mrs-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Outbound\RecipientResolver;
use OCA\Integriq\Outbound\RecipientSelectionRefusedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Resolves a message's recipients without sending.
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-resolved-list-is-shown-before-the-send-req-mrs-005
 */
class OutboundRecipientsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string            $appName           The app id.
	 * @param IRequest          $request           The request.
	 * @param IUserSession      $userSession       Names the principal.
	 * @param RecipientResolver $recipientResolver The send's own resolution.
	 * @param IL10N             $l                 Translations.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-resolved-list-is-shown-before-the-send-req-mrs-005
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly RecipientResolver $recipientResolver,
		private readonly IL10N $l,
	) {
		parent::__construct($appName, $request);

	}//end __construct()

	/**
	 * Resolve the recipients the request names, without recording or sending.
	 *
	 * The body carries `standing`, `added` and `suppressed`, as on a delivery
	 * request. It reads nothing stored, so it needs no more than a session.
	 *
	 * @return JSONResponse The standing, added, suppressed and resolved parts; 422 with the
	 *                      refusal when the decision cannot be honoured.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-resolved-list-is-shown-before-the-send-req-mrs-005
	 */
	#[NoAdminRequired]
	public function preview(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$decision = [];
		foreach (['standing', 'added', 'suppressed'] as $part) {
			$value = $this->request->getParam($part, []);
			if (is_array($value) === true) {
				$decision[$part] = $value;
			}
		}

		try {
			$selection = $this->recipientResolver->resolve(decision: $decision, userId: $user->getUID());
		} catch (RecipientSelectionRefusedException $e) {
			return new JSONResponse(
				['error' => $e->getMessage(), 'code' => $e->getRefusalCode(), 'address' => $e->getAddress()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse($selection);

	}//end preview()
}//end class
