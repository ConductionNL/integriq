<?php

/**
 * Integriq SBR Filing Service.
 *
 * Turns a `nl.conduction.sbr.filing.requested` CloudEvent into one
 * `sbr_filing` record and one delivery to Digipoort, polls the processing
 * status of every filing that has not reached a final status, and reports
 * each status change back as a `nl.conduction.sbr.filing.status` CloudEvent.
 *
 * A filing is keyed on `objectUri` and `filingType`. A repeated request for a
 * filing that was delivered (or has reached any status past `queued`) makes
 * no second delivery. A delivery that fails is tried again by the status job
 * until the retry budget is spent; the filing is then `failed`, which is its
 * dead-letter state, and that is reported like any other status.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Digipoort
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Digipoort;

use DateTime;
use OCA\Integriq\Exception\DigipoortException;
use OCA\Integriq\Service\CloudEventEmitterInterface;
use OCA\Integriq\Service\UserFilePayloadReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers SBR filings through the active Digipoort source and reports their statuses.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md
 */
class SbrFilingService {
	/**
	 * OpenRegister register slug (frozen across the app-id rename).
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * Schema slug of a source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * Schema slug of a filing record.
	 *
	 * @var string
	 */
	public const SCHEMA_FILING = 'sbr_filing';

	/**
	 * `source.type` of a Digipoort source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'digipoort-sbr';

	/**
	 * CloudEvent type consumed to start a filing.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_REQUESTED = 'nl.conduction.sbr.filing.requested';

	/**
	 * CloudEvent type emitted on every status change.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_STATUS = 'nl.conduction.sbr.filing.status';

	/**
	 * Delivery attempts before a filing is dead-lettered as `failed`.
	 *
	 * @var integer
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Statuses after which a filing is neither delivered nor polled again.
	 *
	 * @var list<string>
	 */
	public const FINAL_STATUSES = ['accepted', 'rejected', 'failed'];

	/**
	 * The berichtsoort each filing type is delivered as, unless the source
	 * maps it itself in `configuration.berichtsoorten`.
	 *
	 * @var array<string,string>
	 */
	public const DEFAULT_BERICHTSOORTEN = [
		'ob-aangifte' => 'Omzetbelasting',
		'kvk-deponering' => 'Kvk-jaarverantwoording',
	];

	/**
	 * The recipients a filing may be addressed to.
	 *
	 * @var list<string>
	 */
	public const RECIPIENTS = ['belastingdienst', 'kvk'];

	/**
	 * Request fields without which no filing is stored.
	 *
	 * @var list<string>
	 */
	private const REQUIRED_FIELDS = ['objectUri', 'filingType', 'recipient', 'payloadFileUri', 'identityNumber', 'identityType'];

	/**
	 * How many filings one status sweep looks at.
	 *
	 * @var integer
	 */
	private const SWEEP_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService            $objectService OpenRegister's object service.
	 * @param LogDigipoortProvider       $logProvider   The sandbox binding.
	 * @param WusDigipoortProvider       $wusProvider   The Digikoppeling WUS binding.
	 * @param UserFilePayloadReader      $reader        Reads the XBRL instance `payloadFileUri` names.
	 * @param CloudEventEmitterInterface $events        Emits the status CloudEvents.
	 * @param LoggerInterface            $logger        Logger for failed attempts.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LogDigipoortProvider $logProvider,
		private readonly WusDigipoortProvider $wusProvider,
		private readonly UserFilePayloadReader $reader,
		private readonly CloudEventEmitterInterface $events,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store and deliver the filing a `nl.conduction.sbr.filing.requested` event asks for.
	 *
	 * @param array<string,mixed> $eventData The CloudEvent `data`: `{sourceApp, objectType, objectUri, filingType,
	 *                                       recipient, payloadFileUri, identityNumber, identityType}`.
	 *
	 * @return ObjectEntity|null The filing, or null when the request lacks a required field.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
	 */
	public function handleRequested(array $eventData): ?ObjectEntity {
		foreach (self::REQUIRED_FIELDS as $field) {
			if (trim((string)($eventData[$field] ?? '')) === '') {
				$this->logger->warning(
					'[SbrFilingService] filing request is missing ' . $field . '; ignored',
					['objectUri' => ($eventData['objectUri'] ?? null)]
				);
				return null;
			}
		}

		$objectUri = (string)$eventData['objectUri'];
		$filingType = (string)$eventData['filingType'];

		$existing = $this->findFiling(objectUri: $objectUri, filingType: $filingType);
		if ($existing !== null) {
			if ($this->isDeliverable(data: $existing->getObject()) === false) {
				// Delivered, final or out of retries: a repeated request delivers nothing.
				return $existing;
			}

			return $this->attemptDelivery(filing: $existing);
		}

		$filing = $this->save(
			data: [
				'objectUri' => $objectUri,
				'objectType' => (string)($eventData['objectType'] ?? ''),
				'sourceApp' => (string)($eventData['sourceApp'] ?? ''),
				'filingType' => $filingType,
				'recipient' => (string)$eventData['recipient'],
				'payloadFileUri' => (string)$eventData['payloadFileUri'],
				'identityNumber' => (string)$eventData['identityNumber'],
				'identityType' => (string)$eventData['identityType'],
				'deliveryReference' => '',
				'status' => 'queued',
				'statusHistory' => [],
				'errors' => [],
				'attempts' => [],
				'channelStatusCount' => 0,
			],
			uuid: null
		);

		return $this->attemptDelivery(filing: $filing);
	}//end handleRequested()

	/**
	 * One status sweep: retry the filings whose delivery failed with budget
	 * left, then ask the status service about every delivered filing.
	 *
	 * @return array{retried:int,polled:int,changed:int} What the sweep did.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
	 */
	public function sweep(): array {
		$retried = 0;
		foreach ($this->filingsWithStatus(status: 'queued') as $filing) {
			$data = $filing->getObject();
			if ($this->isDeliverable(data: $data) === false || (array)($data['attempts'] ?? []) === []) {
				// Never attempted: the request that created it is delivering it now.
				continue;
			}

			$this->attemptDelivery(filing: $filing);
			$retried++;
		}

		$polled = 0;
		$changed = 0;
		foreach ($this->filingsWithStatus(status: 'delivered') as $filing) {
			$polled++;
			$changed += $this->pollStatus(filing: $filing);
		}

		return ['retried' => $retried, 'polled' => $polled, 'changed' => $changed];
	}//end sweep()

	/**
	 * Ask the status service about one delivered filing and apply what is new.
	 *
	 * Statuses already seen are skipped by count, so a status the channel
	 * repeats is not reported twice. A status that does not change the
	 * filing's status (a second `delivered`) is not a change and is not
	 * reported. The first final status ends the filing; anything after it is
	 * ignored.
	 *
	 * @param ObjectEntity $filing A filing in status `delivered`.
	 *
	 * @return integer The number of status changes reported.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#scenario-the-belastingdienst-rejects-a-return
	 */
	public function pollStatus(ObjectEntity $filing): int {
		$data = $filing->getObject();
		$reference = (string)($data['deliveryReference'] ?? '');
		if ($reference === '' || ($data['status'] ?? '') !== 'delivered') {
			return 0;
		}

		try {
			$configuration = $this->activeConfiguration();
			$statuses = $this->resolveProvider(configuration: $configuration)->statuses(
				configuration: $configuration,
				deliveryReference: $reference
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[SbrFilingService] status poll failed; tried again on the next sweep',
				['objectUri' => ($data['objectUri'] ?? null), 'exception' => $exception->getMessage()]
			);
			return 0;
		}

		$seen = (int)($data['channelStatusCount'] ?? 0);
		$persisted = $seen;
		$changes = 0;
		foreach (array_slice($statuses, $seen) as $status) {
			$seen++;
			if (($data['status'] ?? '') === $status['status']) {
				continue;
			}

			$data = $this->withStatus(data: $data, status: $status['status'], errors: $status['errors'], entry: $status);
			$data['channelStatusCount'] = $seen;
			$saved = $this->save(data: $data, uuid: $filing->getUuid());
			$persisted = $seen;
			$changes++;
			$this->emitStatus(filing: $saved);
			if (in_array($status['status'], self::FINAL_STATUSES, true) === true) {
				return $changes;
			}
		}

		if ($seen !== $persisted) {
			// Only repeats of the current status: remember them, report nothing.
			$data['channelStatusCount'] = $seen;
			$this->save(data: $data, uuid: $filing->getUuid());
		}

		return $changes;
	}//end pollStatus()

	/**
	 * The sender's own kenmerk for a filing: stable per object and filing type,
	 * so a retry presents the same filing to the recipient.
	 *
	 * @param string $objectUri  The filed object.
	 * @param string $filingType The filing type.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
	 */
	public function aanleverkenmerk(string $objectUri, string $filingType): string {
		return 'SBR-' . strtoupper(substr(hash('sha256', $objectUri . '|' . $filingType), 0, 16));
	}//end aanleverkenmerk()

	/**
	 * The binding `configuration.provider` names: `wus`, or `log` by default.
	 *
	 * @param array<string,mixed> $configuration The source's `configuration`.
	 *
	 * @return DigipoortProviderInterface
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
	 */
	public function resolveProvider(array $configuration): DigipoortProviderInterface {
		if (($configuration['provider'] ?? 'log') === 'wus') {
			return $this->wusProvider;
		}

		return $this->logProvider;
	}//end resolveProvider()

	/**
	 * Try one delivery of a queued filing.
	 *
	 * On success the filing is `delivered` with the delivery reference. On a
	 * failure the attempt is recorded; the filing stays `queued` for the next
	 * sweep, or becomes `failed` when the budget is spent or the failure
	 * cannot be cured by trying again.
	 *
	 * @param ObjectEntity $filing The filing.
	 *
	 * @return ObjectEntity The filing as saved.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
	 */
	private function attemptDelivery(ObjectEntity $filing): ObjectEntity {
		$data = $filing->getObject();
		$attempts = (array)($data['attempts'] ?? []);

		try {
			$configuration = $this->activeConfiguration();
			$delivery = $this->delivery(data: $data, configuration: $configuration);
			$reference = $this->resolveProvider(configuration: $configuration)->deliver(
				configuration: $configuration,
				delivery: $delivery
			);
		} catch (Throwable $exception) {
			$attempts[] = ['at' => $this->now(), 'error' => $exception->getMessage()];
			$data['attempts'] = $attempts;
			$this->logger->warning(
				'[SbrFilingService] delivery failed',
				['objectUri' => ($data['objectUri'] ?? null), 'attempts' => count($attempts), 'exception' => $exception->getMessage()]
			);

			$retryable = ($exception instanceof DigipoortException === false || $exception->retryable === true);
			if ($retryable === true && count($attempts) < self::MAX_ATTEMPTS) {
				return $this->save(data: $data, uuid: $filing->getUuid());
			}

			$data = $this->withStatus(
				data: $data,
				status: 'failed',
				errors: [['code' => '', 'text' => $exception->getMessage()]],
				entry: []
			);
			$saved = $this->save(data: $data, uuid: $filing->getUuid());
			$this->emitStatus(filing: $saved);
			return $saved;
		}//end try

		$attempts[] = ['at' => $this->now(), 'error' => ''];
		$data['attempts'] = $attempts;
		$data['deliveryReference'] = $reference;
		$data = $this->withStatus(data: $data, status: 'delivered', errors: [], entry: []);

		$saved = $this->save(data: $data, uuid: $filing->getUuid());
		$this->emitStatus(filing: $saved);
		return $saved;
	}//end attemptDelivery()

	/**
	 * The delivery for a filing: its berichtsoort, kenmerk, identity and instance.
	 *
	 * @param array<string,mixed> $data          The filing.
	 * @param array<string,mixed> $configuration The source's `configuration` (`berichtsoorten`).
	 *
	 * @return SbrDelivery
	 *
	 * @throws DigipoortException When the filing type or recipient is unknown (not retryable), or the instance cannot be read.
	 */
	private function delivery(array $data, array $configuration): SbrDelivery {
		$filingType = (string)($data['filingType'] ?? '');
		$berichtsoorten = array_merge(self::DEFAULT_BERICHTSOORTEN, (array)($configuration['berichtsoorten'] ?? []));
		$berichtsoort = (string)($berichtsoorten[$filingType] ?? '');
		if ($berichtsoort === '') {
			throw new DigipoortException(
				message: 'The Digipoort source has no berichtsoort for filing type ' . $filingType . '.',
				retryable: false
			);
		}

		if (in_array(($data['recipient'] ?? ''), self::RECIPIENTS, true) === false) {
			throw new DigipoortException(
				message: 'Filings go to the Belastingdienst or the KvK, not to ' . (string)($data['recipient'] ?? '') . '.',
				retryable: false
			);
		}

		try {
			$file = $this->reader->read(reference: (string)($data['payloadFileUri'] ?? ''));
		} catch (Throwable $exception) {
			throw new DigipoortException(message: $exception->getMessage(), previous: $exception);
		}

		$fileName = $file['name'];
		if ($fileName === '') {
			$fileName = $filingType . '.xbrl';
		}

		return new SbrDelivery(
			berichtsoort: $berichtsoort,
			aanleverkenmerk: $this->aanleverkenmerk(objectUri: (string)$data['objectUri'], filingType: $filingType),
			identityNumber: (string)($data['identityNumber'] ?? ''),
			identityType: (string)($data['identityType'] ?? ''),
			instance: $file['content'],
			fileName: $fileName
		);
	}//end delivery()

	/**
	 * The filing with a new status appended to its history.
	 *
	 * @param array<string,mixed>                  $data   The filing.
	 * @param string                               $status The new status.
	 * @param list<array{code:string,text:string}> $errors The recipient's or the channel's errors.
	 * @param array<string,mixed>                  $entry  The channel's status entry (`code`, `description`, `at`), or [].
	 *
	 * @return array<string,mixed>
	 */
	private function withStatus(array $data, string $status, array $errors, array $entry): array {
		$timestamp = (string)($entry['at'] ?? '');
		if ($timestamp === '') {
			$timestamp = $this->now();
		}

		$history = (array)($data['statusHistory'] ?? []);
		$history[] = [
			'status' => $status,
			'code' => (string)($entry['code'] ?? ''),
			'description' => (string)($entry['description'] ?? ''),
			'at' => $timestamp,
			'errors' => $errors,
		];

		$data['statusHistory'] = $history;
		$data['status'] = $status;
		$data['errors'] = $errors;
		return $data;
	}//end withStatus()

	/**
	 * Whether a filing may still be delivered: queued, with budget left.
	 *
	 * @param array<string,mixed> $data The filing.
	 *
	 * @return boolean
	 */
	private function isDeliverable(array $data): bool {
		return ($data['status'] ?? '') === 'queued'
			&& count((array)($data['attempts'] ?? [])) < self::MAX_ATTEMPTS;
	}//end isDeliverable()

	/**
	 * Emit `nl.conduction.sbr.filing.status` for a filing's current status.
	 *
	 * @param ObjectEntity $filing The filing whose status just changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
	 */
	private function emitStatus(ObjectEntity $filing): void {
		$data = $filing->getObject();
		$this->events->emitCloudEvent(
			type: self::EVENT_TYPE_STATUS,
			source: '/digipoort/filings/' . $filing->getUuid(),
			subject: (string)($data['objectUri'] ?? ''),
			data: [
				'objectUri' => ($data['objectUri'] ?? null),
				'deliveryReference' => ($data['deliveryReference'] ?? null),
				'status' => ($data['status'] ?? null),
				'errors' => ($data['errors'] ?? []),
			]
		);
	}//end emitStatus()

	/**
	 * The configuration of the active Digipoort source.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws DigipoortException When no enabled `digipoort-sbr` source exists.
	 */
	private function activeConfiguration(): array {
		$results = $this->find(
			filters: ['schema' => self::SCHEMA_SOURCE, 'type' => self::SOURCE_TYPE, 'isEnabled' => true],
			limit: 1
		);
		if ($results === []) {
			throw new DigipoortException(
				message: 'No active Digipoort source is configured (schema "source", type "digipoort-sbr", isEnabled=true).'
			);
		}

		return (array)($results[0]->getObject()['configuration'] ?? []);
	}//end activeConfiguration()

	/**
	 * The filing for an object and filing type, or null.
	 *
	 * @param string $objectUri  The filed object.
	 * @param string $filingType The filing type.
	 *
	 * @return ObjectEntity|null
	 */
	private function findFiling(string $objectUri, string $filingType): ?ObjectEntity {
		$results = $this->find(
			filters: ['schema' => self::SCHEMA_FILING, 'objectUri' => $objectUri, 'filingType' => $filingType],
			limit: 1
		);

		return ($results[0] ?? null);
	}//end findFiling()

	/**
	 * The filings in one status.
	 *
	 * @param string $status The status.
	 *
	 * @return list<ObjectEntity>
	 */
	private function filingsWithStatus(string $status): array {
		return $this->find(filters: ['schema' => self::SCHEMA_FILING, 'status' => $status], limit: self::SWEEP_LIMIT);
	}//end filingsWithStatus()

	/**
	 * Find objects in integriq's register, in system context.
	 *
	 * Sources and filings are administrator-only, and this runs from a
	 * listener and a background job without an administrator's session.
	 *
	 * @param array<string,mixed> $filters The filters besides the register.
	 * @param integer             $limit   The page size.
	 *
	 * @return list<ObjectEntity>
	 */
	private function find(array $filters, int $limit): array {
		$matches = $this->objectService->findAll(
			config: ['filters' => (['register' => self::REGISTER] + $filters), 'limit' => $limit],
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);

		return array_values(
			array_filter(
				(array)$results,
				static fn (mixed $result): bool => $result instanceof ObjectEntity
			)
		);
	}//end find()

	/**
	 * Save a filing, in system context.
	 *
	 * @param array<string,mixed> $data The filing.
	 * @param string|null         $uuid The filing's uuid, or null for a new one.
	 *
	 * @return ObjectEntity
	 */
	private function save(array $data, ?string $uuid): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_FILING,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * The current time, ISO 8601.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTime())->format('c');
	}//end now()
}//end class
