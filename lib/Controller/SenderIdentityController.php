<?php

/**
 * Integriq SenderIdentityController.
 *
 * The identities an organisation sends under, what their domains say about
 * them, the unsubscribe link a recipient follows, and taking a message back
 * before it leaves.
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Outbound\Identity\DomainAlignmentChecker;
use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use RuntimeException;

/**
 * Identity, alignment, unsubscribe and withdraw endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class SenderIdentityController extends Controller {

	/**
	 * The ADR-023 action reading and checking identities is gated by.
	 *
	 * @var string
	 */
	public const ACTION_IDENTITIES = 'outbound.identities';

	/**
	 * The ADR-023 action taking a message back is gated by.
	 *
	 * @var string
	 */
	public const ACTION_WITHDRAW = 'outbound.withdraw';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Names the principal.
	 * @param ActionAuthService $actionAuth The ADR-023 action gate.
	 * @param SenderIdentityService $identities The identities on this instance.
	 * @param DomainAlignmentChecker $alignment Checks SPF, DKIM and DMARC.
	 * @param UnsubscribeTokenService $tokens Mints and verifies unsubscribe tokens.
	 * @param OptOutRegistry $optOuts Holds the opt-outs an unsubscribe adds to.
	 * @param HoldQueue $holdQueue Holds and withdraws a message inside its window.
	 * @param IL10N $l Translations.
	 * @param IURLGenerator $urls Builds the confirmation form's target.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly SenderIdentityService $identities,
		private readonly DomainAlignmentChecker $alignment,
		private readonly UnsubscribeTokenService $tokens,
		private readonly OptOutRegistry $optOuts,
		private readonly HoldQueue $holdQueue,
		private readonly IL10N $l,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The identities on this instance, with what their domains say.
	 *
	 * @return JSONResponse The identities.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_IDENTITIES);

		$identities = [];
		foreach ($this->identities->all() as $entity) {
			$identity = $entity->getObject();
			$identities[] = [
				'id' => (string)$entity->getUuid(),
				'displayName' => (string)($identity['displayName'] ?? ''),
				'address' => (string)($identity['address'] ?? ''),
				'isDefault' => (bool)($identity['isDefault'] ?? false),
				'quotingLevel' => (string)($identity['quotingLevel'] ?? SenderIdentityService::QUOTING_LAST),
				'holdWindowSeconds' => (int)($identity['holdWindowSeconds'] ?? 0),
				'alignment' => ($identity['alignment'] ?? null),
				'envelope' => $this->identities->envelopeFor($identity),
			];
		}

		return new JSONResponse(['identities' => $identities]);

	}//end index()

	/**
	 * Check one identity's domain now.
	 *
	 * @param string $id The identity id.
	 *
	 * @return JSONResponse What the domain says, and what to publish where it says nothing.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	#[NoAdminRequired]
	public function checkAlignment(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_IDENTITIES);

		try {
			$resolved = $this->identities->resolve($id);
		} catch (RuntimeException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$alignment = $this->alignment->check($resolved['identity']);

		return new JSONResponse(
			['alignment' => $alignment, 'atRisk' => $this->alignment->isAtRisk($alignment)]
		);

	}//end checkAlignment()

	/**
	 * Take a message back before its hold window closes.
	 *
	 * @param string $id The outbound message uuid.
	 *
	 * @return JSONResponse What happened.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	#[NoAdminRequired]
	public function withdraw(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_WITHDRAW);

		try {
			$record = $this->holdQueue->withdraw($id, $user->getUID());
		} catch (RuntimeException $exception) {
			// The window has closed. Saying so is the honest answer: nothing
			// can be recalled once a message has left.
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(['id' => (string)$record->getUuid(), 'status' => 'withdrawn']);

	}//end withdraw()

	/**
	 * Show what the link in a message stops, and ask to confirm.
	 *
	 * GET changes nothing (RFC 8058): mail scanners fetch links before a
	 * person does, so a GET that wrote would unsubscribe people who never
	 * clicked. No login, no account: the person following this link usually
	 * has neither.
	 *
	 * @param string $token The signed token from the link.
	 *
	 * @return TemplateResponse The confirmation page: 200 to confirm, 410 when the
	 *         link expired, 400 when it does not verify.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function unsubscribe(string $token): TemplateResponse {
		$claim = $this->tokens->inspect($token);
		$refusal = $this->refusalFor(claim: $claim);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->confirmPage(token: $token, claim: $claim);

	}//end unsubscribe()

	/**
	 * Resolve a short SMS link to its token and show the same confirmation.
	 *
	 * @param string $shortToken The ten-character id from the SMS. It is the capability: random,
	 *                           and it resolves only to a token whose signature is checked next.
	 *
	 * @return TemplateResponse The confirmation page, or 400 when the id is unknown or expired.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function shortLink(string $shortToken): TemplateResponse {
		$token = $this->tokens->resolveShort($shortToken);
		if ($token === null) {
			return $this->unsubscribePage(
				state: 'invalid',
				message: $this->l->t('This link is not valid. Nothing was changed.'),
				status: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->unsubscribe(token: $token);

	}//end shortLink()

	/**
	 * Write the opt-out the link stands for. The page's button and a mail
	 * provider's one-click POST (`List-Unsubscribe=One-Click`) both land here.
	 *
	 * The opt-out goes into integriq's own table after the signature is
	 * verified; OpenRegister is not touched (ADR-099 section 9 keeps
	 * runAsSystem() off request paths). Answers 200 with no redirect, as RFC
	 * 8058 asks. `choice=all` stops everything that is not statutory.
	 *
	 * @param string $token The signed token from the link.
	 * @param string $choice `this` (what the link names) or `all`.
	 *
	 * @return TemplateResponse 200 when stopped, 410 when the link expired, 400 when it does not verify.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function unsubscribeConfirm(string $token, string $choice = 'this'): TemplateResponse {
		$claim = $this->tokens->inspect($token);
		$refusal = $this->refusalFor(claim: $claim);
		if ($refusal !== null) {
			return $refusal;
		}

		$source = 'unsubscribe-link';
		if ($claim['format'] === 'v1') {
			$source = 'unsubscribe-link-v1';
		}

		if ((string)$this->request->getParam('List-Unsubscribe', '') === 'One-Click') {
			$source = 'one-click';
		}

		if ($choice === 'all') {
			$this->optOuts->record(
				[
					'address' => $claim['address'],
					'state' => 'opted-out',
					'scope' => OptOutRegistry::SCOPE_INSTANCE,
					'source' => $source,
					'sourceApp' => 'integriq',
				]
			);

			return $this->unsubscribePage(
				state: 'stopped',
				message: $this->l->t('Done. You will no longer receive messages from us, except statutory notices such as a besluit.'),
				status: Http::STATUS_OK
			);
		}

		if ($claim['format'] !== UnsubscribeTokenService::PREFIX_V3) {
			$this->optOuts->add($claim['address'], OptOutRegistry::SCOPE_CASE, $claim['caseRef'], $source);
		}

		if ($claim['format'] === UnsubscribeTokenService::PREFIX_V3) {
			$this->optOuts->record(
				[
					'address' => $claim['address'],
					'state' => 'opted-out',
					'scope' => $claim['scope'],
					'channel' => $claim['channel'],
					'ref' => $claim['ref'],
					'source' => $source,
					'sourceApp' => 'integriq',
				]
			);
		}

		return $this->unsubscribePage(
			state: 'stopped',
			message: $this->describe(claim: $claim) . ' ' . $this->l->t('Statutory notices, such as a besluit, are still sent.'),
			status: Http::STATUS_OK
		);

	}//end unsubscribeConfirm()

	/**
	 * The decision log: suppressions, overrides, changes and allowed counts.
	 *
	 * Administrators only: no NoAdminRequired, so Nextcloud refuses everyone
	 * else before this runs.
	 *
	 * @param int    $limit         At most this many rows (1 to 500).
	 * @param int    $offset        Skip this many.
	 * @param string $correlationId Only this correlation id, when given.
	 *
	 * @return JSONResponse `{results}`.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	#[NoCSRFRequired]
	public function optOutLog(int $limit = 50, int $offset = 0, string $correlationId = ''): JSONResponse {
		return new JSONResponse($this->optOuts->logPage(limit: $limit, offset: $offset, correlationId: $correlationId));

	}//end optOutLog()

	/**
	 * The page for a link that is expired or does not verify, or null.
	 *
	 * @param array<string,string> $claim What the token says.
	 *
	 * @return TemplateResponse|null The page, or null when the link is usable.
	 */
	private function refusalFor(array $claim): ?TemplateResponse {
		if ($claim['status'] === UnsubscribeTokenService::STATUS_EXPIRED) {
			return $this->unsubscribePage(
				state: 'expired',
				message: $this->l->t('This link has expired. Nothing was changed. Use the link in a more recent message.'),
				status: Http::STATUS_GONE
			);
		}

		if ($claim['status'] !== UnsubscribeTokenService::STATUS_VALID || $claim['address'] === '') {
			return $this->unsubscribePage(
				state: 'invalid',
				message: $this->l->t('This link is not valid. Nothing was changed.'),
				status: Http::STATUS_BAD_REQUEST
			);
		}

		return null;

	}//end refusalFor()

	/**
	 * What a link stops, in the reader's language.
	 *
	 * @param array<string,string> $claim What the token says.
	 *
	 * @return string The sentence.
	 */
	private function describe(array $claim): string {
		return match ($claim['scope']) {
			OptOutRegistry::SCOPE_CHANNEL => $this->l->t('You will no longer receive these messages by %s.', [$claim['channel']]),
			OptOutRegistry::SCOPE_LIST => $this->l->t('You will no longer receive messages from this list.'),
			OptOutRegistry::SCOPE_INSTANCE => $this->l->t('You will no longer receive messages from us.'),
			default => $this->l->t('You will no longer receive updates about this case.'),
		};

	}//end describe()

	/**
	 * The confirmation page: what stops, a button, and nothing written yet.
	 *
	 * @param string $token The token.
	 * @param array<string,string> $claim What the token says.
	 *
	 * @return TemplateResponse The page.
	 */
	private function confirmPage(string $token, array $claim): TemplateResponse {
		$response = $this->unsubscribePage(
			state: 'confirm',
			message: $this->describe(claim: $claim) . ' ' . $this->l->t('Statutory notices, such as a besluit, are still sent. Nothing has changed yet.'),
			status: Http::STATUS_OK
		);
		$params = $response->getParams();
		$params['action'] = $this->urls->linkToRoute('integriq.senderIdentity.unsubscribeConfirm', ['token' => $token]);
		$params['offerAll'] = ($claim['scope'] !== OptOutRegistry::SCOPE_INSTANCE);
		$response->setParams($params);

		return $response;

	}//end confirmPage()

	/**
	 * The opt-outs on this instance, newest first, from integriq's table.
	 *
	 * Administrators only: no NoAdminRequired, so Nextcloud refuses everyone
	 * else before this runs.
	 *
	 * @param int $limit  At most this many rows (1 to 500).
	 * @param int $offset Skip this many.
	 *
	 * @return JSONResponse `{results, total}`.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	#[NoCSRFRequired]
	public function optOuts(int $limit = 50, int $offset = 0): JSONResponse {
		return new JSONResponse($this->optOuts->page(limit: $limit, offset: $offset));

	}//end optOuts()

	/**
	 * The guest page after following a link.
	 *
	 * @param string $state   `confirm`, `stopped`, `expired` or `invalid`.
	 * @param string $message What happened, in the reader's language.
	 * @param int    $status  The HTTP status.
	 *
	 * @return TemplateResponse The page.
	 */
	private function unsubscribePage(string $state, string $message, int $status): TemplateResponse {
		$response = new TemplateResponse(
			$this->appName,
			'unsubscribe',
			[
				'state' => $state,
				'stopped' => ($state === 'stopped'),
				'message' => $message,
				'l10n' => $this->l,
			],
			TemplateResponse::RENDER_AS_GUEST
		);
		$response->setStatus($status);

		return $response;

	}//end unsubscribePage()

}//end class
