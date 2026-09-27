<?php

/**
 * Integriq OSO Service.
 *
 * Core of integriq-adapter-oso: resolves the configured OSO (`type=oso`)
 * source + provider binding, drives the outbound EXPORT (translate an
 * already-review-cleared payload from learniq's `oso` job, dispatch via
 * the configured transport, persist an `oso_message` audit record), the
 * inbound IMPORT path (verify + parse an incoming overstapdossier, persist
 * its own audit record, dispatch `OsoDossierReceivedEvent` for learniq's
 * `oso-inbound-contract` listener), and the export RETOUR path (verify +
 * translate an acknowledgement, dispatch `OsoAcknowledgementReceivedEvent`).
 * Mirrors `RodService`.
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use OCA\Integriq\Event\OsoAcknowledgementReceivedEvent;
use OCA\Integriq\Event\OsoDossierReceivedEvent;
use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Oso\OsoAcknowledgementTranslator;
use OCA\Integriq\Service\Oso\OsoExportEnvelopeTranslator;
use OCA\Integriq\Service\Oso\OsoImportTranslator;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives the OSO export, import and export-retour paths.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
 */
class OsoService {

	/**
	 * OpenRegister register slug holding OSO sources and message records.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * OR schema slug for an OSO source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for an `oso_message` record.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'oso_message';

	/**
	 * `source.type` value identifying an OSO source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'oso';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/message persistence.
	 * @param OsoProviderRegistry $providers The registered OSO export provider bindings.
	 * @param OsoExportEnvelopeTranslator $exportTranslator Translates an export payload into an envelope.
	 * @param OsoImportTranslator $importTranslator Translates an inbound dossier into OsoImportDossier fields.
	 * @param OsoAcknowledgementTranslator $ackTranslator Translates an export retour into a status update.
	 * @param IEventDispatcher $eventDispatcher The Nextcloud event dispatcher.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly OsoProviderRegistry $providers,
		private readonly OsoExportEnvelopeTranslator $exportTranslator,
		private readonly OsoImportTranslator $importTranslator,
		private readonly OsoAcknowledgementTranslator $ackTranslator,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly RawSourceResolver $rawSourceResolver,
	) {

	}//end __construct()

	/**
	 * Translate and dispatch one outbound OSO export.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — see design.md's field table.
	 *
	 * @return array{ref: string, direction: string, status: string} The provider ref, direction, status.
	 *
	 * @throws OsoTranslationException When a required field is missing/empty.
	 * @throws OsoProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function sendExport(string $kenmerk, array $payload): array {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->providers->get(providerId: (string)($configuration['provider'] ?? ''));

		$envelopeXml = $this->exportTranslator->translate(kenmerk: $kenmerk, payload: $payload);

		$status = 'sent';
		$error = null;
		$ref = $kenmerk;
		try {
			$ref = $provider->sendExport(sourceConfiguration: $configuration, kenmerk: $kenmerk, envelopeXml: $envelopeXml);
		} catch (OsoProviderException $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$this->objectService->saveObject(
			object: [
				'direction' => 'export',
				'status' => $status,
				'ref' => $ref,
				'kenmerk' => $kenmerk,
				'sourceSchoolBrin' => null,
				'learnerEckId' => ($payload['learnerEckId'] ?? null),
				'error' => $error,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		if ($status === 'failed') {
			throw new OsoProviderException(message: (string)$error);
		}

		return ['ref' => $ref, 'direction' => 'export', 'status' => $status];
	}//end sendExport()

	/**
	 * Receive, verify-parse, and process one inbound OSO overstapdossier.
	 *
	 * Signature verification happens in the controller. This method NEVER
	 * throws out to the controller: any failure is logged, an
	 * `oso_message` record is persisted, and the method returns.
	 *
	 * @param string $rawXml The raw inbound dossier XML.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
	 */
	public function receiveImport(string $rawXml): void {
		try {
			$parsed = $this->importTranslator->translate(xml: $rawXml);
		} catch (Throwable $exception) {
			$this->logger->warning(
				$this->l->t('OSO inbound dossier could not be parsed; dropped'),
				['exception' => $exception->getMessage()]
			);
			return;
		}

		$this->objectService->saveObject(
			object: [
				'direction' => 'import',
				'status' => 'received',
				'ref' => null,
				'kenmerk' => null,
				'sourceSchoolBrin' => $parsed['sourceSchoolBrin'],
				'learnerEckId' => $parsed['learnerEckId'],
				'error' => null,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		$this->eventDispatcher->dispatchTyped(
			new OsoDossierReceivedEvent(
				sourceSchoolBrin: $parsed['sourceSchoolBrin'],
				learnerEckId: $parsed['learnerEckId'],
				categories: $parsed['categories'],
				draftProfile: $parsed['draftProfile'],
				attachmentRefs: $parsed['attachmentRefs'],
			)
		);

	}//end receiveImport()

	/**
	 * Receive, verify-translate, and process one export acknowledgement/retour.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	public function receiveReturn(string $rawXml): void {
		try {
			$update = $this->ackTranslator->translate(xml: $rawXml);
		} catch (Throwable $exception) {
			$this->logger->warning(
				$this->l->t('OSO retour could not be translated; dropped'),
				['exception' => $exception->getMessage()]
			);
			return;
		}

		$status = 'rejected';
		if ($update['accepted'] === true) {
			$status = 'acknowledged';
		}

		$this->objectService->saveObject(
			object: [
				'direction' => 'export',
				'status' => $status,
				'ref' => null,
				'kenmerk' => $update['kenmerk'],
				'sourceSchoolBrin' => null,
				'learnerEckId' => null,
				'error' => null,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		$this->eventDispatcher->dispatchTyped(
			new OsoAcknowledgementReceivedEvent(
				kenmerk: $update['kenmerk'],
				signaalcode: $update['signaalcode'],
				signaalOmschrijving: $update['signaalOmschrijving'],
				accepted: $update['accepted'],
			)
		);

	}//end receiveReturn()

	/**
	 * Re-attempt every export `oso_message` row with `status: failed` or
	 * `pending` through the currently configured transport — driven by
	 * `OsoRetryJob`. Per-message isolation.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
	 */
	public function retryFailed(): int {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MESSAGE,
					'direction' => 'export',
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
					$this->l->t('OSO retry failed for one message; skipped, sweep continues'),
					['ref' => ($data['ref'] ?? null), 'exception' => $exception->getMessage()]
				);
			}
		}//end foreach

		return $retried;
	}//end retryFailed()

	/**
	 * Re-dispatch one previously failed export.
	 *
	 * @param ObjectEntity $message The failed `oso_message` row.
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

		$kenmerk = (string)($data['kenmerk'] ?? '');
		$envelopeXml = '<OsoRetry kenmerk="' . htmlspecialchars($kenmerk, ENT_XML1 | ENT_QUOTES) . '"/>';

		$provider->sendExport(sourceConfiguration: $configuration, kenmerk: $kenmerk, envelopeXml: $envelopeXml);

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
	 * Resolve the single active OSO source (`type=oso`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws OsoProviderException When no active OSO source is configured.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
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
			throw new OsoProviderException(
				message: 'No active OSO source is configured (register "integriq", schema "source", type "oso", '
					. 'isEnabled=true). Configure one before using the OSO bridge.'
			);
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()
}//end class
