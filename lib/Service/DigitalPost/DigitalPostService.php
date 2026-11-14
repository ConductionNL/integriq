<?php

/**
 * Tracks a letter from the request to whatever became of it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Event\DigitalPostDeliveredEvent;
use OCA\Integriq\Event\DigitalPostSendRequestedEvent;
use OCA\Integriq\Outbound\OutboundSendGateInterface;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A send is a tracked message, not a fire and forget. The message exists
 * before the provider is called, so a provider that throws leaves a letter
 * somebody can see and retry rather than a gap in a log.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002
 */
class DigitalPostService implements DigitalPostServiceInterface {

	/**
	 * The refusal code when there is no usable digital post account.
	 *
	 * @var string
	 */
	public const CODE_NO_SERVICE_ACCOUNT = 'no_service_account';

	/**
	 * Constructor.
	 *
	 * @param DigitalPostProviderRegistry $providers The bindings.
	 * @param ConnectionStore $connectionStore Source lookup by slug.
	 * @param OrObjectService $objectService OpenRegister's object-service facade.
	 * @param IEventDispatcher $eventDispatcher The Nextcloud event dispatcher.
	 * @param LoggerInterface $logger Structured logger.
	 * @param OutboundSendGateInterface $gate Asks the opt-out list, adds the link, keeps the outbound log row.
	 * @param DigitalPostAccount $account The service account every digital post write runs as.
	 */
	public function __construct(
		private readonly DigitalPostProviderRegistry $providers,
		private readonly ConnectionStore $connectionStore,
		private readonly OrObjectService $objectService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly OutboundSendGateInterface $gate,
		private readonly DigitalPostAccount $account,
	) {
	}//end __construct()

	/**
	 * Handle a send request from another app.
	 *
	 * @param DigitalPostSendRequestedEvent $event The request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function handleSendRequest(DigitalPostSendRequestedEvent $event): void {
		$send = $this->startSend(event: $event);
		if ($send === null) {
			return;
		}

		$this->account->runOrRefuse(
			what: 'send',
			operation: fn () => $this->sendAsAccount(send: $send),
			refuse: fn (string $reason) => $this->refuseWithoutAccount(event: $event, reason: $reason)
		);

	}//end handleSendRequest()

	/**
	 * Start a send from its source, refusing a request whose source or provider is unknown.
	 *
	 * @param DigitalPostSendRequestedEvent $event The request.
	 *
	 * @return DigitalPostSend|null The send, or null when the request was refused.
	 */
	private function startSend(DigitalPostSendRequestedEvent $event): ?DigitalPostSend {
		$config = $this->sourceConfig(sourceId: $event->getSourceId());
		if ($config === null) {
			$this->refuseUnknownSource(event: $event);
			return null;
		}

		$send = new DigitalPostSend(event: $event, config: $config);
		if ($this->providers->has($send->providerId) === false) {
			$this->refuseUnknownProvider(send: $send);
			return null;
		}

		return $send;
	}//end startSend()

	/**
	 * Refuse a request naming a source nobody configured.
	 *
	 * @param DigitalPostSendRequestedEvent $event The request.
	 *
	 * @return void
	 */
	private function refuseUnknownSource(DigitalPostSendRequestedEvent $event): void {
		$event->setHandled(true);
		$event->setRefusal(
			sprintf('No digital post source is configured under "%s". Nothing was sent.', $event->getSourceId()),
			'unknown_source'
		);
	}//end refuseUnknownSource()

	/**
	 * Refuse a request whose source names a provider nothing answers to.
	 *
	 * @param DigitalPostSend $send The send.
	 *
	 * @return void
	 */
	private function refuseUnknownProvider(DigitalPostSend $send): void {
		$send->event->setHandled(true);
		$send->event->setRefusal(
			sprintf(
				'The source "%s" names the provider "%s", which nothing answers to. Registered providers: %s.',
				$send->event->getSourceId(),
				$send->providerId,
				implode(', ', $this->providers->ids())
			),
			'unknown_provider'
		);
	}//end refuseUnknownProvider()

	/**
	 * Refuse a send for which no usable digital post account exists.
	 *
	 * @param DigitalPostSendRequestedEvent $event  The request.
	 * @param string                        $reason Why the account is not usable.
	 *
	 * @return void
	 */
	private function refuseWithoutAccount(DigitalPostSendRequestedEvent $event, string $reason): void {
		$event->setHandled(true);
		$event->setRefusal($reason . ' The letter cannot be stored, so nothing was sent.', self::CODE_NO_SERVICE_ACCOUNT);
	}//end refuseWithoutAccount()

	/**
	 * Ask the opt-outs, store, send and record, as the digital post account.
	 *
	 * @param DigitalPostSend $send The send.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	private function sendAsAccount(DigitalPostSend $send): void {
		$decision = $this->askOptOuts(event: $send->event);
		if ($decision === null) {
			return;
		}

		$send->decision = $decision;
		$send->body = $this->composedBody(send: $send);
		$send->message = $this->queuedMessage(send: $send);
		if ($this->store(send: $send) === true) {
			$this->deliver(send: $send);
		}
	}//end sendAsAccount()

	/**
	 * The body as the opt-out gate composes it for this letter.
	 *
	 * @param DigitalPostSend $send The send, its decision set.
	 *
	 * @return string The body.
	 */
	private function composedBody(DigitalPostSend $send): string {
		return $this->gate->compose(
			body: $send->event->getBody(),
			decision: $send->decision,
			channel: 'digital-post',
			caseRef: $send->event->getCaseRef()
		)['body'];
	}//end composedBody()

	/**
	 * The message as it is stored before the provider is called.
	 *
	 * @param DigitalPostSend $send The send, its body composed.
	 *
	 * @return array<string,mixed> The queued message.
	 */
	private function queuedMessage(DigitalPostSend $send): array {
		$event = $send->event;
		$letter = [
			'recipient' => $event->getRecipient(),
			'subject' => $event->getSubject(),
			'body' => $send->body,
			'attachments' => $event->getAttachments(),
		];

		return ($letter + $this->messageOrigin(send: $send) + $this->queuedState());
	}//end queuedMessage()

	/**
	 * Who asked for the letter, from where, through which provider and about what.
	 *
	 * @param DigitalPostSend $send The send.
	 *
	 * @return array<string,string> The origin fields.
	 */
	private function messageOrigin(DigitalPostSend $send): array {
		return [
			'requestedBy' => $send->event->getRequestedBy(),
			'sourceApp' => $send->event->getSourceApp(),
			'sourceId' => $send->event->getSourceId(),
			'providerId' => $send->providerId,
			'correlationId' => $send->event->getCorrelationId(),
			'category' => $send->event->getCategory(),
			'caseRef' => $send->event->getCaseRef(),
		];
	}//end messageOrigin()

	/**
	 * The state a letter is stored in before the provider is called.
	 *
	 * @return array<string,mixed> The state fields.
	 */
	private function queuedState(): array {
		return [
			'status' => DigitalPostResult::STATUS_QUEUED,
			'lastError' => '',
			'simulated' => false,
			'created' => gmdate('c'),
		];
	}//end queuedState()

	/**
	 * Store the queued letter; a letter that cannot be stored is refused and not sent.
	 *
	 * @param DigitalPostSend $send The send, its message set.
	 *
	 * @return bool True when the letter was stored.
	 */
	private function store(DigitalPostSend $send): bool {
		$messageId = $this->persist(message: $send->message, uuid: null);
		$send->event->setHandled(true);

		if ($messageId === null) {
			$send->event->setRefusal('The letter could not be stored, so it was not sent.', 'not_stored');
			return false;
		}

		$send->messageId = $messageId;
		return true;
	}//end store()

	/**
	 * Send the stored letter through its provider, record and announce the outcome, and answer the request.
	 *
	 * @param DigitalPostSend $send The send, its message stored.
	 *
	 * @return void
	 */
	private function deliver(DigitalPostSend $send): void {
		$send->logRow = $this->openLogRow(send: $send);
		$result = $this->sendThroughProvider(send: $send);
		$this->recordOutcome(send: $send, result: $result);

		// The attachments stay on the message whatever happened, which is what
		// "a failed send keeps the letter" means: the PDF is still there to
		// retry with.
		$this->persist(message: array_merge($send->message, $result->toArray()), uuid: $send->messageId);

		$this->announce(
			messageId: $send->messageId,
			previousStatus: DigitalPostResult::STATUS_QUEUED,
			result: $result,
			requestedBy: $send->event->getRequestedBy()
		);
		$this->answer(send: $send, result: $result);
	}//end deliver()

	/**
	 * Open the letter's outbound log row.
	 *
	 * @param DigitalPostSend $send The send, its message stored.
	 *
	 * @return string|null The row.
	 */
	private function openLogRow(DigitalPostSend $send): ?string {
		$event = $send->event;

		return $this->gate->open(
			channel: 'digital-post',
			subjectRef: $event->getCaseRef(),
			subject: $event->getSubject(),
			body: $send->body,
			address: (string)$send->decision['address'],
			options: ['sourceApp' => $event->getSourceApp(), 'correlationId' => $event->getCorrelationId(), 'caseRef' => $event->getCaseRef()],
			decision: $send->decision
		);
	}//end openLogRow()

	/**
	 * Answer the request: the provider's refusal, or the message id.
	 *
	 * @param DigitalPostSend   $send   The send.
	 * @param DigitalPostResult $result What the provider answered.
	 *
	 * @return void
	 */
	private function answer(DigitalPostSend $send, DigitalPostResult $result): void {
		if ($result->isRefused() === true) {
			// A provider that knows why (`not_subscribed`) says so; anything else
			// stays the generic provider refusal the sending apps already read.
			$code = $result->getCode();
			if ($code === '') {
				$code = 'provider_refused';
			}

			$send->event->setRefusal($result->getError(), $code);

			return;
		}

		$send->event->setMessageId($send->messageId);
	}//end answer()

	/**
	 * Ask the opt-out list about this letter.
	 *
	 * The category decides, not the channel (opt-out-before-send): a besluit
	 * by Berichtenbox is sent, a case update respects an opt-out. The
	 * recipient is a BSN, so the opt-out list keys it as a hash. A refusal is
	 * written onto the event and logged.
	 *
	 * @param DigitalPostSendRequestedEvent $event The request.
	 *
	 * @return array<string,mixed>|null The allowing decision, or null when the send was refused.
	 */
	private function askOptOuts(DigitalPostSendRequestedEvent $event): ?array {
		$gateOptions = [
			'caseRef' => $event->getCaseRef(),
			'sourceApp' => $event->getSourceApp(),
			'correlationId' => $event->getCorrelationId(),
		];
		$decision = $this->gate->check(
			channel: 'digital-post',
			category: $event->getCategory(),
			address: $event->getRecipient(),
			options: $gateOptions
		);
		if ($decision['send'] === true) {
			return $decision;
		}

		$event->setHandled(true);
		$event->setRefusal((string)$decision['reason'], (string)$decision['code']);
		$this->gate->recordRefusal(channel: 'digital-post', subjectRef: $event->getCaseRef(), decision: $decision, options: $gateOptions);

		return null;
	}//end askOptOuts()

	/**
	 * Note on the outbound log row whether the provider took the letter.
	 *
	 * @param DigitalPostSend   $send   The send: its log row and recipient key.
	 * @param DigitalPostResult $result What the provider answered.
	 *
	 * @return void
	 */
	private function recordOutcome(DigitalPostSend $send, DigitalPostResult $result): void {
		$address = (string)$send->decision['address'];
		if ($result->isRefused() === true) {
			$this->gate->failed(uuid: $send->logRow, address: $address, step: OutboundSendGateInterface::STEP_SEND, reason: $result->getError());
			return;
		}

		$this->gate->handedOver(uuid: $send->logRow, address: $address, reference: $result->getProviderReference());
	}//end recordOutcome()

	/**
	 * Ask every provider what became of the letters it took.
	 *
	 * @param array<int,array<string,mixed>> $messages The tracked messages to poll, keyed by `uuid`.
	 *
	 * @return int How many messages changed status.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function pollStatuses(array $messages): int {
		$changed = 0;

		foreach ($messages as $message) {
			$messageId = (string)($message['uuid'] ?? $message['id'] ?? '');
			$providerId = (string)($message['providerId'] ?? '');
			$reference = (string)($message['providerReference'] ?? '');
			$previous = (string)($message['status'] ?? '');

			if ($messageId === '' || $reference === '' || $this->providers->has($providerId) === false) {
				continue;
			}

			$config = ($this->sourceConfig(sourceId: (string)($message['sourceId'] ?? '')) ?? []);

			try {
				$result = $this->providers->get($providerId)->status($reference, $config);
			} catch (Throwable $e) {
				$this->logger->warning(
					'digital-post.status.failed',
					['message' => $messageId, 'error' => $e->getMessage()]
				);
				continue;
			}

			if ($result->getStatus() === $previous) {
				continue;
			}

			$saved = $this->persist(message: array_merge($message, $result->toArray()), uuid: $messageId);
			if ($saved === null) {
				// Not stored, so the provider keeps the status for the next run.
				continue;
			}

			$this->providers->get($providerId)->statusRecorded($reference, $config);

			$this->announce(messageId: $messageId, previousStatus: $previous, result: $result, requestedBy: (string)($message['requestedBy'] ?? ''));
			$changed++;
		}//end foreach

		return $changed;
	}//end pollStatuses()

	/**
	 * What a source's digital post looks like right now.
	 *
	 * @param array<int,array<string,mixed>> $messages The tracked messages for that source.
	 *
	 * @return array<string,mixed> Last send, last error and queue depth.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function health(array $messages): array {
		$lastSend = null;
		$lastError = '';
		$queueDepth = 0;

		foreach ($messages as $message) {
			$status = (string)($message['status'] ?? '');
			if ($status === DigitalPostResult::STATUS_QUEUED) {
				$queueDepth++;
			}

			$created = (string)($message['created'] ?? '');
			if ($created !== '' && ($lastSend === null || $created > $lastSend)) {
				$lastSend = $created;
			}

			if ((string)($message['lastError'] ?? '') !== '') {
				$lastError = (string)$message['lastError'];
			}
		}

		return ['lastSend' => $lastSend, 'lastError' => $lastError, 'queueDepth' => $queueDepth];
	}//end health()

	/**
	 * Call the provider, turning anything it throws into a refusal rather than
	 * an exception that loses the letter.
	 *
	 * @param DigitalPostSend $send The send: its provider, message and source configuration.
	 *
	 * @return DigitalPostResult What the provider answered.
	 */
	private function sendThroughProvider(DigitalPostSend $send): DigitalPostResult {
		try {
			return $this->providers->get($send->providerId)->send($send->message, $send->config);
		} catch (Throwable $e) {
			$this->logger->warning('digital-post.send.failed', ['provider' => $send->providerId, 'error' => $e->getMessage()]);

			return DigitalPostResult::refused($e->getMessage());
		}
	}//end sendThroughProvider()

	/**
	 * Announce a status change to whoever asked for the letter.
	 *
	 * @param string $messageId The message id.
	 * @param string $previousStatus The status it moved from.
	 * @param DigitalPostResult $result The new state.
	 * @param string $requestedBy Who asked for the letter.
	 *
	 * @return void
	 */
	private function announce(string $messageId, string $previousStatus, DigitalPostResult $result, string $requestedBy): void {
		$this->eventDispatcher->dispatchTyped(
			new DigitalPostDeliveredEvent(
				messageId: $messageId,
				status: $result->getStatus(),
				requestedBy: $requestedBy,
				previousStatus: $previousStatus,
				simulated: $result->isSimulated(),
				lastError: $result->getError()
			)
		);
	}//end announce()

	/**
	 * The configuration of one digital post source.
	 *
	 * @param string $sourceId The source slug.
	 *
	 * @return array<string,mixed>|null The configuration, or null when there is no such source.
	 */
	private function sourceConfig(string $sourceId): ?array {
		if ($sourceId === '') {
			return null;
		}

		$source = $this->connectionStore->findSourceBySlug(slug: $sourceId);
		if ($source instanceof ObjectEntity === false) {
			return null;
		}

		// Unrendered, so the encrypted transport certificate is still there.
		$data = $this->connectionStore->readSourceRaw(source: $source)->getObject();
		$config = ($data['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = json_decode($config, true);
		}

		if (is_array($config) === true) {
			return $config;
		}

		return [];
	}//end sourceConfig()

	/**
	 * Store a tracked message.
	 *
	 * @param array<string,mixed> $message The message.
	 * @param string|null $uuid Its id, or null to create one.
	 *
	 * @return string|null The message id, or null when it could not be stored.
	 */
	private function persist(array $message, ?string $uuid): ?string {
		try {
			$saved = $this->objectService->saveObject(
				object: $message,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $uuid
			);
		} catch (Throwable $e) {
			$this->logger->warning('digital-post.persist.failed', ['error' => $e->getMessage()]);

			return null;
		}

		$savedUuid = (string)$saved->getUuid();
		if ($savedUuid === '') {
			return $uuid;
		}

		return $savedUuid;
	}//end persist()
}//end class
