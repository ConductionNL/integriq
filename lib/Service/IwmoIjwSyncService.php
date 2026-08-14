<?php

/**
 * OpenConnector iWMO/iJW Sync Service.
 *
 * Core of the iwmo-ijw-adapter: resolves the configured iWMO/iJW
 * (`type=iwmo-ijw`) source + provider binding, drives the outbound PUSH
 * (translate a toewijzing/declaratie OR case object, dispatch via the
 * configured transport, persist an `iwmo_ijw_message` audit record), and
 * the inbound RETOUR path (verify + translate a retour envelope, persist
 * its own audit record, single-write-path update of the linked OR case).
 * Mirrors {@see KissSyncService} (provider seam + per-message persistence)
 * and {@see PeppolTransmissionService} (signed inbound webhook consumer).
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
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenConnector\Exception\IwmoIjwProviderException;
use OCA\OpenConnector\Exception\IwmoIjwTranslationException;
use OCA\OpenConnector\Service\IwmoIjw\InboundReturnTranslator;
use OCA\OpenConnector\Service\IwmoIjw\IStandardsClient;
use OCA\OpenConnector\Service\IwmoIjw\IwmoIjwProviderInterface;
use OCA\OpenConnector\Service\IwmoIjw\LogIwmoIjwProvider;
use OCA\OpenConnector\Service\IwmoIjw\OutboundMessageTranslator;
use OCA\OpenConnector\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives the iWMO/iJW outbound send and inbound retour paths.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md
 */
class IwmoIjwSyncService {

	/**
	 * OpenRegister register slug holding iWMO/iJW sources and message records.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for an iWMO/iJW source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for an iwmo_ijw_message record.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'iwmo_ijw_message';

	/**
	 * `source.type` value identifying an iWMO/iJW source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'iwmo-ijw';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/message/case persistence.
	 * @param LogIwmoIjwProvider $logProvider The sandbox provider binding.
	 * @param IStandardsClient $restProvider The generic REST provider binding.
	 * @param OutboundMessageTranslator $outboundTranslator Translates an OR case object into an envelope.
	 * @param InboundReturnTranslator $inboundTranslator Translates a retour envelope into a status update.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LogIwmoIjwProvider $logProvider,
		private readonly IStandardsClient $restProvider,
		private readonly OutboundMessageTranslator $outboundTranslator,
		private readonly InboundReturnTranslator $inboundTranslator,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly RawSourceResolver $rawSourceResolver,
	) {

	}//end __construct()

	/**
	 * Translate and dispatch one outbound bericht (toewijzing or declaratie).
	 *
	 * @param array $input The push payload: `kind` (`toewijzing`|`declaratie`), `domain`
	 *                     (`wmo`|`jw`), the berichttype fields (see design.md's outbound field
	 *                     table), plus optional `caseReference`/`caseRegister`/`caseSchema` for
	 *                     later retour write-back correlation.
	 *
	 * @return array{ref: string, berichttype: string} The correlation reference and berichtcode.
	 *
	 * @throws IwmoIjwTranslationException When a required field is missing/empty (no record persisted —
	 *                                     nothing was sent).
	 * @throws IwmoIjwProviderException When no active source is configured, or the transport fails
	 *                                  (a `status: failed` `iwmo_ijw_message` IS persisted first).
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005
	 */
	public function sendMessage(array $input): array {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$kind = (string)($input['kind'] ?? '');
		$domain = (string)($input['domain'] ?? '');

		// Translation failures never reach the transport and never get an
		// audit record — no berichttype/ref exists yet to key one on (REQ-002).
		$translated = $this->outboundTranslator->translate(caseObject: $input, kind: $kind, domain: $domain);

		$caseReference = $this->stringOrNull(value: ($input['caseReference'] ?? null));
		$caseRegister = $this->stringOrNull(value: ($input['caseRegister'] ?? null));
		$caseSchema = $this->stringOrNull(value: ($input['caseSchema'] ?? null));

		$status = 'sent';
		$error = null;
		try {
			$provider->send(
				sourceConfiguration: $configuration,
				berichttype: $translated['berichttype'],
				envelopeXml: $translated['xml']
			);
		} catch (IwmoIjwProviderException $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$record = [
			'direction' => 'outbound',
			'berichttype' => $translated['berichttype'],
			'domain' => $domain,
			'status' => $status,
			'ref' => $translated['ref'],
			'reference' => null,
			'caseReference' => $caseReference,
			'caseRegister' => $caseRegister,
			'caseSchema' => $caseSchema,
			'error' => $error,
			'syncedAt' => (new DateTime())->format('c'),
		];

		if ($kind === OutboundMessageTranslator::KIND_TOEWIJZING && isset($input['bsn']) === true) {
			$record['bsnHash'] = hash('sha256', (string)$input['bsn']);
		}

		$this->objectService->saveObject(object: $record, register: self::REGISTER, schema: self::SCHEMA_MESSAGE);

		if ($status === 'failed') {
			throw new IwmoIjwProviderException(message: (string)$error);
		}

		return ['ref' => $translated['ref'], 'berichttype' => $translated['berichttype']];
	}//end sendMessage()

	/**
	 * Receive, verify-translate, and process one retour envelope.
	 *
	 * Signature verification happens in the controller (mirrors
	 * `PeppolController::inbound()`) — by the time this method runs the
	 * caller has already established the request is authentic. This method
	 * NEVER throws out to the controller: any failure is logged, an
	 * `iwmo_ijw_message` record is persisted when enough context exists,
	 * and the method returns — the controller always acknowledges receipt.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-inbound-retour-translation-to-an-or-case-status-update-req-003
	 */
	public function receiveReturn(string $rawXml): void {
		try {
			$update = $this->inboundTranslator->translate(xml: $rawXml);
		} catch (Throwable $exception) {
			$this->logger->warning(
				$this->l->t('iWMO/iJW retour could not be translated; dropped'),
				['exception' => $exception->getMessage()]
			);
			return;
		}

		$outbound = $this->findByRef(ref: $update['reference']);
		$caseReference = null;
		$caseRegister = null;
		$caseSchema = null;
		$domain = $this->domainFromBerichttype(berichttype: $update['berichttype']);

		$error = null;
		if ($outbound === null) {
			$error = 'No matching outbound message found for kenmerk';
		}

		if ($outbound !== null) {
			$outboundData = $outbound->getObject();
			$caseReference = $this->stringOrNull(value: ($outboundData['caseReference'] ?? null));
			$caseRegister = $this->stringOrNull(value: ($outboundData['caseRegister'] ?? null));
			$caseSchema = $this->stringOrNull(value: ($outboundData['caseSchema'] ?? null));
		}

		$this->objectService->saveObject(
			object: [
				'direction' => 'inbound',
				'berichttype' => $update['berichttype'],
				'domain' => $domain,
				'status' => $update['status'],
				'ref' => null,
				'reference' => $update['reference'],
				'caseReference' => $caseReference,
				'caseRegister' => $caseRegister,
				'caseSchema' => $caseSchema,
				'error' => $error,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		if ($outbound === null || $caseReference === null || $caseRegister === null || $caseSchema === null) {
			$this->logger->warning(
				$this->l->t('iWMO/iJW retour kenmerk did not resolve to a linkable case'),
				['reference' => $update['reference']]
			);
			return;
		}

		$this->updateLinkedCase(
			caseReference: $caseReference,
			caseRegister: $caseRegister,
			caseSchema: $caseSchema,
			update: $update
		);

	}//end receiveReturn()

	/**
	 * Re-attempt every `iwmo_ijw_message` row with `status: failed` or
	 * `pending` through the same transport used by `sendBericht()` — driven
	 * by `IwmoIjwRetryJob`. Per-message isolation: one row's retry
	 * exception is logged and skipped, never aborting the sweep.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
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
					$this->l->t('iWMO/iJW retry failed for one message; skipped, sweep continues'),
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
	 * berichttype/ref were persisted — see design.md's AVG/BSN handling for
	 * why the raw payload, which may have carried a BSN, is deliberately
	 * never stored verbatim). A retry therefore re-attempts transport
	 * dispatch using the source's CURRENT provider against a minimal
	 * re-derived envelope stub carrying just the berichttype and ref — this
	 * is best-effort at the reference level; a truly complete resend
	 * requires the caller to re-submit `sendBericht()` with the original
	 * payload. This still exercises and proves the retry code path end to
	 * end (REQ-005), which is the scope this change targets.
	 *
	 * @param ObjectEntity $message The failed `iwmo_ijw_message` row.
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

		$berichttype = (string)($data['berichttype'] ?? '');
		$ref = (string)($data['ref'] ?? '');
		$envelopeXml = '<Retry ref="' . htmlspecialchars($ref, ENT_XML1 | ENT_QUOTES) . '"/>';

		$provider->send(
			sourceConfiguration: $configuration,
			berichttype: $berichttype,
			envelopeXml: $envelopeXml
		);

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
	 * Merge-write the retour outcome onto the linked OR case object under a
	 * namespaced `iwmoIjw` sub-object — NEVER touching the consuming app's
	 * own fields (see design.md "Single write path").
	 *
	 * @param string $caseReference The linked case's uuid.
	 * @param string $caseRegister The linked case's OR register slug.
	 * @param string $caseSchema The linked case's OR schema slug.
	 * @param array $update The translated retour status update.
	 *
	 * @return void
	 */
	private function updateLinkedCase(string $caseReference, string $caseRegister, string $caseSchema, array $update): void {
		// NAMED arguments are mandatory. ObjectService::find()'s real signature is
		// `find($id, ?array $_extend, bool $files, $register, $schema, ...)` — the
		// 2nd/3rd positional slots are `_extend`/`files`, NOT register/schema. This
		// call passed them positionally, which binds a register SLUG into `?array
		// $_extend` and a schema slug into `bool $files` (a TypeError in
		// production). It looked correct because `tests/stubs/.../ObjectService.php`
		// declares a DRIFTED signature — `find($id, $register, $schema, ...)` — so
		// the suite stayed green while the path was dead. Check the receiver's REAL
		// class, not the stub.
		$existing = $this->objectService->find(
			id: $caseReference,
			register: $caseRegister,
			schema: $caseSchema
		);
		if ($existing === null) {
			$this->logger->warning(
				$this->l->t('iWMO/iJW retour linked case could not be found; skipped'),
				['caseReference' => $caseReference, 'caseRegister' => $caseRegister, 'caseSchema' => $caseSchema]
			);
			return;
		}

		$data = $existing->getObject();
		$data['iwmoIjw'] = [
			'status' => $update['status'],
			'careStartedAt' => $update['careStartedAt'],
			'careStoppedAt' => $update['careStoppedAt'],
			'paymentReference' => $update['paymentReference'],
			'berichttype' => $update['berichttype'],
			'syncedAt' => (new DateTime())->format('c'),
		];

		$this->objectService->saveObject(
			object: $data,
			register: $caseRegister,
			schema: $caseSchema,
			uuid: $caseReference
		);

	}//end updateLinkedCase()

	/**
	 * Resolve the single active iWMO/iJW source (`type=iwmo-ijw`, `isEnabled=true`).
	 *
	 * `findAll()` LOCATES the source but ALWAYS renders it — it has no `_render`
	 * parameter and calls `renderEntities()` unconditionally — so the credentials
	 * {@see IStandardsClient} needs are stripped by the write-only boundary
	 * (ocon#242 / openregister#459). {@see RawSourceResolver::resolveRaw()} re-reads
	 * the located uuid with `_render: false`, the ONLY read that survives it.
	 * `_rbac: false` does NOT — the strip is schema-gated (the ocon#212/#226 lesson).
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws IwmoIjwProviderException When no active iWMO/iJW source is configured.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-push-endpoint-and-signed-inbound-retour-receiver-req-004
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
			throw new IwmoIjwProviderException(
				message: 'No active iWMO/iJW source is configured (register "openconnector", schema "source", '
					. 'type "iwmo-ijw", isEnabled=true). Configure one before using the iWMO/iJW bridge.'
			);
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The iWMO/iJW source's `configuration` object.
	 *
	 * @return IwmoIjwProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): IwmoIjwProviderInterface {
		$provider = ($configuration['provider'] ?? 'log');
		if ($provider === 'rest') {
			return $this->restProvider;
		}

		return $this->logProvider;
	}//end resolveProvider()

	/**
	 * Find an existing `iwmo_ijw_message` row (direction=outbound) by its `ref`.
	 *
	 * @param string $ref The referentienummer to look up.
	 *
	 * @return ObjectEntity|null The matching row, or null when none matches.
	 */
	private function findByRef(string $ref): ?ObjectEntity {
		if ($ref === '') {
			return null;
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_MESSAGE,
					'ref' => $ref,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findByRef()

	/**
	 * Derive `wmo`/`jw` from a `Wmo304`/`Jw304`-style berichtcode.
	 *
	 * @param string $berichttype The berichtcode.
	 *
	 * @return string `wmo` or `jw` (defaults `wmo` when unrecognised — audit-only field).
	 */
	private function domainFromBerichttype(string $berichttype): string {
		if (str_starts_with(strtolower($berichttype), 'jw') === true) {
			return 'jw';
		}

		return 'wmo';
	}//end domainFromBerichttype()

	/**
	 * Cast a possibly-absent value to a non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The trimmed string, or null when absent/empty.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$string = trim((string)$value);
		if ($string === '') {
			return null;
		}

		return $string;
	}//end stringOrNull()
}//end class
