<?php

/**
 * Integriq DSO Ingest Service.
 *
 * Completes the dso-connector-adapter: persists an already-verified,
 * already-parsed DSO Verzoek ({@see DSOParserService::parseRequest()}) as a
 * `dso_verzoek` OR record (`received` -> `mapped`|`failed`), and executes
 * the separate, authenticated `verzoek-to-case` handoff through
 * OpenRegister's real `Handoff\HandoffService` under the calling user's own
 * RBAC — see design.md §1 for why this is NOT triggered automatically at
 * webhook-receipt time (HandoffService v1 has no system-user privilege
 * lane). Also drives the outbound leg: builds and dispatches a `status`
 * (voortgangsinformatie) or `besluit` update back to DSO-LV via the
 * {@see Dso\DsoConnectorProviderInterface} seam, persisting a `dso_message`
 * audit row per attempt. Mirrors
 * {@see OpenFormulierenIntakeService} (ingest/handoff split) and
 * {@see IwmoIjwSyncService} (provider seam + per-message audit persistence).
 *
 * `DSOController` stays the thin HTTP/auth shell (signature verification +
 * payload parsing already lived there before this change; this service adds
 * the persistence/mapping/handoff/outbound steps that were previously
 * entirely missing — the controller logged and dropped every verzoek).
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
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use OCA\Integriq\BackgroundJob\FetchDsoAttachmentsJob;
use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Exception\DsoTranslationException;
use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\Dso\DsoConnectorProviderInterface;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Dso\DsoRequestTranslator;
use OCA\Integriq\Service\Dso\LogDsoConnectorProvider;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives dso_verzoek persistence/mapping, the authenticated handoff trigger,
 * and the outbound status/besluit post.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */
class DsoIngestService {

	/**
	 * OpenRegister register slug holding sources, verzoeken, and outbound messages.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * OR schema slug for a DSO source (outbound provider/credential config).
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a dso_verzoek record.
	 *
	 * @var string
	 */
	public const SCHEMA_VERZOEK = 'dso_verzoek';

	/**
	 * OR schema slug for a dso_message (outbound audit) record.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'dso_message';

	/**
	 * `source.type` value identifying a DSO outbound source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'dso';

	/**
	 * The declared `x-openregister-handoff` entry id on `dso_verzoek` (see
	 * lib/Settings/integriq_register.json).
	 *
	 * @var string
	 */
	public const HANDOFF_ID = 'verzoek-to-case';

	/**
	 * Recognised outbound message kinds.
	 *
	 * @var array<int, string>
	 */
	private const OUTBOUND_TYPES = ['status', 'besluit'];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/verzoek/message persistence.
	 * @param HandoffService $handoffService Executes the declared handoff under the caller's RBAC.
	 * @param DsoRequestTranslator $translator Translates a parsed Verzoek into normalised handoff fields.
	 * @param LogDsoConnectorProvider $logProvider The sandbox outbound provider binding.
	 * @param DsoClient $restProvider The generic REST outbound provider binding.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 * @param IJobList $jobList Queues the bijlage download after intake.
	 * @param DsoActivityMapper $activityMapper Maps the activiteiten to zaaktypen (REQ-DSO-010).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly HandoffService $handoffService,
		private readonly DsoRequestTranslator $translator,
		private readonly LogDsoConnectorProvider $logProvider,
		private readonly DsoClient $restProvider,
		private readonly LoggerInterface $logger,
		private readonly RawSourceResolver $rawSourceResolver,
		private readonly IJobList $jobList,
		private readonly DsoActivityMapper $activityMapper,
	) {

	}//end __construct()

	/**
	 * Persist one already-verified, already-parsed DSO Verzoek
	 * (`received`), then resolve + apply {@see DsoRequestTranslator}
	 * (`mapped`|`failed`, isolated to this verzoek).
	 *
	 * Runs inside `DsoConnection::runAs()`: every read and write here happens
	 * as the DSO connection's account, under its own RBAC. A repeated delivery
	 * of a verzoek that is already stored is matched by `verzoekId` (and
	 * `volgnummer`, when the payload has one): `mapped`, `failed` or
	 * `handed_off` answers with the stored record and writes nothing;
	 * `received` (a crash after the first save) is finished, not duplicated.
	 *
	 * @param array<string, mixed> $parsedRequest The {@see DSOParserService::parseRequest()} output.
	 * @param DsoIdentity|null     $identity      The account and connection the intake acts as;
	 *                                            recorded in `receivedVia` and handed to the bijlage job.
	 *
	 * @return ObjectEntity The persisted `dso_verzoek` record (any status).
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function ingest(array $parsedRequest, ?DsoIdentity $identity = null): ObjectEntity {
		$actingUserId = '';
		if ($identity !== null) {
			$actingUserId = $identity->account->getUID();
		}

		$existing = $this->findDelivered(parsedRequest: $parsedRequest);
		if ($existing !== null && ($existing->getObject()['status'] ?? null) !== 'received') {
			$this->logger->info(
				'[DsoIngestService] verzoek ' . (string)($parsedRequest['verzoekId'] ?? '') . ' was delivered before; nothing written',
				['uuid' => $existing->getUuid()]
			);
			return $existing;
		}

		$request = ($existing ?? $this->createReceived(parsedRequest: $parsedRequest, identity: $identity));

		try {
			$mapped = $this->translator->translate(request: $parsedRequest);
		} catch (DsoTranslationException $exception) {
			$this->logger->warning(
				'[DsoIngestService] translation failed for verzoek ' . $request->getUuid(),
				['exception' => $exception->getMessage()]
			);

			return $this->enqueueAttachmentFetch(
				request: $this->objectService->saveObject(
					object: array_merge(
						$request->getObject(),
						['status' => 'failed', 'errorDetail' => $exception->getMessage()]
					),
					register: self::REGISTER,
					schema: self::SCHEMA_VERZOEK,
					uuid: $request->getUuid()
				),
				actingUserId: $actingUserId
			);
		}

		$data = $request->getObject();
		$data['mappedTitle'] = $mapped['mappedTitle'];
		$data['mappedSummary'] = $mapped['mappedSummary'];
		$data['mappedChannel'] = $mapped['mappedChannel'];
		$data['mappedPriority'] = $mapped['mappedPriority'];
		$data['requester'] = $mapped['requester'];
		$data = array_merge(
			$data,
			$this->activityMapper->mapRequest(activiteiten: (array)($parsedRequest['activiteiten'] ?? []))
		);
		$data['status'] = 'mapped';

		return $this->enqueueAttachmentFetch(
			request: $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_VERZOEK,
				uuid: $request->getUuid()
			),
			actingUserId: $actingUserId
		);

	}//end ingest()

	/**
	 * Find a verzoek DSO-LV delivered before, under the acting account's rights.
	 *
	 * @param array<string, mixed> $parsedRequest The parsed verzoek.
	 *
	 * @return ObjectEntity|null The stored record, or null when this is a first delivery.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	private function findDelivered(array $parsedRequest): ?ObjectEntity {
		$verzoekId = (string)($parsedRequest['verzoekId'] ?? '');
		if ($verzoekId === '') {
			return null;
		}

		$filters = ['register' => self::REGISTER, 'schema' => self::SCHEMA_VERZOEK, 'verzoekId' => $verzoekId];
		$matches = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => 10]);
		$results = ($matches['results'] ?? $matches);

		$volgnummer = ($parsedRequest['volgnummer'] ?? null);
		foreach ($results as $candidate) {
			if ($candidate instanceof ObjectEntity === false) {
				continue;
			}

			$data = $candidate->getObject();
			if (($data['verzoekId'] ?? null) !== $verzoekId) {
				continue;
			}

			$storedVolgnummer = ($data['rawRequest']['volgnummer'] ?? null);
			if ($volgnummer !== null && $storedVolgnummer !== null && (string)$storedVolgnummer !== (string)$volgnummer) {
				continue;
			}

			return $candidate;
		}

		return null;

	}//end findDelivered()

	/**
	 * Store a first delivery as `received`.
	 *
	 * @param array<string, mixed> $parsedRequest The parsed verzoek.
	 * @param DsoIdentity|null     $identity      The account and connection the intake acts as.
	 *
	 * @return ObjectEntity The stored record.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	private function createReceived(array $parsedRequest, ?DsoIdentity $identity): ObjectEntity {
		$object = [
			'verzoekId' => (string)($parsedRequest['verzoekId'] ?? ''),
			'bronorganisatie' => (string)($parsedRequest['bronorganisatie'] ?? ''),
			'type' => (string)($parsedRequest['type'] ?? ''),
			'submissionDate' => (string)($parsedRequest['submissionDate'] ?? ''),
			'rawRequest' => $parsedRequest,
			'mappedTitle' => '',
			'mappedSummary' => '',
			'mappedChannel' => '',
			'mappedPriority' => '',
			'requester' => [],
			'status' => 'received',
			'errorDetail' => null,
			'correlationId' => '',
			'targetCase' => [],
			'receivedAt' => (new DateTime())->format('c'),
			'attachments' => $this->pendingAttachments(references: ($parsedRequest['bijlagen'] ?? [])),
		];

		if ($identity !== null) {
			$object['receivedVia'] = [
				'consumer' => $identity->consumerUuid,
				'account' => $identity->account->getUID(),
			];
		}

		return $this->objectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: self::SCHEMA_VERZOEK
		);

	}//end createReceived()

	/**
	 * Queue one {@see FetchDsoAttachmentsJob} for a request that has bijlagen.
	 *
	 * Queued after the last intake save, so the job never races the intake
	 * for the request object. The endpoint answers without waiting for it.
	 * A failure to queue is logged and leaves every entry `pending`.
	 *
	 * The job carries the uid the intake acted as, so it runs as the same
	 * account under cron (dso-intake-through-an-integriq-connection D5).
	 *
	 * @param ObjectEntity $request      The saved request.
	 * @param string       $actingUserId The uid the intake acted as.
	 *
	 * @return ObjectEntity The same request.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-the-endpoint-does-not-wait-for-the-bijlagen
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-3
	 */
	private function enqueueAttachmentFetch(ObjectEntity $request, string $actingUserId): ObjectEntity {
		if (empty($request->getObject()['attachments'] ?? []) === true) {
			return $request;
		}

		try {
			$this->jobList->add(
				FetchDsoAttachmentsJob::class,
				['requestUuid' => $request->getUuid(), 'actingUserId' => $actingUserId]
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[DsoIngestService] could not queue the bijlage download for verzoek ' . $request->getUuid(),
				['exception' => $exception->getMessage()]
			);
		}

		return $request;
	}//end enqueueAttachmentFetch()

	/**
	 * Turn the parser's bijlage references into `attachments` entries, each
	 * `pending` until {@see \OCA\Integriq\BackgroundJob\FetchDsoAttachmentsJob} has run.
	 *
	 * Names are made unique within the request ("tekening.pdf", then
	 * "tekening (2).pdf"), because every bijlage becomes a file in the same
	 * object folder and OpenRegister refuses a second file with the same name.
	 *
	 * @param mixed $references The {@see DSOParserService::parseRequest()} `bijlagen` list.
	 *
	 * @return array<int, array{name: string, url: string, status: string, attempts: int}> The entries.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/tasks.md#task-1.2
	 */
	private function pendingAttachments(mixed $references): array {
		if (is_array($references) === false) {
			return [];
		}

		$entries = [];
		$taken = [];
		foreach ($references as $reference) {
			if (is_array($reference) === false) {
				continue;
			}

			$entries[] = [
				'name' => $this->uniqueFileName(name: (string)($reference['name'] ?? ''), taken: $taken),
				'url' => (string)($reference['url'] ?? ''),
				'status' => 'pending',
				'attempts' => 0,
			];
		}

		return $entries;
	}//end pendingAttachments()

	/**
	 * Return a file name not yet in `$taken`, and add it there.
	 *
	 * @param string $name The wanted name.
	 * @param array<string, true> $taken The names already used, by reference.
	 *
	 * @return string The unique name.
	 */
	private function uniqueFileName(string $name, array &$taken): string {
		$candidate = $name;
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$stem = $name;
		if ($extension !== '') {
			$stem = substr($name, 0, -(strlen($extension) + 1));
			$extension = '.' . $extension;
		}

		$counter = 1;
		while (isset($taken[strtolower($candidate)]) === true) {
			$counter++;
			$candidate = $stem . ' (' . $counter . ')' . $extension;
		}

		$taken[strtolower($candidate)] = true;

		return $candidate;
	}//end uniqueFileName()

	/**
	 * Read one dso_verzoek's current state.
	 *
	 * @param string $uuid The `dso_verzoek` uuid.
	 *
	 * @return array<string, mixed> The verzoek's object data, plus `id`.
	 *
	 * @throws DsoTranslationException When no verzoek exists for the uuid.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
	 */
	public function getRequest(string $uuid): array {
		$request = $this->objectService->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA_VERZOEK);
		if ($request instanceof ObjectEntity === false) {
			throw new DsoTranslationException(message: 'No dso_verzoek found for uuid "' . $uuid . '".');
		}

		return ($request->getObject() + ['id' => $request->getUuid()]);
	}//end getRequest()

	/**
	 * List dso_verzoek records, optionally filtered by status (e.g. `mapped`
	 * — the set eligible for the handoff-trigger endpoint).
	 *
	 * @param string|null $status Optional status filter.
	 * @param integer $limit Maximum number of records to return.
	 *
	 * @return array<int, array<string, mixed>> The matching verzoeken (each with `id`).
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-rest-surface-to-list-and-complete-mapped-verzoeken-req-004
	 */
	public function listVerzoeken(?string $status = null, int $limit = 100): array {
		$filters = ['register' => self::REGISTER, 'schema' => self::SCHEMA_VERZOEK];
		if ($status !== null) {
			$filters['status'] = $status;
		}

		$matches = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => $limit]);
		$results = ($matches['results'] ?? $matches);

		$list = [];
		foreach ($results as $entity) {
			$list[] = ($entity->getObject() + ['id' => $entity->getUuid()]);
		}

		return $list;
	}//end listVerzoeken()

	/**
	 * Execute the declared `verzoek-to-case` handoff for a `mapped`
	 * verzoek, as the calling (real, authenticated) user — never a
	 * system-account shortcut (design.md §1).
	 *
	 * @param string $uuid The `dso_verzoek` uuid.
	 *
	 * @return array<string, mixed> The engine's `execute()` result (`{status, target, correlationId}`
	 *                              or `{status: parked, queueEntry}`).
	 *
	 * @throws DsoTranslationException When the verzoek is unknown or not yet `mapped`.
	 *
	 * Also propagates OpenRegister's own `Handoff\HandoffException` (not-declared /
	 * provider-unavailable) and `NotAuthorizedException` (RBAC refusal) unchanged —
	 * omitted from the @throws tag because PHPStan cannot resolve cross-app
	 * OCA\OpenRegister\Exception\* types as Throwable subtypes (same limitation
	 * documented in phpstan.neon's `unknown class OCA\\OpenRegister\\` ignores).
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
	 */
	public function handoff(string $uuid): array {
		$request = $this->objectService->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA_VERZOEK);
		if ($request instanceof ObjectEntity === false) {
			throw new DsoTranslationException(message: 'No dso_verzoek found for uuid "' . $uuid . '".');
		}

		$data = $request->getObject();
		if (($data['status'] ?? null) !== 'mapped') {
			throw new DsoTranslationException(
				message: 'Verzoek "' . $uuid . '" is not in "mapped" status (currently "'
				. (string)($data['status'] ?? 'unknown') . '") — a handoff can only be triggered once mapping succeeded.'
			);
		}

		try {
			$result = $this->handoffService->execute(
				register: self::REGISTER,
				schema: self::SCHEMA_VERZOEK,
				id: $uuid,
				handoffId: self::HANDOFF_ID
			);
		} catch (Throwable $exception) {
			$this->markFailed(request: $request, message: $exception->getMessage());
			throw $exception;
		}

		if (($result['status'] ?? null) === 'executed') {
			$this->recordHandoffSuccess(request: $request, result: $result);
		}

		return $result;
	}//end handoff()

	/**
	 * Build and dispatch one outbound `status` (voortgangsinformatie) or
	 * `besluit` message for a previously received verzoek, persisting a
	 * `dso_message` audit row regardless of outcome.
	 *
	 * @param string $requestUuid The `dso_verzoek` uuid this message concerns.
	 * @param string $type The message kind: `status` or `besluit`.
	 * @param array<string, mixed> $fields Type-specific fields (`status` for `type=status`;
	 *                                     `besluit`/`gemotiveerd` for `type=besluit`).
	 *
	 * @return array{ref: string, type: string, status: string} The dispatch outcome.
	 *
	 * @throws DsoTranslationException When the verzoek is unknown, or `type` is not recognised.
	 * @throws DsoProviderException When no active DSO source is configured, or the transport
	 *                              fails (a `status: failed` `dso_message` IS persisted first).
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	public function postOutbound(string $requestUuid, string $type, array $fields = []): array {
		if (in_array($type, self::OUTBOUND_TYPES, true) === false) {
			throw new DsoTranslationException(
				message: 'Unknown outbound DSO message type "' . $type . '" (must be `status` or `besluit`).'
			);
		}

		$request = $this->objectService->find(id: $requestUuid, register: self::REGISTER, schema: self::SCHEMA_VERZOEK);
		if ($request instanceof ObjectEntity === false) {
			throw new DsoTranslationException(message: 'No dso_verzoek found for uuid "' . $requestUuid . '".');
		}

		$requestId = (string)($request->getObject()['verzoekId'] ?? '');
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$payload = array_merge(['verzoekId' => $requestId, 'timestamp' => (new DateTime())->format('c')], $fields);

		$status = 'sent';
		$error = null;
		$ref = '';
		try {
			$ref = $provider->send(
				sourceConfiguration: $configuration,
				requestId: $requestId,
				type: $type,
				payload: $payload
			);
		} catch (DsoProviderException $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$this->objectService->saveObject(
			object: [
				'ref' => $ref,
				'type' => $type,
				'status' => $status,
				'payload' => $payload,
				'verzoekUuid' => $requestUuid,
				'error' => $error,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE
		);

		if ($status === 'failed') {
			throw new DsoProviderException(message: (string)$error);
		}

		return ['ref' => $ref, 'type' => $type, 'status' => $status];
	}//end postOutbound()

	/**
	 * Resolve the single active `dso` outbound source
	 * (`type=dso`, `isEnabled=true`), under the active user's rights.
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws DsoProviderException When no active source is configured.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	public function resolveActiveSource(): ObjectEntity {
		$matches = $this->objectService->findAll(config: $this->activeSourceQuery());
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			throw $this->noActiveSource();
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()

	/**
	 * Resolve the active `dso` source as an engine read.
	 *
	 * The source is admin-only configuration (`99-source-lockdown.json`). The
	 * bijlage job runs as the DSO connection's account, which need not be an
	 * admin, so it reads the source as the engine: `_rbac: false` and
	 * `_render: false`, a read only. It mirrors RawSourceResolver, without the
	 * active user's RBAC.
	 *
	 * @return ObjectEntity The resolved source, read raw.
	 *
	 * @throws DsoProviderException When no active source is configured.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function resolveActiveSourceAsEngine(): ObjectEntity {
		$matches = $this->objectService->findAll(config: $this->activeSourceQuery(), _rbac: false, _multitenancy: false);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			throw $this->noActiveSource();
		}

		$uuid = (string)$results[0]->getUuid();
		if ($uuid === '') {
			return $results[0];
		}

		$raw = $this->objectService->find(
			id: $uuid,
			register: self::REGISTER,
			schema: self::SCHEMA_SOURCE,
			_rbac: false,
			_multitenancy: false,
			_render: false
		);
		if ($raw instanceof ObjectEntity === false) {
			return $results[0];
		}

		return $raw;
	}//end resolveActiveSourceAsEngine()

	/**
	 * The query that locates the active DSO source.
	 *
	 * @return array<string, mixed> The findAll() config.
	 */
	private function activeSourceQuery(): array {
		return [
			'filters' => [
				'register' => self::REGISTER,
				'schema' => self::SCHEMA_SOURCE,
				'type' => self::SOURCE_TYPE,
				'isEnabled' => true,
			],
			'limit' => 1,
		];
	}//end activeSourceQuery()

	/**
	 * The error for a missing active DSO source.
	 *
	 * @return DsoProviderException The exception.
	 */
	private function noActiveSource(): DsoProviderException {
		return new DsoProviderException(
			message: 'No active DSO source is configured (register "openconnector", '
			. 'schema "source", type "dso", isEnabled=true).'
		);
	}//end noActiveSource()

	/**
	 * Resolve the outbound provider binding named by
	 * `configuration.provider` (`log`|`rest`), defaulting to the sandbox
	 * `log` provider when unset or unrecognised — new/unconfigured
	 * deployments never accidentally dispatch a live DSO-LV call.
	 *
	 * @param array<string, mixed> $configuration The `dso` source's `configuration` object.
	 *
	 * @return DsoConnectorProviderInterface The resolved provider.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): DsoConnectorProviderInterface {
		$providerId = (string)($configuration['provider'] ?? 'log');
		if ($providerId === 'rest') {
			return $this->restProvider;
		}

		return $this->logProvider;
	}//end resolveProvider()

	/**
	 * Best-effort persist the handoff's target/correlation metadata onto the
	 * verzoek (`status` itself was already set by the engine's own
	 * `onSuccess.set`).
	 *
	 * @param ObjectEntity $request The (pre-handoff) verzoek object.
	 * @param array<string, mixed> $result The engine's `execute()` result (`status: executed`).
	 *
	 * @return void
	 */
	private function recordHandoffSuccess(ObjectEntity $request, array $result): void {
		$target = (array)($result['target'] ?? []);
		$correlationId = (string)($result['correlationId'] ?? '');

		$current = $this->objectService->find(id: $request->getUuid(), register: self::REGISTER, schema: self::SCHEMA_VERZOEK);
		$data = $request->getObject();
		if ($current instanceof ObjectEntity === true) {
			$data = $current->getObject();
		}

		$data = array_merge($data, ['targetCase' => $target, 'correlationId' => $correlationId]);

		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_VERZOEK,
			uuid: $request->getUuid()
		);

	}//end recordHandoffSuccess()

	/**
	 * Mark a verzoek `failed` after a handoff execution error — isolated to
	 * this verzoek, never thrown past this method (the original exception
	 * is rethrown by the caller separately).
	 *
	 * @param ObjectEntity $request The verzoek being handed off.
	 * @param string $message The failure detail.
	 *
	 * @return void
	 */
	private function markFailed(ObjectEntity $request, string $message): void {
		$this->objectService->saveObject(
			object: array_merge($request->getObject(), ['status' => 'failed', 'errorDetail' => $message]),
			register: self::REGISTER,
			schema: self::SCHEMA_VERZOEK,
			uuid: $request->getUuid()
		);

	}//end markFailed()
}//end class
