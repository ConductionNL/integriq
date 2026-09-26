<?php

/**
 * Integriq UWLR/Edu-V Service.
 *
 * Core of integriq-adapter-uwlr-eduv: resolves the configured
 * UWLR/Edu-V/Basispoort/Entree-content (`type=uwlr-eduv`) source +
 * provider binding, drives all four outbound sends (translate the payload
 * for the chosen target/subtype, dispatch via the configured transport,
 * persist a `uwlr_eduv_message` audit record) and the shared RETOUR path
 * (verify + translate an acknowledgement, dispatch
 * `UwlrEduVAcknowledgementReceivedEvent`). Mirrors `OsoService`.
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use OCA\Integriq\Event\UwlrEduVAcknowledgementReceivedEvent;
use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\UwlrEduV\BasispoortSyncTranslator;
use OCA\Integriq\Service\UwlrEduV\EduVExportEnvelopeTranslator;
use OCA\Integriq\Service\UwlrEduV\EntreeContentSyncTranslator;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVAcknowledgementTranslator;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderRegistry;
use OCA\Integriq\Service\UwlrEduV\UwlrExportEnvelopeTranslator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives the UWLR/Edu-V/Basispoort/Entree-content sends and the shared
 * acknowledgement/retour path.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
 */
class UwlrEduVService {

	/**
	 * OpenRegister register slug holding UWLR/Edu-V sources and message records.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * OR schema slug for a UWLR/Edu-V source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a `uwlr_eduv_message` record.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'uwlr_eduv_message';

	/**
	 * `source.type` value identifying a UWLR/Edu-V source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'uwlr-eduv';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/message persistence.
	 * @param UwlrEduVProviderRegistry $providers The registered provider bindings.
	 * @param UwlrExportEnvelopeTranslator $uwlrTranslator Translates a uwlr export payload.
	 * @param EduVExportEnvelopeTranslator $eduVTranslator Translates an edu-v export payload.
	 * @param BasispoortSyncTranslator $basispoortTranslator Translates a basispoort sync payload.
	 * @param EntreeContentSyncTranslator $entreeTranslator Translates an entree-content sync payload.
	 * @param UwlrEduVAcknowledgementTranslator $ackTranslator Translates a retour into a status update.
	 * @param IEventDispatcher $eventDispatcher The Nextcloud event dispatcher.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly UwlrEduVProviderRegistry $providers,
		private readonly UwlrExportEnvelopeTranslator $uwlrTranslator,
		private readonly EduVExportEnvelopeTranslator $eduVTranslator,
		private readonly BasispoortSyncTranslator $basispoortTranslator,
		private readonly EntreeContentSyncTranslator $entreeTranslator,
		private readonly UwlrEduVAcknowledgementTranslator $ackTranslator,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly RawSourceResolver $rawSourceResolver,
	) {

	}//end __construct()

	/**
	 * Translate and dispatch one outbound UWLR export.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $subtype One of `pupil`, `group`, `teacher`.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty or the subtype is unknown.
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
	 */
	public function sendUwlrExport(string $kenmerk, string $subtype, array $payload): array {
		$envelopeXml = $this->uwlrTranslator->translate(kenmerk: $kenmerk, subtype: $subtype, payload: $payload);
		return $this->dispatchAndPersist(
			target: 'uwlr',
			subtype: $subtype,
			direction: 'export',
			kenmerk: $kenmerk,
			envelopeXml: $envelopeXml,
			eckId: (string)($payload['eckId'] ?? '')
		);
	}//end sendUwlrExport()

	/**
	 * Translate and dispatch one outbound Edu-V export.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $dataService One of `onderwijsdeelnemers`, `onderwijsgroepen`, `onderwijsmedewerkers`.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty or the data service is unqualified.
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-003-edu-v-export-envelope-translation-across-three-qualified-data-services
	 */
	public function sendEduVExport(string $kenmerk, string $dataService, array $payload): array {
		$envelopeXml = $this->eduVTranslator->translate(kenmerk: $kenmerk, dataService: $dataService, payload: $payload);
		return $this->dispatchAndPersist(
			target: 'edu-v',
			subtype: $dataService,
			direction: 'export',
			kenmerk: $kenmerk,
			envelopeXml: $envelopeXml,
			eckId: (string)($payload['eckId'] ?? '')
		);
	}//end sendEduVExport()

	/**
	 * Translate and dispatch one Basispoort sync.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`, `ssoAudience`.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty.
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function syncBasispoort(string $kenmerk, array $payload): array {
		$envelopeXml = $this->basispoortTranslator->translate(kenmerk: $kenmerk, payload: $payload);
		return $this->dispatchAndPersist(
			target: 'basispoort',
			subtype: null,
			direction: 'sync',
			kenmerk: $kenmerk,
			envelopeXml: $envelopeXml,
			eckId: (string)($payload['eckId'] ?? '')
		);
	}//end syncBasispoort()

	/**
	 * Translate and dispatch one Entree content sync.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`, `ssoAudience`.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty.
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-005-entree-content-sso-hand-off-translation
	 */
	public function syncEntreeContent(string $kenmerk, array $payload): array {
		$envelopeXml = $this->entreeTranslator->translate(kenmerk: $kenmerk, payload: $payload);
		return $this->dispatchAndPersist(
			target: 'entree-content',
			subtype: null,
			direction: 'sync',
			kenmerk: $kenmerk,
			envelopeXml: $envelopeXml,
			eckId: (string)($payload['eckId'] ?? '')
		);
	}//end syncEntreeContent()

	/**
	 * Receive, verify-translate, and process one acknowledgement/retour,
	 * shared across all four targets.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
	 */
	public function receiveReturn(string $rawXml): void {
		try {
			$update = $this->ackTranslator->translate(xml: $rawXml);
		} catch (Throwable $exception) {
			$this->logger->warning(
				$this->l->t('UWLR/Edu-V retour could not be translated; dropped'),
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
				'target' => 'uwlr',
				'subtype' => null,
				'direction' => 'export',
				'status' => $status,
				'ref' => null,
				'kenmerk' => $update['kenmerk'],
				'eckId' => null,
				'error' => null,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		$this->eventDispatcher->dispatchTyped(
			new UwlrEduVAcknowledgementReceivedEvent(
				kenmerk: $update['kenmerk'],
				signaalcode: $update['signaalcode'],
				signaalOmschrijving: $update['signaalOmschrijving'],
				accepted: $update['accepted'],
			)
		);

	}//end receiveReturn()

	/**
	 * Re-attempt every `uwlr_eduv_message` row with `status: failed` through
	 * the currently configured transport — driven by `UwlrEduVRetryJob`.
	 * Per-message isolation, across every target.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-failed-send-persists-and-is-retried-in-isolation
	 */
	public function retryFailed(): int {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MESSAGE,
				],
			]
		);
		$results = ($matches['results'] ?? $matches);

		$retried = 0;
		foreach ($results as $message) {
			$data = $message->getObject();
			if (($data['status'] ?? null) !== 'failed') {
				continue;
			}

			try {
				$this->retryOne(message: $message, data: $data);
				$retried++;
			} catch (Throwable $exception) {
				$this->logger->warning(
					$this->l->t('UWLR/Edu-V retry failed for one message; skipped, sweep continues'),
					['ref' => ($data['ref'] ?? null), 'exception' => $exception->getMessage()]
				);
			}
		}//end foreach

		return $retried;
	}//end retryFailed()

	/**
	 * Re-dispatch one previously failed send.
	 *
	 * @param ObjectEntity $message The failed `uwlr_eduv_message` row.
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

		$target = (string)($data['target'] ?? '');
		$kenmerk = (string)($data['kenmerk'] ?? '');
		$envelopeXml = '<UwlrEduVRetry target="' . htmlspecialchars($target, ENT_XML1 | ENT_QUOTES)
			. '" kenmerk="' . htmlspecialchars($kenmerk, ENT_XML1 | ENT_QUOTES) . '"/>';

		$provider->send(sourceConfiguration: $configuration, target: $target, kenmerk: $kenmerk, envelopeXml: $envelopeXml);

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
	 * Resolve the single active UWLR/Edu-V source (`type=uwlr-eduv`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws UwlrEduVProviderException When no active source is configured.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-008-pushsync-endpoints-and-a-shared-signed-retour-endpoint
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
			throw new UwlrEduVProviderException(
				message: 'No active UWLR/Edu-V source is configured (register "integriq", schema "source", '
					. 'type "uwlr-eduv", isEnabled=true). Configure one before using this bridge.'
			);
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()

	/**
	 * Shared dispatch-then-persist tail for all four send methods.
	 *
	 * @param string $target One of `uwlr`, `edu-v`, `basispoort`, `entree-content`.
	 * @param string|null $subtype The subtype/data service, or null when not applicable.
	 * @param string $direction `export` or `sync`.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $envelopeXml The fully rendered envelope.
	 * @param string $eckId The pupil ECK iD, when present in the payload.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 */
	private function dispatchAndPersist(
		string $target,
		?string $subtype,
		string $direction,
		string $kenmerk,
		string $envelopeXml,
		string $eckId
	): array {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->providers->get(providerId: (string)($configuration['provider'] ?? ''));

		$status = 'sent';
		$error = null;
		$ref = $kenmerk;
		try {
			$ref = $provider->send(sourceConfiguration: $configuration, target: $target, kenmerk: $kenmerk, envelopeXml: $envelopeXml);
		} catch (UwlrEduVProviderException $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$this->objectService->saveObject(
			object: [
				'target' => $target,
				'subtype' => $subtype,
				'direction' => $direction,
				'status' => $status,
				'ref' => $ref,
				'kenmerk' => $kenmerk,
				'eckId' => $eckId,
				'error' => $error,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		if ($status === 'failed') {
			throw new UwlrEduVProviderException(message: (string)$error);
		}

		return ['ref' => $ref, 'target' => $target, 'status' => $status];
	}//end dispatchAndPersist()
}//end class
