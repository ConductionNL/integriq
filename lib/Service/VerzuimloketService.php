<?php

/**
 * Integriq DUO Verzuimloket Service.
 *
 * Core of integriq-adapter-verzuimloket: resolves the configured
 * Verzuimloket (`type=verzuimloket`) source + provider binding, drives the
 * outbound PUSH (translate a meldingType payload from learniq's
 * `leerplicht` job, dispatch via the configured transport, persist a
 * `verzuim_message` audit record), and the inbound RETOUR path (verify +
 * translate a DUO acknowledgement, persist its own audit record, dispatch
 * `VerzuimloketAcknowledgementReceivedEvent`). Mirrors `RodService`.
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
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use OCA\Integriq\Event\VerzuimloketAcknowledgementReceivedEvent;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketAcknowledgementTranslator;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEnvelopeTranslator;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives the Verzuimloket outbound send and inbound retour paths.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md
 */
class VerzuimloketService {

	/**
	 * OpenRegister register slug holding Verzuimloket sources and message records.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * OR schema slug for a Verzuimloket source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a `verzuim_message` record.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'verzuim_message';

	/**
	 * `source.type` value identifying a Verzuimloket source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'verzuimloket';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/message persistence.
	 * @param VerzuimloketProviderRegistry $providers The registered Verzuimloket provider bindings.
	 * @param VerzuimloketEnvelopeTranslator $envelopeTranslator Translates a meldingType payload into an envelope.
	 * @param VerzuimloketAcknowledgementTranslator $ackTranslator Translates a retour into a status update.
	 * @param IEventDispatcher $eventDispatcher The Nextcloud event dispatcher.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly VerzuimloketProviderRegistry $providers,
		private readonly VerzuimloketEnvelopeTranslator $envelopeTranslator,
		private readonly VerzuimloketAcknowledgementTranslator $ackTranslator,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly RawSourceResolver $rawSourceResolver,
	) {

	}//end __construct()

	/**
	 * Translate and dispatch one outbound Verzuimloket melding.
	 *
	 * @param string $meldingType One of `eerste-melding`|`herhaalmelding`|`langdurig-relatief-verzuim`.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — see design.md's field table.
	 *
	 * @return array{ref: string, meldingType: string, status: string} The provider ref, echoed
	 *                                                                 meldingType, and outcome status.
	 *
	 * @throws VerzuimloketTranslationException When a required field is missing/empty.
	 * @throws VerzuimloketProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function sendMelding(string $meldingType, string $kenmerk, array $payload): array {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->providers->get(providerId: (string)($configuration['provider'] ?? ''));

		// Translation failures never reach the transport and never get an
		// audit record — no envelope exists yet to key one on (REQ-002).
		$envelopeXml = $this->envelopeTranslator->translate(meldingType: $meldingType, kenmerk: $kenmerk, payload: $payload);

		$status = 'sent';
		$error = null;
		$ref = $kenmerk;
		try {
			$ref = $provider->send(
				sourceConfiguration: $configuration,
				meldingType: $meldingType,
				kenmerk: $kenmerk,
				envelopeXml: $envelopeXml
			);
		} catch (VerzuimloketProviderException $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$record = [
			'direction' => 'outbound',
			'meldingType' => $meldingType,
			'status' => $status,
			'ref' => $ref,
			'kenmerk' => $kenmerk,
			'signaalcode' => null,
			'signaalOmschrijving' => null,
			'error' => $error,
			'syncedAt' => (new DateTime())->format('c'),
		];

		if (isset($payload['bsn']) === true) {
			$record['bsnHash'] = hash('sha256', (string)$payload['bsn']);
		}

		$this->objectService->saveObject(object: $record, register: self::REGISTER, schema: self::SCHEMA_MESSAGE);

		if ($status === 'failed') {
			throw new VerzuimloketProviderException(message: (string)$error);
		}

		return ['ref' => $ref, 'meldingType' => $meldingType, 'status' => $status];
	}//end sendMelding()

	/**
	 * Receive, verify-translate, and process one DUO acknowledgement/retour.
	 *
	 * Signature verification happens in the controller. This method NEVER
	 * throws out to the controller: any failure is logged, a
	 * `verzuim_message` record is persisted when enough context exists, and
	 * the method returns.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	public function receiveReturn(string $rawXml): void {
		try {
			$update = $this->ackTranslator->translate(xml: $rawXml);
		} catch (Throwable $exception) {
			$this->logger->warning(
				$this->l->t('Verzuimloket retour could not be translated; dropped'),
				['exception' => $exception->getMessage()]
			);
			return;
		}

		$outbound = $this->findByKenmerk(kenmerk: $update['kenmerk']);
		$meldingType = '';
		$error = null;
		if ($outbound !== null) {
			$meldingType = (string)($outbound->getObject()['meldingType'] ?? '');
		}

		if ($outbound === null) {
			$error = 'No matching outbound message found for kenmerk';
		}

		$status = 'rejected';
		if ($update['accepted'] === true) {
			$status = 'acknowledged';
		}

		$this->objectService->saveObject(
			object: [
				'direction' => 'inbound',
				'meldingType' => $meldingType,
				'status' => $status,
				'ref' => null,
				'kenmerk' => $update['kenmerk'],
				'signaalcode' => $update['signaalcode'],
				'signaalOmschrijving' => $update['signaalOmschrijving'],
				'error' => $error,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		if ($outbound === null) {
			$this->logger->warning(
				$this->l->t('Verzuimloket retour kenmerk did not resolve to a known outbound message'),
				['kenmerk' => $update['kenmerk']]
			);
		}

		$this->eventDispatcher->dispatchTyped(
			new VerzuimloketAcknowledgementReceivedEvent(
				kenmerk: $update['kenmerk'],
				signaalcode: $update['signaalcode'],
				signaalOmschrijving: $update['signaalOmschrijving'],
				accepted: $update['accepted'],
				meldingType: $meldingType,
			)
		);

	}//end receiveReturn()

	/**
	 * Re-attempt every `verzuim_message` row with `status: failed` or
	 * `pending` through the currently configured transport — driven by
	 * `VerzuimloketRetryJob`. Per-message isolation.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
	 */
	public function retryFailed(): int {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MESSAGE,
					'direction' => 'outbound',
				],
			]
		);
		$results = ($matches['results'] ?? $matches);

		$retried = 0;
		foreach ($results as $message) {
			$data = $message->getObject();
			if (($data['status'] ?? null) !== 'failed' && ($data['status'] ?? null) !== 'pending') {
				continue;
			}

			try {
				$this->retryOne(message: $message, data: $data);
				$retried++;
			} catch (Throwable $exception) {
				$this->logger->warning(
					$this->l->t('Verzuimloket retry failed for one message; skipped, sweep continues'),
					['ref' => ($data['ref'] ?? null), 'exception' => $exception->getMessage()]
				);
			}
		}//end foreach

		return $retried;
	}//end retryFailed()

	/**
	 * Re-dispatch one previously failed message.
	 *
	 * @param ObjectEntity $message The failed `verzuim_message` row.
	 * @param array $data The message's object data.
	 *
	 * @return void
	 *
	 * @throws Throwable When the provider send fails again.
	 */
	private function retryOne(ObjectEntity $message, array $data): void {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->providers->get(providerId: (string)($configuration['provider'] ?? ''));

		$meldingType = (string)($data['meldingType'] ?? '');
		$kenmerk = (string)($data['kenmerk'] ?? '');
		$envelopeXml = '<VerzuimloketRetry kenmerk="' . htmlspecialchars($kenmerk, ENT_XML1 | ENT_QUOTES) . '"/>';

		$provider->send(sourceConfiguration: $configuration, meldingType: $meldingType, kenmerk: $kenmerk, envelopeXml: $envelopeXml);

		$data['status'] = 'sent';
		$data['error'] = null;
		$data['syncedAt'] = (new DateTime())->format('c');

		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE,
			uuid: $message->getUuid()
		);

	}//end retryOne()

	/**
	 * Resolve the single active Verzuimloket source (`type=verzuimloket`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws VerzuimloketProviderException When no active Verzuimloket source is configured.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
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
			throw new VerzuimloketProviderException(
				message: 'No active Verzuimloket source is configured (register "integriq", schema "source", '
					. 'type "verzuimloket", isEnabled=true). Configure one before using the Verzuimloket bridge.'
			);
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()

	/**
	 * Find an existing outbound `verzuim_message` row by its `kenmerk`.
	 *
	 * @param string $kenmerk The kenmerk to look up.
	 *
	 * @return ObjectEntity|null The matching row, or null when none matches.
	 */
	private function findByKenmerk(string $kenmerk): ?ObjectEntity {
		if ($kenmerk === '') {
			return null;
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MESSAGE,
					'direction' => 'outbound',
					'kenmerk' => $kenmerk,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findByKenmerk()
}//end class
