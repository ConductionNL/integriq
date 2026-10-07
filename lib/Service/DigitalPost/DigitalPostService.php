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
use OCA\Integriq\Outbound\OutboundSendGate;
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
class DigitalPostService {
	/**
	 * The register integriq's own objects live in.
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema a tracked letter is stored under.
	 */
	public const SCHEMA = 'digitalPostMessage';

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
	 * @param OutboundSendGate $gate Asks the opt-out list, adds the link, keeps the outbound log row.
	 * @param DigitalPostAccount $account The service account every digital post write runs as.
	 */
	public function __construct(
		private readonly DigitalPostProviderRegistry $providers,
		private readonly ConnectionStore $connectionStore,
		private readonly OrObjectService $objectService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly OutboundSendGate $gate,
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
		$config = $this->sourceConfig(sourceId: $event->getSourceId());
		if ($config === null) {
			$event->setHandled(true);
			$event->setRefusal(
				sprintf('No digital post source is configured under "%s". Nothing was sent.', $event->getSourceId()),
				'unknown_source'
			);

			return;
		}

		$providerId = (string)($config['providerId'] ?? '');
		if ($this->providers->has($providerId) === false) {
			$event->setHandled(true);
			$event->setRefusal(
				sprintf(
					'The source "%s" names the provider "%s", which nothing answers to. Registered providers: %s.',
					$event->getSourceId(),
					$providerId,
					implode(', ', $this->providers->ids())
				),
				'unknown_provider'
			);

			return;
		}

		$this->account->runOrRefuse(
			what: 'send',
			operation: function () use ($event, $providerId, $config): void {
				$this->sendAsAccount(event: $event, providerId: $providerId, config: $config);
			},
			refuse: static function (string $reason) use ($event): void {
				$event->setHandled(true);
				$event->setRefusal($reason . ' The letter cannot be stored, so nothing was sent.', self::CODE_NO_SERVICE_ACCOUNT);
			}
		);

	}//end handleSendRequest()

	/**
	 * Ask the opt-outs, store, send and record, as the digital post account.
	 *
	 * @param DigitalPostSendRequestedEvent $event      The request.
	 * @param string                        $providerId The provider the source names.
	 * @param array<string,mixed>           $config     The source configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
	 */
	private function sendAsAccount(DigitalPostSendRequestedEvent $event, string $providerId, array $config): void {
		$decision = $this->askOptOuts(event: $event);
		if ($decision === null) {
			return;
		}

		$composed = $this->gate->compose(
			body: $event->getBody(),
			decision: $decision,
			channel: 'digital-post',
			caseRef: $event->getCaseRef()
		);

		$message = [
			'recipient' => $event->getRecipient(),
			'subject' => $event->getSubject(),
			'body' => $composed['body'],
			'attachments' => $event->getAttachments(),
			'requestedBy' => $event->getRequestedBy(),
			'sourceApp' => $event->getSourceApp(),
			'sourceId' => $event->getSourceId(),
			'providerId' => $providerId,
			'correlationId' => $event->getCorrelationId(),
			'status' => DigitalPostResult::STATUS_QUEUED,
			'lastError' => '',
			'simulated' => false,
			'created' => gmdate('c'),
		];

		$messageId = $this->persist(message: $message, uuid: null);
		$event->setHandled(true);

		if ($messageId === null) {
			$event->setRefusal('The letter could not be stored, so it was not sent.', 'not_stored');

			return;
		}

		$logRow = $this->gate->open(
			channel: 'digital-post',
			subjectRef: $event->getCaseRef(),
			subject: $event->getSubject(),
			body: $composed['body'],
			address: (string)$decision['address'],
			options: ['sourceApp' => $event->getSourceApp(), 'correlationId' => $event->getCorrelationId(), 'caseRef' => $event->getCaseRef()],
			decision: $decision
		);
		$result = $this->sendThroughProvider(providerId: $providerId, message: $message, config: $config);
		$this->recordOutcome(logRow: $logRow, address: (string)$decision['address'], result: $result);

		// The attachments stay on the message whatever happened, which is what
		// "a failed send keeps the letter" means: the PDF is still there to
		// retry with.
		$message = array_merge($message, $result->toArray());
		$this->persist(message: $message, uuid: $messageId);

		$this->announce(messageId: $messageId, previousStatus: DigitalPostResult::STATUS_QUEUED, result: $result, requestedBy: $event->getRequestedBy());

		if ($result->isRefused() === true) {
			$event->setRefusal($result->getError(), 'provider_refused');

			return;
		}

		$event->setMessageId($messageId);
	}//end sendAsAccount()

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
	 * @param string|null $logRow The row.
	 * @param string $address The recipient key.
	 * @param DigitalPostResult $result What the provider answered.
	 *
	 * @return void
	 */
	private function recordOutcome(?string $logRow, string $address, DigitalPostResult $result): void {
		if ($result->isRefused() === true) {
			$this->gate->failed(uuid: $logRow, address: $address, step: OutboundSendGate::STEP_SEND, reason: $result->getError());
			return;
		}

		$this->gate->handedOver(uuid: $logRow, address: $address, reference: $result->getProviderReference());
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

			$this->persist(message: array_merge($message, $result->toArray()), uuid: $messageId);
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
	 * @param string $providerId The provider id.
	 * @param array<string,mixed> $message The message.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult What the provider answered.
	 */
	private function sendThroughProvider(string $providerId, array $message, array $config): DigitalPostResult {
		try {
			return $this->providers->get($providerId)->send($message, $config);
		} catch (Throwable $e) {
			$this->logger->warning('digital-post.send.failed', ['provider' => $providerId, 'error' => $e->getMessage()]);

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

		$data = $source->getObject();
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
