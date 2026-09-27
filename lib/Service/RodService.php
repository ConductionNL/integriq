<?php

/**
 * Integriq DUO ROD Service.
 *
 * Core of integriq-adapter-rod: resolves the configured ROD (`type=rod`)
 * source + provider binding, drives the outbound PUSH (translate a
 * berichtsoort payload from learniq's `bron-rod` job, dispatch via the
 * configured transport, persist a `rod_message` audit record), and the
 * inbound RETOUR path (verify + translate a DUO acknowledgement, persist
 * its own audit record, dispatch `RodAcknowledgementReceivedEvent` for
 * learniq's `ExchangeRejectionDetail` worklist to subscribe to). Mirrors
 * {@see IwmoIjwSyncService} (provider seam + per-message persistence) and
 * {@see \OCA\Integriq\Service\DigitalPost\DigitalPostService} (typed
 * event on receipt).
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use OCA\Integriq\Event\RodAcknowledgementReceivedEvent;
use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\Rod\RodAcknowledgementTranslator;
use OCA\Integriq\Service\Rod\RodEnvelopeTranslator;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives the ROD outbound send and inbound retour paths.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
 */
class RodService {

	/**
	 * OpenRegister register slug holding ROD sources and message records.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * OR schema slug for a ROD source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a `rod_message` record.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'rod_message';

	/**
	 * `source.type` value identifying a ROD source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'rod';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/message persistence.
	 * @param RodProviderRegistry $providers The registered ROD provider bindings.
	 * @param RodEnvelopeTranslator $envelopeTranslator Translates a berichtsoort payload into an envelope.
	 * @param RodAcknowledgementTranslator $ackTranslator Translates a retour into a status update.
	 * @param IEventDispatcher $eventDispatcher The Nextcloud event dispatcher.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly RodProviderRegistry $providers,
		private readonly RodEnvelopeTranslator $envelopeTranslator,
		private readonly RodAcknowledgementTranslator $ackTranslator,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly RawSourceResolver $rawSourceResolver,
	) {

	}//end __construct()

	/**
	 * Translate and dispatch one outbound ROD bericht.
	 *
	 * @param string $berichtsoort One of `inschrijving`|`uitschrijving`|`verblijfsgegevens`|`schooladvies`.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — see design.md's field table.
	 *
	 * @return array{ref: string, berichtsoort: string, status: string} The provider ref, echoed
	 *                                                                  berichtsoort, and outcome status.
	 *
	 * @throws RodTranslationException When a required field is missing/empty (no record persisted —
	 *                                 nothing was sent).
	 * @throws RodProviderException When no active source is configured, or the transport fails (a
	 *                              `status: failed` `rod_message` IS persisted first).
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function sendBericht(string $berichtsoort, string $kenmerk, array $payload): array {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->providers->get(providerId: (string)($configuration['provider'] ?? ''));

		// Translation failures never reach the transport and never get an
		// audit record — no envelope exists yet to key one on (REQ-002).
		$envelopeXml = $this->envelopeTranslator->translate(berichtsoort: $berichtsoort, kenmerk: $kenmerk, payload: $payload);

		$status = 'sent';
		$error = null;
		$ref = $kenmerk;
		try {
			$ref = $provider->send(
				sourceConfiguration: $configuration,
				berichtsoort: $berichtsoort,
				kenmerk: $kenmerk,
				envelopeXml: $envelopeXml
			);
		} catch (RodProviderException $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$record = [
			'direction' => 'outbound',
			'berichtsoort' => $berichtsoort,
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
			throw new RodProviderException(message: (string)$error);
		}

		return ['ref' => $ref, 'berichtsoort' => $berichtsoort, 'status' => $status];
	}//end sendBericht()

	/**
	 * Receive, verify-translate, and process one DUO acknowledgement/retour.
	 *
	 * Signature verification happens in the controller (mirrors
	 * `IwmoIjwController::inbound()`) — by the time this method runs the
	 * caller has already established the request is authentic. This method
	 * NEVER throws out to the controller: any failure is logged, a
	 * `rod_message` record is persisted when enough context exists, and the
	 * method returns — the controller always acknowledges receipt.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	public function receiveReturn(string $rawXml): void {
		try {
			$update = $this->ackTranslator->translate(xml: $rawXml);
		} catch (Throwable $exception) {
			$this->logger->warning(
				$this->l->t('ROD retour could not be translated; dropped'),
				['exception' => $exception->getMessage()]
			);
			return;
		}

		$outbound = $this->findByKenmerk(kenmerk: $update['kenmerk']);
		$berichtsoort = '';
		$error = null;
		if ($outbound !== null) {
			$berichtsoort = (string)($outbound->getObject()['berichtsoort'] ?? '');
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
				'berichtsoort' => $berichtsoort,
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
				$this->l->t('ROD retour kenmerk did not resolve to a known outbound message'),
				['kenmerk' => $update['kenmerk']]
			);
		}

		$this->eventDispatcher->dispatchTyped(
			new RodAcknowledgementReceivedEvent(
				kenmerk: $update['kenmerk'],
				signaalcode: $update['signaalcode'],
				signaalOmschrijving: $update['signaalOmschrijving'],
				accepted: $update['accepted'],
				berichtsoort: $berichtsoort,
			)
		);

	}//end receiveReturn()

	/**
	 * Re-attempt every `rod_message` row with `status: failed` or `pending`
	 * through the currently configured transport — driven by
	 * `RodRetryJob`. Per-message isolation: one row's retry exception is
	 * logged and skipped, never aborting the sweep.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
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
					$this->l->t('ROD retry failed for one message; skipped, sweep continues'),
					['ref' => ($data['ref'] ?? null), 'exception' => $exception->getMessage()]
				);
			}
		}//end foreach

		return $retried;
	}//end retryFailed()

	/**
	 * Re-dispatch one previously failed message.
	 *
	 * The originally rendered envelope XML is NOT retained (only the
	 * berichtsoort/kenmerk/ref were persisted — the payload may have carried
	 * a BSN, deliberately never stored verbatim, see REQ-006). A retry
	 * therefore re-attempts transport dispatch using the source's CURRENT
	 * provider against a minimal re-derived envelope stub carrying just the
	 * berichtsoort and kenmerk — best-effort at the reference level,
	 * mirroring `IwmoIjwSyncService::retryOne()`'s identical trade-off. A
	 * truly complete resend requires the caller to re-submit `sendBericht()`
	 * with the original payload.
	 *
	 * @param ObjectEntity $message The failed `rod_message` row.
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

		$berichtsoort = (string)($data['berichtsoort'] ?? '');
		$kenmerk = (string)($data['kenmerk'] ?? '');
		$envelopeXml = '<RodRetry kenmerk="' . htmlspecialchars($kenmerk, ENT_XML1 | ENT_QUOTES) . '"/>';

		$provider->send(sourceConfiguration: $configuration, berichtsoort: $berichtsoort, kenmerk: $kenmerk, envelopeXml: $envelopeXml);

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
	 * Resolve the single active ROD source (`type=rod`, `isEnabled=true`).
	 *
	 * See {@see RawSourceResolver} for why a raw re-read by uuid is required
	 * (ocon#242 / openregister#459) — `findAll()` always renders, stripping
	 * the credentials `RodEdukoppelingClient` needs.
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws RodProviderException When no active ROD source is configured.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
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
			throw new RodProviderException(
				message: 'No active ROD source is configured (register "integriq", schema "source", type "rod", '
					. 'isEnabled=true). Configure one before using the ROD bridge.'
			);
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()

	/**
	 * Find an existing outbound `rod_message` row by its `kenmerk`.
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
