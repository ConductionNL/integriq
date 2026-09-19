<?php

/**
 * Integriq IntakeChannelsController.
 *
 * The HTTP surface of channel intake: the signed public webhook every channel
 * delivers on, the channel descriptions a configuration screen reads, the
 * routing rule save that refuses a mapping onto a field the case type does
 * not have, and the reply that goes back over the arriving channel.
 *
 * The inbound endpoint verifies the signature over the raw bytes before the
 * body is read. A public endpoint that parses first is a public endpoint that
 * can be attacked with a payload.
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Inbound, description, rule save and reply endpoints for intake channels.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-submission-arrives-over-a-signed-webhook-and-maps-to-a-case-type-req-ic-003
 */
class IntakeChannelsController extends Controller {

	/**
	 * The ADR-023 action saving a routing rule is gated by.
	 *
	 * @var string
	 */
	public const ACTION_RULES = 'intake.rules';

	/**
	 * The ADR-023 action replying to an inbound message is gated by.
	 *
	 * @var string
	 */
	public const ACTION_REPLY = 'intake.reply';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Names the principal making a write.
	 * @param ActionAuthService $actionAuth The ADR-023 action gate.
	 * @param IntakeChannelRegistry $registry The channels this instance has.
	 * @param IntakeChannelSourceResolver $sourceResolver Finds a channel's webhook secret.
	 * @param IntakeRoutingService $routingService Routes and validates.
	 * @param IntakeReplyService $replyService Replies on the arriving channel.
	 * @param WebhookSignatureService $signatureService Verifies the inbound signature.
	 * @param OrObjectService $orObjectService Stores routing rules and rejections.
	 * @param IL10N $l Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IntakeChannelRegistry $registry,
		private readonly IntakeChannelSourceResolver $sourceResolver,
		private readonly IntakeRoutingService $routingService,
		private readonly IntakeReplyService $replyService,
		private readonly WebhookSignatureService $signatureService,
		private readonly OrObjectService $orObjectService,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Receive one message on a channel.
	 *
	 * @param string $channel The channel id.
	 *
	 * @return JSONResponse What happened to the message.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-submission-arrives-over-a-signed-webhook-and-maps-to-a-case-type-req-ic-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(string $channel): JSONResponse {
		$rawBody = $this->getRawContent();
		$configuration = $this->sourceResolver->configurationFor($channel);
		if ($configuration === null) {
			// No source, no secret to verify against: fail closed rather than
			// accepting an unverifiable payload on an unconfigured channel.
			return $this->refused(channel: $channel, reason: 'no configured channel source');
		}

		$signature = ($configuration['webhookSignature'] ?? []);
		if (is_array($signature) === false) {
			$signature = [];
		}

		$headerName = (string)($signature['header'] ?? 'X-OpenConnector-Signature');
		$verified = $this->signatureService->verify(
			rawBody: $rawBody,
			headerValue: (string)$this->request->getHeader($headerName),
			config: [
				'scheme' => (string)($signature['scheme'] ?? 'openconnector'),
				'secret' => (string)($signature['secret'] ?? ''),
				'toleranceSeconds' => (int)($signature['toleranceSeconds']
					?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS),
			]
		);

		if ($verified === false) {
			return $this->refused(channel: $channel, reason: 'invalid signature');
		}

		try {
			$adapter = $this->registry->get($channel);
			$message = $adapter->receive($this->request->getParams());
			$stored = $this->routingService->route($message);
		} catch (IntakeChannelException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$object = $stored->getObject();

		return new JSONResponse(
			[
				'id' => (string)$stored->getUuid(),
				'status' => (string)($object['status'] ?? ''),
				'reason' => (string)($object['reason'] ?? ''),
				'targetRef' => (string)($object['targetRef'] ?? ''),
			],
			Http::STATUS_ACCEPTED
		);

	}//end inbound()

	/**
	 * What every channel on this instance can do.
	 *
	 * @return JSONResponse The channel descriptions.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-channel-is-a-declared-adapter-behind-one-contract-req-ic-001
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function channels(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['channels' => $this->registry->describeAll()]);

	}//end channels()

	/**
	 * Save one routing rule, refusing a mapping the case type cannot hold.
	 *
	 * @param string $id The rule id when updating, empty when creating.
	 *
	 * @return JSONResponse The saved rule, or why it was refused.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-routing-rule-maps-a-channel-and-a-payload-onto-a-case-type-req-ic-002
	 */
	#[NoAdminRequired]
	public function saveRule(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_RULES);

		$rule = $this->request->getParams();
		unset($rule['id'], $rule['_route']);

		try {
			$this->routingService->validateRule($rule, $this->registry);
		} catch (IntakeRoutingException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$targetUuid = $id;
		if ($targetUuid === '') {
			$targetUuid = null;
		}

		$status = Http::STATUS_OK;
		if ($id === '') {
			$status = Http::STATUS_CREATED;
		}

		$saved = $this->orObjectService->saveObject(
			object: $rule,
			register: IntakeRoutingService::REGISTER,
			schema: IntakeRoutingService::SCHEMA_RULE,
			uuid: $targetUuid,
		);

		return new JSONResponse(
			['id' => (string)$saved->getUuid(), 'rule' => $saved->getObject()],
			$status
		);

	}//end saveRule()

	/**
	 * Reply to an inbound message on the channel it arrived on.
	 *
	 * @param string $id The `intake_message` uuid.
	 *
	 * @return JSONResponse What happened to the reply.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-reply-goes-back-over-the-channel-it-arrived-on-req-ic-005
	 */
	#[NoAdminRequired]
	public function reply(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_REPLY);

		$text = (string)$this->request->getParam('text', '');
		if (trim($text) === '') {
			return new JSONResponse(
				['error' => $this->l->t('A reply needs something to say.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->replyService->reply($id, $text);
		} catch (IntakeChannelException $exception) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result->toArray());

	}//end reply()

	/**
	 * Record a refused delivery and answer without saying which check failed.
	 *
	 * @param string $channel The channel id.
	 * @param string $reason Why it was refused, recorded but not returned.
	 *
	 * @return JSONResponse The undifferentiated refusal.
	 */
	private function refused(string $channel, string $reason): JSONResponse {
		try {
			$this->orObjectService->saveObject(
				object: [
					'channelId' => $channel,
					'externalId' => '',
					'status' => 'rejected',
					'reason' => $reason,
				],
				register: IntakeRoutingService::REGISTER,
				schema: IntakeRoutingService::SCHEMA_MESSAGE,
			);
		} catch (Throwable) {
			// The rejection record is evidence, not the refusal itself: a
			// storage failure must not turn a refusal into an acceptance.
		}

		return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);

	}//end refused()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * Verification MUST run over the exact bytes the sender signed, not the
	 * framework's normalised params, which would desync.
	 *
	 * @return string The raw request body.
	 */
	protected function getRawContent(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;

	}//end getRawContent()

}//end class
