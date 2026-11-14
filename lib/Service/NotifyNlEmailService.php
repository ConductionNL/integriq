<?php

/**
 * Integriq NotifyNL e-mail service.
 *
 * Sends one e-mail through a NotifyNL source and keeps it in the outbound
 * message log on channel `notifynl`: the address is validated first, the
 * opt-out list is asked as for any e-mail, the log row is opened, and its uuid
 * travels as NotifyNL's `reference`, so NotifyNL's delivery callback, which
 * hands the reference back, finds the row and the recipient on it.
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
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Outbound\OutboundSendGateInterface;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends NotifyNL e-mail and applies NotifyNL's mail delivery callbacks.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
 */
class NotifyNlEmailService {

	/**
	 * The outbound log channel a NotifyNL e-mail is recorded on.
	 *
	 * @var string
	 */
	public const LOG_CHANNEL = 'notifynl';

	/**
	 * The opt-out channel a NotifyNL e-mail is asked under: it is e-mail to the person.
	 *
	 * @var string
	 */
	public const OPT_OUT_CHANNEL = 'mail';

	/**
	 * Error code of an address that is not an e-mail address.
	 *
	 * @var string
	 */
	public const ERROR_INVALID_ADDRESS = 'invalid-address';

	/**
	 * Error code of a send without a template.
	 *
	 * @var string
	 */
	public const ERROR_MISSING_TEMPLATE = 'missing-template';

	/**
	 * The step a delivery failure NotifyNL reports is recorded in.
	 *
	 * @var string
	 */
	private const STEP_DELIVERY = 'delivery';

	/**
	 * Most SMS sources looked through for the NotifyNL one.
	 *
	 * @var int
	 */
	private const SOURCE_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService           $objectService     Finds the NotifyNL source.
	 * @param RawSourceResolver         $rawSourceResolver Re-reads the source raw, its key intact.
	 * @param RestNotifyNlProvider      $notifyNl          Sends the e-mail.
	 * @param OutboundSendGateInterface $gate              Asks the opt-out list and keeps the log row.
	 * @param MessageRecorder           $recorder          Reads and updates the log row on a callback.
	 * @param IL10N                     $l                 Words the refusals.
	 * @param LoggerInterface           $logger            Logs a callback that names no row.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly RawSourceResolver $rawSourceResolver,
		private readonly RestNotifyNlProvider $notifyNl,
		private readonly OutboundSendGateInterface $gate,
		private readonly MessageRecorder $recorder,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Send one e-mail through NotifyNL and record it.
	 *
	 * @param string              $emailAddress    The recipient address.
	 * @param string              $templateId      The NotifyNL template id, or a name the source maps.
	 * @param array<string,mixed> $personalisation The template's personalisation values.
	 * @param array<string,mixed> $options         `category` (default `service`), `caseRef`, `sourceApp`,
	 *                                             `objectUri`, `sourceSlug`.
	 *
	 * @return array{id: string|null, reference: string, status: string} The log row, NotifyNL's id and `sent`.
	 *
	 * @throws SmsProviderException With code `invalid-address` or `missing-template` before anything is
	 *                              sent or recorded, `unknown-source` when no NotifyNL source answers,
	 *                              the opt-out decision code when the list refuses, or NotifyNL's failure.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
	 */
	public function sendEmail(string $emailAddress, string $templateId, array $personalisation, array $options = []): array {
		$address = trim($emailAddress);
		if (self::isEmailAddress(address: $address) === false) {
			throw new SmsProviderException(
				message: $this->l->t('"%s" is not an e-mail address.', [$address]),
				errorCode: self::ERROR_INVALID_ADDRESS
			);
		}

		if (trim($templateId) === '') {
			throw new SmsProviderException(message: $this->l->t('A NotifyNL e-mail needs a template.'), errorCode: self::ERROR_MISSING_TEMPLATE);
		}

		$configuration = $this->sourceConfiguration(sourceSlug: self::optionalString(options: $options, key: 'sourceSlug'));

		$objectUri = (string)(self::optionalString(options: $options, key: 'objectUri') ?? '');
		$caseRef = (string)(self::optionalString(options: $options, key: 'caseRef') ?? '');
		$gateOptions = [
			'caseRef' => $caseRef,
			'sourceApp' => (self::optionalString(options: $options, key: 'sourceApp') ?? 'integriq'),
			'correlationId' => $objectUri,
		];
		$decision = $this->gate->check(
			channel: self::OPT_OUT_CHANNEL,
			category: (self::optionalString(options: $options, key: 'category') ?? 'service'),
			address: $address,
			options: $gateOptions
		);
		if ($decision['send'] !== true) {
			$this->gate->recordRefusal(channel: self::LOG_CHANNEL, subjectRef: $objectUri, decision: $decision, options: $gateOptions);
			throw new SmsProviderException(message: (string)$decision['reason'], errorCode: (string)$decision['code']);
		}

		$composed = $this->gate->compose(body: '', decision: $decision, channel: self::OPT_OUT_CHANNEL, caseRef: $caseRef);
		if ((string)($composed['unsubscribeLink'] ?? '') !== '') {
			// A NotifyNL template renders this as ((unsubscribe)).
			$personalisation['unsubscribe'] = (string)$composed['unsubscribeLink'];
		}

		$row = $this->gate->open(
			channel: self::LOG_CHANNEL,
			subjectRef: $objectUri,
			subject: $templateId,
			body: '',
			address: $address,
			options: $gateOptions,
			decision: $decision
		);

		try {
			$result = $this->notifyNl->sendEmail(
				sourceConfiguration: $configuration,
				emailAddress: $address,
				templateId: $templateId,
				personalisation: $personalisation,
				reference: (string)$row
			);
		} catch (SmsProviderException $exception) {
			$this->gate->failed(uuid: $row, address: $address, step: OutboundSendGateInterface::STEP_SEND, reason: $exception->getMessage());
			throw $exception;
		}

		$this->gate->handedOver(uuid: $row, address: $address, reference: $result->providerMessageId);

		return ['id' => $row, 'reference' => $result->providerMessageId, 'status' => 'sent'];
	}//end sendEmail()

	/**
	 * Apply a NotifyNL delivery callback to the e-mail it reports on.
	 *
	 * The callback's `reference` names the log row; the row must be a NotifyNL
	 * row with a recipient NotifyNL's id was handed over for, so a callback can
	 * only ever move the recipient it is about.
	 *
	 * @param string      $reference         The callback's `reference`: the log row uuid.
	 * @param string      $providerMessageId NotifyNL's notification id.
	 * @param string      $status            The normalised status.
	 * @param string|null $detail            What NotifyNL said.
	 *
	 * @return bool True when a recipient on a log row was updated.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
	 */
	public function applyCallback(string $reference, string $providerMessageId, string $status, ?string $detail): bool {
		if ($reference === '' || $providerMessageId === '' || in_array($status, ['delivered', 'failed'], true) === false) {
			return false;
		}

		try {
			$record = $this->recorder->read(uuid: $reference);
		} catch (Throwable) {
			$this->logger->warning('[NotifyNlEmailService] a NotifyNL callback names no outbound message; recorded, no state change');
			return false;
		}

		$address = self::recipientFor(record: $record, providerMessageId: $providerMessageId);
		if ($address === '') {
			$this->logger->warning('[NotifyNlEmailService] a NotifyNL callback does not match its outbound message; no state change');
			return false;
		}

		if ($status === 'delivered') {
			$this->recorder->deliveryReported(uuid: $reference, address: $address, detail: (string)$detail);
			return true;
		}

		$this->recorder->recipientFailed(uuid: $reference, address: $address, step: self::STEP_DELIVERY, reason: (string)$detail);
		return true;
	}//end applyCallback()

	/**
	 * The recipient on a NotifyNL log row that NotifyNL's id was handed over for.
	 *
	 * @param array<string,mixed> $record            The log row.
	 * @param string              $providerMessageId NotifyNL's notification id.
	 *
	 * @return string The address, or an empty string when the row is not NotifyNL's or no recipient matches.
	 */
	private static function recipientFor(array $record, string $providerMessageId): string {
		if (($record['channel'] ?? null) !== self::LOG_CHANNEL) {
			return '';
		}

		foreach ((array)$record['recipients'] as $recipient) {
			if (is_array($recipient) === true && (string)($recipient['reference'] ?? '') === $providerMessageId) {
				return (string)($recipient['address'] ?? '');
			}
		}

		return '';

	}//end recipientFor()

	/**
	 * Whether a string is an e-mail address with a domain.
	 *
	 * @param string $address The address.
	 *
	 * @return bool
	 */
	private static function isEmailAddress(string $address): bool {
		if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
			return false;
		}

		$domain = substr($address, ((int)strrpos($address, '@') + 1));
		return str_contains($domain, '.') === true;

	}//end isEmailAddress()

	/**
	 * The configuration of the NotifyNL source that sends: the named one, else the first enabled one.
	 *
	 * @param string|null $sourceSlug The source the caller named, or null.
	 *
	 * @return array<string,mixed> The source's configuration, raw.
	 *
	 * @throws SmsProviderException With code `unknown-source` when no enabled NotifyNL source answers.
	 */
	private function sourceConfiguration(?string $sourceSlug): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => RawSourceResolver::REGISTER,
					'schema' => RawSourceResolver::SCHEMA,
					'type' => SmsDispatchService::SOURCE_TYPE,
					'isEnabled' => true,
				],
				'limit' => self::SOURCE_LIMIT,
			]
		);
		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			$results = [];
		}

		foreach ($results as $candidate) {
			if (self::isNotifyNlSource(source: $candidate, sourceSlug: $sourceSlug) === true) {
				$configuration = ($this->rawSourceResolver->resolveRaw(source: $candidate)->getObject()['configuration'] ?? []);
				return (array)$configuration;
			}
		}

		throw new SmsProviderException(
			message: $this->l->t('No enabled NotifyNL source answers to "%s".', [(string)$sourceSlug]),
			errorCode: SmsDispatchService::ERROR_UNKNOWN_SOURCE
		);

	}//end sourceConfiguration()

	/**
	 * Whether a located source is an enabled NotifyNL source, and the named one when a slug is given.
	 *
	 * @param mixed       $source     The candidate.
	 * @param string|null $sourceSlug The named slug, or null.
	 *
	 * @return bool
	 */
	private static function isNotifyNlSource(mixed $source, ?string $sourceSlug): bool {
		if (($source instanceof ObjectEntity) === false) {
			return false;
		}

		$data = $source->getObject();
		if (($data['type'] ?? null) !== SmsDispatchService::SOURCE_TYPE || ($data['isEnabled'] ?? false) !== true) {
			return false;
		}

		if (($data['configuration']['provider'] ?? null) !== 'notifynl') {
			return false;
		}

		return $sourceSlug === null || ($data['slug'] ?? null) === $sourceSlug;

	}//end isNotifyNlSource()

	/**
	 * A non-blank string option, trimmed, or null.
	 *
	 * @param array<string,mixed> $options The options.
	 * @param string              $key     The option.
	 *
	 * @return string|null
	 */
	private static function optionalString(array $options, string $key): ?string {
		$value = ($options[$key] ?? null);
		if (is_scalar($value) === false || trim((string)$value) === '') {
			return null;
		}

		return trim((string)$value);

	}//end optionalString()
}//end class
