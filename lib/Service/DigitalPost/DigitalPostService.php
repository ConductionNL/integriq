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
	 * Constructor.
	 *
	 * @param DigitalPostProviderRegistry $providers The bindings.
	 * @param ConnectionStore $connectionStore Source lookup by slug.
	 * @param OrObjectService $objectService OpenRegister's object-service facade.
	 * @param IEventDispatcher $eventDispatcher The Nextcloud event dispatcher.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly DigitalPostProviderRegistry $providers,
		private readonly ConnectionStore $connectionStore,
		private readonly OrObjectService $objectService,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a send request from another app.
	 *
	 * @param DigitalPostSendRequestedEvent $event The request.
	 *
	 * @return void
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

		$message = [
			'recipient' => $event->getRecipient(),
			'subject' => $event->getSubject(),
			'body' => $event->getBody(),
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

		$result = $this->sendThroughProvider(providerId: $providerId, message: $message, config: $config);

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
	}//end handleSendRequest()

	/**
	 * Ask every provider what became of the letters it took.
	 *
	 * @param array<int,array<string,mixed>> $messages The tracked messages to poll, keyed by `uuid`.
	 *
	 * @return int How many messages changed status.
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

		if (is_array($config) === false) {
			return [];
		}

		return $config;
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
