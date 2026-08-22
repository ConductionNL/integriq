<?php

/**
 * Integriq SMS Dispatch Service.
 *
 * Core of the notifynl-sms-channel: resolves the configured SMS source +
 * provider binding, validates the recipient to E.164, drives an outbound send
 * through the resolved {@see \OCA\Integriq\Service\Sms\SmsProviderInterface}
 * binding, and persists the `sms_message` lifecycle record
 * (queued -> sent -> delivered|failed) — updated either by polling
 * ({@see pollStatus()}) or by a verified inbound provider callback
 * ({@see handleStatusCallback()}). Mirrors PeppolTransmissionService /
 * BankfeedSyncService: NotifyNlController stays a thin HTTP/auth shell.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Sms\DeliveryResult;
use OCA\Integriq\Service\Sms\LogSmsProvider;
use OCA\Integriq\Service\Sms\PhoneNumberValidator;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCA\Integriq\Service\Sms\SmsProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives SMS send, delivery-status polling, and inbound callback handling.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)           -- PhoneNumberValidator is a deliberately pure,
 * dependency-free static helper (REQ-005); injecting it as a service would add DI ceremony
 * around a function with no state or side effects.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */
class SmsDispatchService {

	/**
	 * OpenRegister register slug holding SMS sources and messages.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for an SMS source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for an `sms_message` record.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'sms_message';

	/**
	 * `source.type` value identifying an SMS channel source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'sms';

	/**
	 * CloudEvent type emitted on every message status change.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_DELIVERY_STATUS = 'nl.conduction.sms.delivery.status';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/message persistence.
	 * @param LogSmsProvider $logProvider The sandbox provider binding.
	 * @param RestNotifyNlProvider $notifyNlProvider The NotifyNL REST provider binding.
	 * @param EventService $eventService Emits delivery-status CloudEvents.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LogSmsProvider $logProvider,
		private readonly RestNotifyNlProvider $notifyNlProvider,
		private readonly EventService $eventService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly RawSourceResolver $rawSourceResolver,
	) {

	}//end __construct()

	/**
	 * Validate and send one SMS message: validates the recipient to E.164, dispatches
	 * through the configured provider, and persists the resulting `sms_message` record.
	 *
	 * @param string $to The raw recipient phone number (normalised to E.164 before dispatch).
	 * @param string $body Free-text body (audit context — template providers ignore it for the wire
	 *                     call).
	 * @param array $options Provider-specific send options (e.g. `templateId`, `personalisation`).
	 * @param string|null $sourceApp Slug of the producing app (e.g. `procest`), stored for audit.
	 * @param string|null $objectUri Optional reference to the producing app's own object.
	 *
	 * @return ObjectEntity The created `sms_message` record.
	 *
	 * @throws SmsProviderException When the recipient is not a valid phone number, no active SMS source is
	 *                              configured, or the provider rejects/cannot reach the request.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function sendMessage(
		string $to,
		string $body,
		array $options = [],
		?string $sourceApp = null,
		?string $objectUri = null,
	): ObjectEntity {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);

		$callingCode = (string)($configuration['defaultCallingCode'] ?? PhoneNumberValidator::DEFAULT_CALLING_CODE);
		$e164 = PhoneNumberValidator::toE164(rawNumber: $to, callingCode: $callingCode);
		if ($e164 === null) {
			throw new SmsProviderException(
				message: $this->l->t('Invalid phone number') . ': "' . $to . '" could not be normalised to E.164.'
			);
		}

		$provider = $this->resolveProvider(configuration: $configuration);

		$message = $this->objectService->saveObject(
			object: [
				'sourceApp' => ($sourceApp ?? ''),
				'objectUri' => ($objectUri ?? ''),
				'recipientMsisdn' => $e164,
				'templateId' => (string)($options['templateId'] ?? ''),
				'provider' => $provider->getProviderId(),
				'providerMessageId' => null,
				'status' => 'queued',
				'detail' => $this->l->t('Message queued'),
				'attempts' => [],
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		return $this->attemptSend(message: $message, provider: $provider, to: $e164, body: $body, options: $options);
	}//end sendMessage()

	/**
	 * Attempt one provider send for a `queued` `sms_message` and persist the outcome.
	 *
	 * @param ObjectEntity $message The message to send.
	 * @param SmsProviderInterface $provider The resolved provider binding.
	 * @param string $to The E.164-normalised recipient.
	 * @param string $body Free-text body (audit context).
	 * @param array $options Provider-specific send options.
	 *
	 * @return ObjectEntity The updated message.
	 */
	private function attemptSend(
		ObjectEntity $message,
		SmsProviderInterface $provider,
		string $to,
		string $body,
		array $options,
	): ObjectEntity {
		$data = $message->getObject();
		$attempts = (array)($data['attempts'] ?? []);

		try {
			$source = $this->resolveActiveSource();
			$configuration = ($source->getObject()['configuration'] ?? []);

			$result = $provider->send(sourceConfiguration: $configuration, to: $to, body: $body, options: $options);

			$attempts[] = ['at' => (new DateTime())->format('c'), 'error' => null];
			$data['attempts'] = $attempts;
			$data['providerMessageId'] = $result->providerMessageId;
			$data['status'] = $result->status;
			$data['detail'] = $result->detail;

			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_MESSAGE,
				uuid: $message->getUuid()
			);

			$this->emitDeliveryStatus(message: $saved);
			return $saved;
		} catch (Throwable $exception) {
			$attempts[] = ['at' => (new DateTime())->format('c'), 'error' => $exception->getMessage()];
			$data['attempts'] = $attempts;
			$data['status'] = 'failed';
			$data['detail'] = $exception->getMessage();

			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_MESSAGE,
				uuid: $message->getUuid()
			);

			$this->logger->warning(
				'[SmsDispatchService] send failed',
				['objectUri' => ($data['objectUri'] ?? null), 'exception' => $exception->getMessage()]
			);

			$this->emitDeliveryStatus(message: $saved);
			return $saved;
		}//end try

	}//end attemptSend()

	/**
	 * Poll the provider for a message's current delivery status and persist any change.
	 *
	 * @param string $uuid The `sms_message` uuid.
	 *
	 * @return ObjectEntity The (possibly updated) message.
	 *
	 * @throws SmsProviderException When the message is unknown or has no `providerMessageId` yet.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function pollStatus(string $uuid): ObjectEntity {
		$message = $this->objectService->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA_MESSAGE);
		if ($message instanceof ObjectEntity === false) {
			throw new SmsProviderException(message: 'No sms_message found for uuid "' . $uuid . '".');
		}

		$data = $message->getObject();
		$providerMessageId = (string)($data['providerMessageId'] ?? '');
		if ($providerMessageId === '') {
			throw new SmsProviderException(
				message: 'sms_message "' . $uuid . '" has no providerMessageId yet — the send has not completed.'
			);
		}

		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$result = $provider->fetchStatus(sourceConfiguration: $configuration, providerMessageId: $providerMessageId);

		if ($result->status === ($data['status'] ?? null)) {
			return $message;
		}

		return $this->applyStatus(message: $message, result: $result);
	}//end pollStatus()

	/**
	 * Apply a verified provider delivery callback to its `sms_message` record.
	 *
	 * A callback for an unknown `providerMessageId` is recorded (logged) and MUST NOT
	 * throw — it returns null rather than surfacing an error to the (already-verified) caller.
	 *
	 * @param string $providerMessageId The provider-assigned message id from the callback.
	 * @param string $status The reported normalised status ({@see DeliveryResult::STATUSES}).
	 * @param string|null $detail An optional reason/detail message.
	 *
	 * @return ObjectEntity|null The updated message, or null when the providerMessageId is unknown.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function handleStatusCallback(string $providerMessageId, string $status, ?string $detail): ?ObjectEntity {
		if (in_array($status, DeliveryResult::STATUSES, true) === false) {
			$this->logger->warning(
				'[SmsDispatchService] inbound status callback carried an unsupported status; ignored',
				['providerMessageId' => $providerMessageId, 'status' => $status]
			);
			return null;
		}

		$message = $this->findMessageByProviderMessageId(providerMessageId: $providerMessageId);
		if ($message === null) {
			$this->logger->warning(
				'[SmsDispatchService] inbound status callback for unknown providerMessageId; recorded, no state change',
				['providerMessageId' => $providerMessageId, 'status' => $status]
			);
			return null;
		}

		$result = new DeliveryResult(providerMessageId: $providerMessageId, status: $status, detail: $detail);

		return $this->applyStatus(message: $message, result: $result);
	}//end handleStatusCallback()

	/**
	 * Persist a resolved {@see DeliveryResult} onto a message and emit the delivery-status CloudEvent.
	 *
	 * @param ObjectEntity $message The message to update.
	 * @param DeliveryResult $result The resolved status.
	 *
	 * @return ObjectEntity The updated message.
	 */
	private function applyStatus(ObjectEntity $message, DeliveryResult $result): ObjectEntity {
		$data = $message->getObject();
		$data['status'] = $result->status;
		$data['detail'] = $result->detail;

		$saved = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE,
			uuid: $message->getUuid()
		);

		$this->emitDeliveryStatus(message: $saved);
		return $saved;
	}//end applyStatus()

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The SMS source's `configuration` object.
	 *
	 * @return SmsProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function resolveProvider(array $configuration): SmsProviderInterface {
		$provider = ($configuration['provider'] ?? $this->logProvider->getProviderId());
		if ($provider === $this->notifyNlProvider->getProviderId()) {
			return $this->notifyNlProvider;
		}

		return $this->logProvider;
	}//end resolveProvider()

	/**
	 * Resolve the single active SMS source (`type=sms`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws SmsProviderException When no active SMS source is configured.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-send-endpoint-consumable-by-sibling-apps-req-006
	 */
	public function resolveActiveSource(): ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_SOURCE,
					'type' => self::SOURCE_TYPE,
					'isEnabled' => true,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			throw new SmsProviderException(
				message: 'No active SMS source is configured (register "openconnector", schema "source", '
					. 'type "sms", isEnabled=true). Configure one before using the SMS channel.'
			);
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()

	/**
	 * Find an `sms_message` by its provider-assigned message id.
	 *
	 * @param string $providerMessageId The provider message id.
	 *
	 * @return ObjectEntity|null The matching message, or null when none exists.
	 */
	private function findMessageByProviderMessageId(string $providerMessageId): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MESSAGE,
					'providerMessageId' => $providerMessageId,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findMessageByProviderMessageId()

	/**
	 * Emit `nl.conduction.sms.delivery.status` for a message's current state.
	 *
	 * @param ObjectEntity $message The message whose state just changed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	private function emitDeliveryStatus(ObjectEntity $message): void {
		$data = $message->getObject();

		$this->eventService->emitCloudEvent(
			type: self::EVENT_TYPE_DELIVERY_STATUS,
			source: '/sms/messages/' . $message->getUuid(),
			subject: $message->getUuid(),
			data: [
				'objectUri' => ($data['objectUri'] ?? null),
				'providerMessageId' => ($data['providerMessageId'] ?? null),
				'status' => ($data['status'] ?? null),
				'timestamp' => (new DateTime())->format('c'),
				'detail' => ($data['detail'] ?? null),
			]
		);

	}//end emitDeliveryStatus()
}//end class
