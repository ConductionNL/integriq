<?php

/**
 * OpenConnector StUF-ZKN Sync Service.
 *
 * Core of the stuf-zkn-bridge: resolves the configured `type=stuf-zkn`
 * source + provider binding, drives the INBOUND path (parse+translate a
 * `zakLk01`/`edcLk01` kennisgeving, upsert the OR/ZGW zaak/document object,
 * persist a `stuf_message` audit record, reply `Bv03`/`Fo03`) and the
 * OUTBOUND path (translate an OR/ZGW zaak change into a `zakLk01`
 * kennisgeving, dispatch via the configured provider, persist a
 * `stuf_message` audit record). Mirrors {@see IwmoIjwSyncService}
 * (provider seam + per-message persistence + isolated retry) and
 * {@see \OCA\OpenConnector\Service\DsoIngestService} (translate + persist +
 * outbound-provider dispatch split).
 *
 * IDEMPOTENCY: a redelivered inbound StUF message (same
 * `stuurgegevens.referentienummer`) MUST NOT create a second
 * `stuf_message` row nor apply its OR write twice — see
 * {@see receiveInbound()}. Once a `referentienummer` has a `stuf_message`
 * row with `status=processed`, a redelivery short-circuits straight to a
 * fresh `Bv03` acknowledgement without touching the OR object again.
 *
 * AVG/persoonsgegevens hygiene: the raw inbound/outbound envelope XML is
 * NEVER persisted on `stuf_message` — a zaak/document can carry
 * persoonsgegevens (betrokkene BSN, namen) that this fleet's
 * `AvgBsnPolicyRule`/`iwmo_ijw_message` precedent already treats as
 * "never store the raw wire payload verbatim", only the correlation/audit
 * metadata (mirrors `IwmoIjwSyncService::retryOne()`'s identical, already-
 * accepted limitation: retry is best-effort at the reference level, not a
 * full replay of the original payload).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenConnector\Exception\StufZknProviderException;
use OCA\OpenConnector\Exception\StufZknTranslationException;
use OCA\OpenConnector\Service\Security\RawSourceResolver;
use OCA\OpenConnector\Service\StufZkn\InboundMessageTranslator;
use OCA\OpenConnector\Service\StufZkn\LogStufZknProvider;
use OCA\OpenConnector\Service\StufZkn\OutboundNotificationTranslator;
use OCA\OpenConnector\Service\StufZkn\StufZknAcknowledgementBuilder;
use OCA\OpenConnector\Service\StufZkn\StufZknClient;
use OCA\OpenConnector\Service\StufZkn\StufZknProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives the StUF-ZKN inbound kennisgeving intake and outbound kennisgeving send.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md
 */
class StufZknSyncService {

	/**
	 * OpenRegister register slug holding stuf-zkn sources and message records.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for a stuf-zkn source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a stuf_message audit record.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'stuf_message';

	/**
	 * `source.type` value identifying a stuf-zkn source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'stuf-zkn';

	/**
	 * Default target register for an upserted zaak — see design.md "How procest consumes this".
	 *
	 * @var string
	 */
	public const DEFAULT_ZAAK_REGISTER = 'zaken';

	/**
	 * Default target schema for an upserted zaak (ZGW Zaken API resource-name convention, per
	 * procest's `ZgwService::RESOURCE_MAP['zaken']['zaken'] === 'zaak'`).
	 *
	 * @var string
	 */
	public const DEFAULT_ZAAK_SCHEMA = 'zaak';

	/**
	 * Default target register for an upserted document.
	 *
	 * @var string
	 */
	public const DEFAULT_DOCUMENT_REGISTER = 'documenten';

	/**
	 * Default target schema for an upserted document (ZGW Documenten API resource-name convention,
	 * per `ZgwService::RESOURCE_MAP['documenten']['enkelvoudiginformatieobjecten']`).
	 *
	 * @var string
	 */
	public const DEFAULT_DOCUMENT_SCHEMA = 'enkelvoudiginformatieobject';

	/**
	 * Fallback organisatie code used to address a Bv03/Fo03 reply when no active source is
	 * configured — an inbound message must always be acknowledgeable, even before any source exists.
	 *
	 * @var string
	 */
	private const FALLBACK_ORGANISATIE = 'OpenConnector';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/message/zaak/document persistence.
	 * @param LogStufZknProvider $logProvider The sandbox outbound provider binding.
	 * @param StufZknClient $restProvider The generic REST/mTLS outbound provider binding.
	 * @param InboundMessageTranslator $inboundTranslator Translates an inbound kennisgeving.
	 * @param OutboundNotificationTranslator $outboundTranslator Translates an OR zaak into a kennisgeving.
	 * @param StufZknAcknowledgementBuilder $ackBuilder Builds Bv03/Fo03 replies.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LogStufZknProvider $logProvider,
		private readonly StufZknClient $restProvider,
		private readonly InboundMessageTranslator $inboundTranslator,
		private readonly OutboundNotificationTranslator $outboundTranslator,
		private readonly StufZknAcknowledgementBuilder $ackBuilder,
		private readonly LoggerInterface $logger,
		private readonly RawSourceResolver $rawSourceResolver,
	) {

	}//end __construct()

	/**
	 * Receive, translate, upsert, and acknowledge one inbound StUF-ZKN SOAP envelope.
	 *
	 * Never throws out to the controller — every failure path (malformed XML, translation
	 * guard, OR write failure) is captured and shaped into a `Fo03` reply instead (mirrors
	 * `IwmoIjwSyncService::receiveReturn()`'s "the controller always gets something to send
	 * back" contract, adapted to StUF's own ack/fault berichten instead of a bare HTTP status).
	 *
	 * @param string $soapXml The raw inbound SOAP envelope XML, exactly as received on the wire.
	 *
	 * @return string The rendered `Bv03Bericht` (success) or `Fo03Bericht` (fault) reply XML.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-inbound-soap-endpoint-with-bv03-fo03-shaping-req-005
	 */
	public function receiveInbound(string $soapXml): string {
		[$zenderOrganisation] = $this->resolveOrganisationCodes();

		try {
			$translated = $this->inboundTranslator->translate(soapXml: $soapXml);
		} catch (StufZknTranslationException $exception) {
			$this->persistMessage(
				direction: 'inbound',
				berichttype: 'unknown',
				referenceNumber: '',
				processingKind: null,
				entityType: null,
				status: 'failed',
				error: $exception->getMessage()
			);
			$this->logger->warning('[StufZknSyncService] inbound translation failed: ' . $exception->getMessage());

			return $this->ackBuilder->buildFo03(
				reason: 'validation_failed',
				crossRefnummer: '',
				zenderOrganisation: $zenderOrganisation,
				ontvangerOrganisation: ''
			);
		}//end try

		$ontvangerOrganisation = $translated['senderOrganisatie'];

		// Idempotency: a redelivery of an already-fully-processed
		// referentienummer never touches the OR object again — just
		// re-acknowledges (REQ: "a redelivered StUF message must not
		// duplicate").
		$existing = $this->findInboundByReferenceNumber(referenceNumber: $translated['referentienummer']);
		if ($existing !== null && ($existing->getObject()['status'] ?? null) === 'processed') {
			return $this->ackBuilder->buildBv03(
				crossRefnummer: $translated['referentienummer'],
				zenderOrganisation: $zenderOrganisation,
				ontvangerOrganisation: $ontvangerOrganisation
			);
		}

		$status = 'processed';
		$error = null;
		try {
			$this->upsertOrObject(translated: $translated);
		} catch (Throwable $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
			$this->logger->warning('[StufZknSyncService] inbound OR upsert failed: ' . $exception->getMessage());
		}

		$this->persistMessage(
			direction: 'inbound',
			berichttype: $translated['berichttype'],
			referenceNumber: $translated['referentienummer'],
			processingKind: $translated['verwerkingssoort'],
			entityType: $translated['entiteittype'],
			status: $status,
			error: $error,
			existing: $existing
		);

		if ($status === 'failed') {
			return $this->ackBuilder->buildFo03(
				reason: 'processing_failed',
				crossRefnummer: $translated['referentienummer'],
				zenderOrganisation: $zenderOrganisation,
				ontvangerOrganisation: $ontvangerOrganisation
			);
		}

		return $this->ackBuilder->buildBv03(
			crossRefnummer: $translated['referentienummer'],
			zenderOrganisation: $zenderOrganisation,
			ontvangerOrganisation: $ontvangerOrganisation
		);

	}//end receiveInbound()

	/**
	 * Translate and dispatch one outbound `zakLk01` kennisgeving for an OR/ZGW zaak change.
	 *
	 * @param array $case The OR/ZGW zaak object fields — see design.md's outbound field table.
	 * @param string $processingKind `T` (create), `W` (update/status change), or `V` (vervallen).
	 *
	 * @return array{referentienummer: string, ref: string} The kennisgeving's own referentienummer
	 *                                                      and the transport-assigned/derived reference.
	 *
	 * @throws StufZknTranslationException When a required field is missing/empty (no record persisted —
	 *                                     nothing was sent).
	 * @throws StufZknProviderException When no active source is configured, or the transport fails
	 *                                  (a `status: failed` `stuf_message` IS persisted first).
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006
	 */
	public function sendNotification(array $case, string $processingKind): array {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		[$zenderOrganisation, $ontvangerOrganisation] = $this->resolveOrganisationCodes();

		// Translation failures never reach the transport and never get an
		// audit record — no referentienummer exists yet to key one on.
		$translated = $this->outboundTranslator->translate(
			case: $case,
			processingKind: $processingKind,
			zenderOrganisation: $zenderOrganisation,
			ontvangerOrganisation: $ontvangerOrganisation
		);

		$status = 'sent';
		$error = null;
		$ref = '';
		try {
			$ref = $provider->send(
				sourceConfiguration: $configuration,
				referenceNumber: $translated['referentienummer'],
				envelopeXml: $translated['xml']
			);
		} catch (StufZknProviderException $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$this->persistMessage(
			direction: 'outbound',
			berichttype: 'zakLk01',
			referenceNumber: $translated['referentienummer'],
			processingKind: $processingKind,
			entityType: 'ZAK',
			status: $status,
			error: $error
		);

		if ($status === 'failed') {
			throw new StufZknProviderException(message: (string)$error);
		}

		return ['referentienummer' => $translated['referentienummer'], 'ref' => $ref];
	}//end sendNotification()

	/**
	 * Re-attempt every `stuf_message` row with `status: failed` (direction=outbound) through the
	 * currently configured provider. Per-message isolation: one row's retry exception is logged
	 * and skipped, never aborting the sweep — mirrors `IwmoIjwSyncService::retryFailed()`.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
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
			if (($data['status'] ?? null) !== 'failed') {
				continue;
			}

			try {
				$this->retryOne(message: $message, data: $data);
				$retried++;
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[StufZknSyncService] retry failed for one message; skipped, sweep continues',
					['referentienummer' => ($data['referentienummer'] ?? null), 'exception' => $exception->getMessage()]
				);
			}
		}//end foreach

		return $retried;
	}//end retryFailed()

	/**
	 * Resolve the single active StUF-ZKN source (`type=stuf-zkn`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws StufZknProviderException When no active StUF-ZKN source is configured.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
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
			throw new StufZknProviderException(
				message: 'No active StUF-ZKN source is configured (register "openconnector", schema "source", '
					. 'type "stuf-zkn", isEnabled=true). Configure one before using the StUF-ZKN bridge.'
			);
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()

	/**
	 * Best-effort resolve an active source without throwing — used by the inbound leg, which must
	 * always be acknowledgeable even before any source is configured.
	 *
	 * @return ObjectEntity|null The resolved source, or null when none is active.
	 */
	private function tryResolveActiveSource(): ?ObjectEntity {
		try {
			return $this->resolveActiveSource();
		} catch (StufZknProviderException) {
			return null;
		}

	}//end tryResolveActiveSource()

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The stuf-zkn source's `configuration` object.
	 *
	 * @return StufZknProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): StufZknProviderInterface {
		$provider = ($configuration['provider'] ?? 'log');
		if ($provider === 'rest') {
			return $this->restProvider;
		}

		return $this->logProvider;
	}//end resolveProvider()

	/**
	 * Resolve this bridge's own organisatie code (`zender` on outbound, reply `zender` on inbound)
	 * and the configured consumer's organisatie code (`ontvanger` on outbound) from the active
	 * source, falling back to {@see FALLBACK_ORGANISATIE} when no source is configured — the
	 * inbound leg must remain acknowledgeable even before any source exists.
	 *
	 * @return array{0: string, 1: string} `[zenderOrganisatie, ontvangerOrganisatie]`.
	 */
	private function resolveOrganisationCodes(): array {
		$source = $this->tryResolveActiveSource();
		if ($source === null) {
			return [self::FALLBACK_ORGANISATIE, ''];
		}

		$configuration = ($source->getObject()['configuration'] ?? []);
		$zender = (string)($configuration['organisatie'] ?? self::FALLBACK_ORGANISATIE);
		$ontvanger = (string)($source->getObject()['ontvangerOrganisatie'] ?? '');

		return [$zender, $ontvanger];
	}//end resolveOrganisatieCodes()

	/**
	 * Upsert the translated zaak/document into its configured OR target register/schema, keyed by
	 * `identificatie`. `V` (vervallen) marks the existing record `status: vervallen` instead of
	 * deleting it (never destroy data on an external signal); a `V` for an identificatie with no
	 * existing record is a no-op (nothing to vervallen — idempotent).
	 *
	 * @param array<string, mixed> $translated The {@see InboundMessageTranslator::translate()} output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-inbound-zaklk01-edclk01-translation-with-a-literal-leak-guard-req-002
	 */
	private function upsertOrObject(array $translated): void {
		[$register, $schema] = $this->resolveTargetRegisterSchema(kind: $translated['kind']);
		$identification = (string)$translated['fields']['identificatie'];

		$matches = $this->objectService->findAll(
			config: [
				'filters' => ['register' => $register, 'schema' => $schema, 'identificatie' => $identification],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);
		$existing = ($results[0] ?? null);

		if ($translated['verwerkingssoort'] === 'V') {
			if ($existing === null) {
				$this->logger->info(
					'[StufZknSyncService] vervallen for an unknown identificatie — nothing to do',
					['identificatie' => $identification]
				);
				return;
			}

			$data = $existing->getObject();
			$data['status'] = 'vervallen';
			$this->objectService->saveObject(object: $data, register: $register, schema: $schema, uuid: $existing->getUuid());
			return;
		}

		$data = $translated['fields'];
		$uuid = null;
		if ($existing !== null) {
			$uuid = $existing->getUuid();
		}

		$this->objectService->saveObject(object: $data, register: $register, schema: $schema, uuid: $uuid);

	}//end upsertOrObject()

	/**
	 * Resolve the configured (or default) target register/schema for a zaak/document upsert —
	 * configurable on the active source since OR register/schema slugs are legitimately
	 * tenant-specific in this fleet (see design.md "Target register/schema").
	 *
	 * @param string $kind `zaak` or `document`.
	 *
	 * @return array{0: string, 1: string} `[register, schema]`.
	 */
	private function resolveTargetRegisterSchema(string $kind): array {
		$source = $this->tryResolveActiveSource();
		$configuration = ($source?->getObject()['configuration'] ?? []);

		if ($kind === 'document') {
			return [
				(string)($configuration['targetDocumentRegister'] ?? self::DEFAULT_DOCUMENT_REGISTER),
				(string)($configuration['targetDocumentSchema'] ?? self::DEFAULT_DOCUMENT_SCHEMA),
			];
		}

		return [
			(string)($configuration['targetRegister'] ?? self::DEFAULT_ZAAK_REGISTER),
			(string)($configuration['targetSchema'] ?? self::DEFAULT_ZAAK_SCHEMA),
		];

	}//end resolveTargetRegisterSchema()

	/**
	 * Find an existing inbound `stuf_message` row by its `referentienummer`.
	 *
	 * @param string $referenceNumber The referentienummer to look up.
	 *
	 * @return ObjectEntity|null The matching row, or null when none matches.
	 */
	private function findInboundByReferenceNumber(string $referenceNumber): ?ObjectEntity {
		if ($referenceNumber === '') {
			return null;
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MESSAGE,
					'direction' => 'inbound',
					'referentienummer' => $referenceNumber,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findInboundByReferentienummer()

	/**
	 * Persist (or, when `$existing` is given, update in place — never a duplicate row for the same
	 * `referentienummer`) one `stuf_message` audit record.
	 *
	 * @param string $direction `inbound` or `outbound`.
	 * @param string $berichttype `zakLk01`, `edcLk01`, or `unknown`.
	 * @param string $referenceNumber The message's correlation reference (may be empty
	 *                                when translation failed before it could be read).
	 * @param string|null $processingKind `T`/`W`/`I`/`V`, or null when unknown.
	 * @param string|null $entityType `ZAK`/`EDC`, or null when unknown.
	 * @param string $status `processed`/`sent`/`failed`.
	 * @param string|null $error The failure detail, or null on success.
	 * @param ObjectEntity|null $existing An existing row to update in place (idempotent
	 *                                    redelivery retry), or null to create a new row.
	 *
	 * @return void
	 */
	private function persistMessage(
		string $direction,
		string $berichttype,
		string $referenceNumber,
		?string $processingKind,
		?string $entityType,
		string $status,
		?string $error,
		?ObjectEntity $existing = null,
	): void {
		$record = [
			'direction' => $direction,
			'berichttype' => $berichttype,
			'referentienummer' => $referenceNumber,
			'verwerkingssoort' => $processingKind,
			'entiteittype' => $entityType,
			'status' => $status,
			'error' => $error,
			'syncedAt' => (new DateTime())->format('c'),
		];

		$uuid = null;
		if ($existing !== null) {
			$uuid = $existing->getUuid();
		}

		$this->objectService->saveObject(object: $record, register: self::REGISTER, schema: self::SCHEMA_MESSAGE, uuid: $uuid);

	}//end persistMessage()

	/**
	 * Re-dispatch one previously failed outbound message.
	 *
	 * The originally rendered kennisgeving XML is NOT retained (AVG hygiene — see class docblock),
	 * so a retry re-attempts transport dispatch using the source's CURRENT provider against a
	 * minimal re-derived envelope stub carrying just the referentienummer — best-effort at the
	 * reference level (mirrors `IwmoIjwSyncService::retryOne()`'s identical, already-accepted
	 * limitation). A truly complete resend requires the caller to re-submit `sendKennisgeving()`.
	 *
	 * @param ObjectEntity $message The failed `stuf_message` row.
	 * @param array $data The message's object data.
	 *
	 * @return void
	 *
	 * @throws Throwable When the provider send fails again.
	 */
	private function retryOne(ObjectEntity $message, array $data): void {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$referenceNumber = (string)($data['referentienummer'] ?? '');
		$envelopeXml = '<Retry referentienummer="' . htmlspecialchars($referenceNumber, ENT_XML1 | ENT_QUOTES) . '"/>';

		$provider->send(sourceConfiguration: $configuration, referenceNumber: $referenceNumber, envelopeXml: $envelopeXml);

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
}//end class
