<?php

/**
 * Integriq Synchronization Service.
 *
 * Service for handling synchronization operations between internal and external
 * data sources. Provides functionality for mapping, transforming, and synchronizing
 * data with support for asynchronous file fetching using ReactPHP for improved
 * performance.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Service;

use Adbar\Dot;
use DateTime;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Each;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use JWadhams\JsonLogic;
use OCA\Integriq\EventListener\SourceOwnedDeleteGuardListener;
use OCA\Integriq\Event\SynchronizationDeletionGuardedEvent;
use OCA\Integriq\Exception\FormsFeatureDisabledException;
use OCA\Integriq\Exception\MessageValidationRefusedException;
use OCA\Integriq\Exception\ResponseDecodeException;
use OCA\Integriq\Exception\TablesFeatureDisabledException;
use OCA\Integriq\Exception\TargetWriteRefusedException;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\ZgwDocumentDelivery;
use OCA\Integriq\Service\StufZkn\StufZdsDocumentDelivery;
use OCA\Integriq\Service\Synchronization\ApiFetch;
use OCA\Integriq\Service\Synchronization\ChangeSetBuilder;
use OCA\Integriq\Service\Synchronization\ContractRun;
use OCA\Integriq\Service\Synchronization\ExternSyncRun;
use OCA\Integriq\Service\Synchronization\ExtraDataFetch;
use OCA\Integriq\Service\Synchronization\FetchedFile;
use OCA\Integriq\Service\Synchronization\SourcePage;
use OCA\Integriq\Service\Synchronization\OpenRegisterTargetWrite;
use OCA\Integriq\Service\Synchronization\OutcomeWriteBack;
use OCA\Integriq\Service\Synchronization\PagedFetch;
use OCA\Integriq\Service\Synchronization\ItemRun;
use OCA\Integriq\Service\MessageValidation\SynchronizationMessageGate;
use OCA\Integriq\Service\Synchronization\RunPrerequisiteGuard;
use OCA\Integriq\Service\Synchronization\TargetWrite;
use OCA\Integriq\Service\Synchronization\DeletionRun;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Ownership\DisappearanceApplier;
use OCA\Integriq\Service\Ownership\DisappearancePolicy;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\Tables\TablesSyncAdapter;
use OCA\Integriq\Util\SafeXmlParser;
use OCA\OpenRegister\Db\Mapping as OrMapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\IAppConfig;
use OCP\Lock\LockedException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Service for handling synchronization operations between internal and external data sources.
 * Provides functionality for mapping, transforming, and synchronizing data with support for
 * asynchronous file fetching using ReactPHP for improved performance.
 *
 * @category  Service
 * @package   OCA\Integriq\Service
 * @author    Conduction b.v.
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/integriq
 *
 * @SuppressWarnings(PHPMD.TooManyFields) 21 against a threshold of 15. Every field is
 *   an injected collaborator — this class is the synchronization ENGINE, and the
 *   count is the number of subsystems a sync legitimately touches (sources, mappings,
 *   contracts, rules, targets, files, logging, events). Grouping them behind facades
 *   purely to reach 15 would hide the dependency surface rather than reduce it. The
 *   real reduction is splitting the engine, which is a design change and not a lint fix.
 */
class SynchronizationService implements SynchronizationServiceInterface {

	/**
	 * Total pages the last fetched page advertised via its `Link` header.
	 *
	 * Null when the source does not use RFC 5988 pagination, which is the
	 * previous behaviour: page until a page comes back empty.
	 *
	 * @var int|null
	 */
	private ?int $lastPageFromLink = null;

	/**
	 * Call logs for pages fetched ahead of the loop, keyed by page number.
	 *
	 * @var array<int, mixed>
	 */
	private array $pagePrefetch = [];

	/**
	 * Source objects already resolved during this request, keyed by identifier.
	 *
	 * A source is resolved from OpenRegister on EVERY call, and a paginated fetch
	 * makes one call per page — so a 199-page crawl re-read the same row 199
	 * times. Measured 2026-08-14 at ~11 ms a page against a source answering in
	 * 5.3 ms: twice the cost of the request it was preparing.
	 *
	 * SAFE ONLY BECAUSE `CallService::sourceRateLimit()` NOW UPDATES THE ENTITY
	 * IT MUTATES. It persists the decremented `rateLimitRemaining` to the
	 * database, and used to leave the in-memory object holding the old value —
	 * so the per-call re-read was how that counter advanced. Caching without
	 * that fix is 11 ms faster and stops rate limiting from ever tripping, which
	 * is the failure you would not notice until a source banned you.
	 *
	 * Request-scoped, so it cannot outlive the run and go stale against an edited
	 * source: the service is built per request, and a cron pass gets a fresh one.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $resolvedSourceObjects = [];

	/**
	 * Number of buffered target rows that forces an intermediate flush.
	 *
	 * A run's whole object list is processed in one loop, so without a ceiling
	 * the buffer would hold every mapped object of a several-hundred-thousand
	 * row sync in memory before the first write. Flushing at this size bounds
	 * peak memory while keeping batches large enough for the bulk path to be
	 * worth taking.
	 *
	 * @var int
	 */
	private const WRITE_BUFFER_FLUSH_SIZE = 500;

	/**
	 * The write-back states a push records on its local object (zgw-connectors-for-dossiq D4).
	 *
	 * @var string
	 */
	private const WRITE_BACK_CONFLICT = 'conflict';

	private const WRITE_BACK_SYNCED = 'synced';

	/**
	 * How many contracts a run will inline into `_embed.contracts`.
	 *
	 * `_embed` is a convenience for reading ONE run's contracts inline. Past a
	 * few hundred it stops being convenient and starts being the most expensive
	 * thing the synchronization does — every contract re-read, re-serialised,
	 * held in memory twice and json-encoded onto the log row.
	 *
	 * Five hundred is comfortably above any run a person reads by hand and far
	 * below the size where the cost matters. Overridable per source with
	 * `maxEmbeddedContracts`; 0 disables the cap.
	 *
	 * @var int
	 */
	private const MAX_EMBEDDED_CONTRACTS = 500;

	/**
	 * Namespace for deriving a target object's uuid from its source identity.
	 *
	 * A buffered write has to know the target uuid BEFORE the row is written, so
	 * the contract can be built against it. Deriving that uuid deterministically
	 * from (synchronization, originId) rather than drawing a random one makes the
	 * write idempotent, which is what closes the only window this batching opens.
	 *
	 * Without it, a run interrupted between the object flush and the contract
	 * flush leaves objects written with no mapping — the state that makes the
	 * next run treat those source records as new and create a SECOND copy of
	 * each. Per-record writes had the same hole but only ever one record wide;
	 * batching would have widened it to a whole flush.
	 *
	 * With a derived uuid there is no hole at all: the next run computes the same
	 * uuid for the same source record, and OpenRegister's uuid-keyed upsert
	 * updates the row that is already there. That is strictly better than the
	 * per-record behaviour it replaces, not merely as good.
	 *
	 * @var string
	 */
	private const TARGET_UUID_NAMESPACE = '6f9a1f8e-4a2b-5c3d-9e7f-1b2c3d4e5f60';

	/**
	 * How many source pages may be in flight, and therefore held in memory, at once.
	 *
	 * The fan-out originally issued a promise for EVERY remaining page in one go.
	 * On the four-page benchmark it was written against that is three requests;
	 * on the large syncs it exists for it is not. A five-hundred-page source
	 * would have opened 499 concurrent connections and buffered every response
	 * body simultaneously — around 275 MB at GitHub's ~550 KB per page — which
	 * is a rate-limit, a connection-exhaustion and an out-of-memory failure at
	 * the same time, and only on the inputs the feature was for.
	 *
	 * Bounded, the fan-out fetches a window, the loop consumes it, and the next
	 * window goes out when the buffer empties. Concurrency and peak memory are
	 * then a function of this constant rather than of the source's size.
	 *
	 * Overridable per synchronization via `sourceConfig.prefetchConcurrency`,
	 * because the right number depends on what the source tolerates.
	 *
	 * @var int
	 */
	private const PREFETCH_WINDOW = 10;

	/**
	 * Query-string keys a source might use to express its page size.
	 *
	 * Needed to turn "how many objects do we expect" into "how many pages is
	 * that". There is no standard spelling, so the common ones are recognised
	 * and `sourceConfig.pageSize` overrides all of them. An unrecognised source
	 * simply yields no prediction and keeps the previous behaviour.
	 *
	 * @var array<int, string>
	 */
	private const PAGE_SIZE_KEYS = [
		'per_page',
		'perPage',
		'pageSize',
		'page_size',
		'limit',
		'count',
		'top',
		'$top',
		// CKAN (data.overheid.nl, and every other CKAN portal) names its page
		// size `rows` and its cursor `start`. Both are offsets, not indexes —
		// see self::paginationValueFor().
		'rows',
	];

	/**
	 * Pages this run expects to fetch, predicted before the first request.
	 *
	 * Null when nothing could be predicted, in which case the engine falls back
	 * to learning the count from page one's `Link` header as before.
	 *
	 * @var int|null
	 */
	private ?int $predictedLastPage = null;

	/**
	 * Whether target/contract writes are being buffered for a bulk flush.
	 *
	 * Only the batch loop in synchronizeExternToIntern() turns this on, and it
	 * always turns it off again in a `finally`. Every other entry point —
	 * a standalone updateTarget() call, the single-object and delete paths —
	 * writes inline exactly as before, because there is no loop after which a
	 * buffer would be flushed and a buffered write would simply be lost.
	 *
	 * @var bool
	 */
	private bool $bufferWrites = false;

	/**
	 * Mapped target rows awaiting a bulk write, grouped by "register/schema".
	 *
	 * Each group is `['register' => ?, 'schema' => ?, 'validation' => bool,
	 * 'events' => bool, 'audit' => bool, 'rows' => array<string, array>]`, with
	 * rows keyed by the target uuid so a source that yields the same object
	 * twice in one run collapses to one write rather than racing itself.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $targetWriteBuffer = [];

	/**
	 * Contract payloads awaiting persistence, in the order they were produced.
	 *
	 * Flushed AFTER the target buffer — see flushWriteBuffers() for why that
	 * ordering is the whole point of buffering both sides together.
	 *
	 * @var array<int, array>
	 */
	private array $contractWriteBuffer = [];

	/**
	 * Whether the most recent updateTarget() call buffered its write.
	 *
	 * Set by updateTargetOpenRegister() and read by synchronizeContract()
	 * immediately afterwards, so the contract for a buffered target is buffered
	 * too. A contract may only be deferred when its object is also deferred:
	 * persisting it while the object is still in the buffer would leave a
	 * mapping pointing at a row that does not exist yet.
	 *
	 * @var bool
	 */
	private bool $lastTargetWriteBuffered = false;

	/**
	 * Milliseconds spent inside flushWriteBuffers() during the current run.
	 *
	 * Reported alongside `process_objects` so the stage separates the engine's
	 * per-record work from the database writes it batches. Without the split, a
	 * stage timing cannot say whether the next optimisation belongs in the
	 * mapping path or in the storage layer.
	 *
	 * @var float
	 */
	private float $writeFlushMs = 0.0;

	/**
	 * The synchronizations currently on the chaining call stack.
	 *
	 * Integriq chains synchronizations two ways — a `synchronization` rule
	 * and a `followUps` entry — and both re-enter `synchronize()` on this same
	 * service. Neither had any cycle or depth guard, so A -> B -> A recursed
	 * until the process died. One shared stack guards both, because a cycle can
	 * be formed out of either kind of hop, or a mix of the two.
	 *
	 * Static because the recursion runs through the container's single shared
	 * instance, so a per-call variable would not see the outer frame. Entries
	 * are pushed before the nested run and popped in a `finally`, so a failed
	 * run does not leave the chain permanently blocked.
	 *
	 * @var array<int, string>
	 */
	private static array $syncChainStack = [];

	/**
	 * Retention period in milliseconds for error synchronization logs.
	 *
	 * @var integer
	 */
	private int $errorRetention;

	/**
	 * Retention period in milliseconds for error synchronization contract logs.
	 *
	 * @var integer
	 */
	private int $errorContractRetention;

	/**
	 * Retention period in milliseconds for successful synchronization logs.
	 *
	 * @var integer
	 */
	private int $successRetention;

	public const EXTRA_DATA_CONFIGS_LOCATION = 'extraDataConfigs';
	public const EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION = 'dynamicEndpointLocation';
	public const EXTRA_DATA_STATIC_ENDPOINT_LOCATION = 'staticEndpoint';
	public const EXTRA_DATA_ENDPOINT_TEMPLATE_LOCATION = 'endpointTemplate';
	public const KEY_FOR_EXTRA_DATA_LOCATION = 'keyToSetExtraData';
	public const MERGE_EXTRA_DATA_OBJECT_LOCATION = 'mergeExtraData';
	public const UNSET_CONFIG_KEY_LOCATION = 'unsetConfigKey';
	public const EXTRA_DATA_BEFORE_CONDITIONS_LOCATION = 'fetchExtraDataBeforeConditions';
	public const EXTEND_BEFORE_CONDITIONS_LOCATION = 'extendInputBeforeConditions';
	public const EXTEND_BEFORE_CONDITIONS_FETCH_OBJECT = 'extendInputFetchObjectBeforeConditions';
	public const FILE_TAG_TYPE = 'files';
	public const VALID_MUTATION_TYPES = ['create', 'update', 'delete'];
	public const DEFAULT_MAX_PAGES = 50;

	/**
	 * Records one run of a `format: "jsonl"` synchronization reads at most.
	 *
	 * A bulk JSONL export can hold hundreds of thousands of records (Austria's
	 * OpenTender `full.jsonl.gz` is 181,741 lines, 451 MB decompressed), and
	 * the engine holds a run's records in memory before it processes them.
	 * Reading a fixed-size batch per run bounds that memory; the next run
	 * resumes at the next batch through `currentPage`. Overridable per
	 * synchronization with `sourceConfig.pageSize`.
	 *
	 * @var int
	 */
	public const DEFAULT_JSONL_BATCH_SIZE = 5000;

	/**
	 * Percentage of PHP's memory limit a fetch may reach before it stops.
	 *
	 * Eighty leaves room for the item loop, the write buffers and the run log
	 * that all come AFTER the fetch — stopping at 95% would trade a fatal during
	 * fetching for a fatal during processing, which is the same outage with a
	 * later timestamp.
	 *
	 * Overridable per source with `maxMemoryPercent`; 0 disables the ceiling.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_MEMORY_PERCENT = 80;

	/**
	 * The most pages one synchronization may have in flight at once.
	 *
	 * Every page in the window is an open HTTP connection held by a single
	 * apache worker, so this is a bound on what ONE synchronization can take
	 * from the container and from the upstream.
	 *
	 * Twenty is generous rather than tight: measured 2026-08-14, throughput
	 * PEAKS around a window of 5-10 and falls away above it. Nothing above this
	 * has been observed to help, on any source tested.
	 *
	 * @var int
	 */
	public const MAX_PREFETCH_WINDOW = 20;

	/**
	 * What started a purge, as the contract log names it (REQ-SDP-003): a
	 * complete full run that no longer saw the record.
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-every-purge-is-recorded-and-a-refused-purge-stays-visible-req-sdp-003
	 */
	public const PURGE_TRIGGER_FULL_RUN = 'fullRun';

	/**
	 * What started a purge: a destruction notice from the source.
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-every-purge-is-recorded-and-a-refused-purge-stays-visible-req-sdp-003
	 */
	public const PURGE_TRIGGER_NOTICE = 'destructionNotice';

	/**
	 * Default number of a single object's file downloads allowed in flight at once.
	 *
	 * A POLITENESS default, not a memory one. Nothing about PHP binds at this
	 * number: each fetch streams to a temp file, so its memory cost is curl
	 * buffers and headers (tens of KB against a 512 M limit), and it costs 2 file
	 * descriptors (against a measured `ulimit -n` of 1024). The constraint is the
	 * upstream — the Woo sources include a demo zaaksysteem plus government
	 * endpoints (TenderNed, Diavgeia, KvK) — and, because saves stay serialized,
	 * the sum of the saves.
	 *
	 * Overridable per source via `configuration.maxConcurrentFetches`.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	private const FETCH_CONCURRENCY_DEFAULT = 5;

	/**
	 * Hard ceiling on the per-source file-fetch concurrency.
	 *
	 * A source asking for more than this is clamped and the clamp is logged, so a
	 * misconfiguration cannot turn one object's attachments into an unbounded
	 * burst against an upstream.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	private const FETCH_CONCURRENCY_MAX = 20;

	/**
	 * Default total in-flight byte budget for one object's concurrent downloads
	 * (~256 MB).
	 *
	 * A count cap alone is the wrong unit: ten 5 MB attachments are trivial where
	 * ten 2 GB exports are not. Derived from `Content-Length` where the source
	 * sends it; a source that omits the header is gated by count alone.
	 *
	 * Overridable per source via `configuration.maxInFlightFetchBytes`; 0
	 * disables the budget and leaves count-only gating.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	private const FETCH_BYTE_BUDGET_DEFAULT = 268435456;

	/**
	 * Default per-file ceiling for a fetched file, in bytes. 0 = no ceiling.
	 *
	 * Overridable per source via `configuration.maxFileSize`. Deliberately OFF
	 * by default: downloads stream to a temp file, so an oversized file costs
	 * disk and time rather than memory, and a default cap would silently drop
	 * legitimate large documents from existing syncs.
	 *
	 * @var integer
	 */
	private const FETCH_MAX_FILE_SIZE_DEFAULT = 0;

	/**
	 * The OpenRegister-backed synchronization run-log write service.
	 *
	 * Post OpenRegister-cutover the SynchronizationLog entity + its QBMapper were
	 * deleted; the run-log is now persisted to OpenRegister (schema
	 * `synchronization_log`) through this service.
	 *
	 * @var SynchronizationLogService
	 */
	private readonly SynchronizationLogService $synchronizationLogService;

	/**
	 * Live progress recorder for the current run.
	 *
	 * Nullable and NOT readonly: it is resolved in the constructor body behind a
	 * container guard, so a bare container (the unit suite's default) simply
	 * leaves progress recording off rather than failing. Every call site must
	 * therefore use `?->` — a run must never fail because nobody was watching.
	 *
	 * @var SynchronizationRunProgressService|null
	 */
	private ?SynchronizationRunProgressService $runProgressService = null;

	/**
	 * Files successfully fetched and saved since the last tally reset.
	 *
	 * @var int
	 */
	private int $fileFetchSucceeded = 0;

	/**
	 * Files whose fetch or save failed since the last tally reset.
	 *
	 * @var int
	 */
	private int $fileFetchFailed = 0;

	// Contracts are now read/written directly through the OpenRegister
	// ObjectService — the legacy SynchronizationContractMapper container
	// lookup is dropped in W5. See findContract*() / persistContract() /
	// contractsByOriginId() helpers below.

	/**
	 * The OpenRegister-backed synchronization contract log write service.
	 *
	 * Replaces the legacy SynchronizationContractLogMapper container lookup
	 * dropped in W5. The service is resolved lazily from the PSR-11 container
	 * so the public constructor signature stays unchanged and the unit suite
	 * (which mocks the container as a bare stub) keeps working — call sites
	 * are guarded against a null resolution exactly like the legacy mapper.
	 *
	 * @var SynchronizationContractLogService|null
	 */
	private ?SynchronizationContractLogService $synchronizationContractLogService = null;

	/**
	 * The OpenRegister-backed synchronization contract lifecycle service.
	 *
	 * Extracted from SynchronizationService in W14 Tier 2 — encapsulates all
	 * read/write operations against the `synchronization_contract` schema so
	 * the engine no longer interleaves OR persistence with sync orchestration.
	 * Resolved lazily from the container so the public constructor signature
	 * stays unchanged; call sites fall back to the in-class private helpers
	 * when the container returns null (unit-suite safety net).
	 *
	 * @var SynchronizationContractService|null
	 */
	private ?SynchronizationContractService $synchronizationContractService = null;

	/**
	 * The dead-letter capture service for per-item sync failures.
	 *
	 * Resolved lazily from the container — mirrors
	 * {@see $synchronizationContractLogService}/{@see $synchronizationContractService}
	 * — to avoid a constructor cycle: {@see SyncItemDeadLetterService} itself
	 * resolves THIS class lazily (for replay) so the two services cannot be
	 * constructor-injected into each other.
	 *
	 * @var SyncItemDeadLetterService|null
	 */
	private ?SyncItemDeadLetterService $syncItemDeadLetterService = null;

	/**
	 * The Nextcloud domain event dispatcher, used to dispatch
	 * SynchronizationDeletionGuardedEvent when a cleanup pass is guarded.
	 *
	 * Resolved lazily from the container so the public constructor signature
	 * stays unchanged (see the constructor docblock) — call sites are
	 * guarded against a null resolution exactly like the other lazily
	 * resolved dependencies above.
	 *
	 * @var IEventDispatcher|null
	 */
	private ?IEventDispatcher $eventDispatcher = null;

	/**
	 * Refuses a run whose declared prerequisites are not set (connectors-course-marketplace Task 4).
	 *
	 * @var RunPrerequisiteGuard|null
	 */
	private ?RunPrerequisiteGuard $prerequisiteGuard = null;

	/**
	 * Checks source objects and target bodies against their message schemas
	 * (mapping-message-schema-validation REQ-MSV-003).
	 *
	 * Resolved on first use by {@see messageGate()}, not in the constructor,
	 * which is already at its complexity ceiling.
	 *
	 * @var SynchronizationMessageGate|null
	 */
	private ?SynchronizationMessageGate $messageGate = null;

	/**
	 * Fills a push's `writeBack` templates (REQ-CSD-001); made on first use.
	 *
	 * @var OutcomeWriteBack|null
	 */
	private ?OutcomeWriteBack $outcomeWriteBack = null;

	/**
	 * Whether {@see $messageGate} has been looked up in the container.
	 *
	 * @var bool
	 */
	private bool $messageGateResolved = false;

	/**
	 * Record-mode findings from target writes, taken into the run's result by
	 * the item loop. The target write sits below the item and has no handle on
	 * the result, so the loop collects what it left here.
	 *
	 * @var list<array>
	 */
	private array $pendingValidationFindings = [];

	/**
	 * Constructor.
	 *
	 * Post OpenRegister-cutover, synchronizations are resolved through the
	 * OpenRegister object service (register `openconnector`, schema
	 * `synchronization`); the legacy SynchronizationMapper was removed. The
	 * surviving QBMappers (sources, mappings, rules, contract/log) are resolved
	 * from the container so the public constructor stays aligned with the
	 * OpenRegister-based wiring.
	 *
	 * @param CallService $callService The call service.
	 * @param MappingService $mappingService The mapping service.
	 * @param ContainerInterface $containerInterface The PSR-11 container.
	 * @param OrObjectService $orObjectService The OpenRegister object service.
	 * @param ObjectService $objectService The Integriq object service.
	 * @param LoggerInterface $logger The logger.
	 * @param SynchronizationLogService $synchronizationLogService The OpenRegister-backed run-log write service.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param SynchronizationApprovalGate $approvalGate HITL batch-approval gate: the approval_request that pauses a gated run.
	 * @param TablesSyncAdapter $tablesSyncAdapter The `nextcloud-table` source/target adapter (tables-bridge).
	 * @param FormsSyncAdapter $formsSyncAdapter The `nextcloud-form` source adapter (nextcloud-forms-connector).
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly MappingService $mappingService,
		private readonly ContainerInterface $containerInterface,
		private readonly OrObjectService $orObjectService,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		SynchronizationLogService $synchronizationLogService,
		IAppConfig $appConfig,
		private readonly SynchronizationApprovalGate $approvalGate,
		private readonly ?TablesSyncAdapter $tablesSyncAdapter = null,
		private readonly ?FormsSyncAdapter $formsSyncAdapter = null,
	) {
		$this->synchronizationLogService = $synchronizationLogService;

		// Resolve the surviving QBMappers from the container so the constructor
		// signature mirrors the OpenRegister-based wiring (and the unit suite).
		// Guarded against a bare container mock that returns null in tests which
		// only exercise the OpenRegister-backed paths.
		$synchronizationContractLogService = $this->containerInterface->get(SynchronizationContractLogService::class);
		if ($synchronizationContractLogService instanceof SynchronizationContractLogService) {
			$this->synchronizationContractLogService = $synchronizationContractLogService;
		}

		$synchronizationContractService = $this->containerInterface->get(SynchronizationContractService::class);
		if ($synchronizationContractService instanceof SynchronizationContractService) {
			$this->synchronizationContractService = $synchronizationContractService;
		}

		$syncItemDeadLetterService = $this->containerInterface->get(SyncItemDeadLetterService::class);
		if ($syncItemDeadLetterService instanceof SyncItemDeadLetterService) {
			$this->syncItemDeadLetterService = $syncItemDeadLetterService;
		}

		$eventDispatcher = $this->containerInterface->get(IEventDispatcher::class);
		if ($eventDispatcher instanceof IEventDispatcher) {
			$this->eventDispatcher = $eventDispatcher;
		}

		// Resolved in the BODY, not added to the signature: 84 tests were once
		// pinned to this constructor's shape, and a run-progress recorder is not
		// worth breaking them for. The null-guard is what lets a bare container
		// mock (the unit suite's default) leave progress recording switched off
		// rather than fatal.
		$runProgressService = $this->containerInterface->get(SynchronizationRunProgressService::class);
		if ($runProgressService instanceof SynchronizationRunProgressService) {
			$this->runProgressService = $runProgressService;
		}

		// Resolved in the body for the same reason as the progress recorder: a
		// bare container mock leaves the guard off rather than fatal.
		$prerequisiteGuard = $this->containerInterface->get(RunPrerequisiteGuard::class);
		if ($prerequisiteGuard instanceof RunPrerequisiteGuard) {
			$this->prerequisiteGuard = $prerequisiteGuard;
		}

		// Fall back to the defaults the settings read reports, from one table,
		// so an unset key means the 30 days both log schemas declare, for
		// synchronization and contract logs alike (integriq#2210).
		if ($appConfig->hasKey(app: 'integriq', key: 'retention') === true) {
			$retention = json_decode($appConfig->getValueString(app: 'integriq', key: 'retention'), true);

			$this->errorRetention = ($retention['syncLogRetention'] ?? RetentionDefaults::SYNC_LOG);
			$this->errorContractRetention = ($retention['syncContractLogRetention'] ?? RetentionDefaults::SYNC_CONTRACT_LOG);
			$this->successRetention = ($retention['successLogRetention'] ?? RetentionDefaults::SUCCESS_LOG);
		} else {
			$this->errorRetention = RetentionDefaults::SYNC_LOG;
			$this->errorContractRetention = RetentionDefaults::SYNC_CONTRACT_LOG;
			$this->successRetention = RetentionDefaults::SUCCESS_LOG;
		}

	}//end __construct()

	/**
	 * Normalise a synchronization argument into the OpenRegister payload array.
	 *
	 * Accepts either an already-normalised payload array or an OpenRegister
	 * ObjectEntity (as handed in by controllers/cron) and returns the
	 * jsonSerialize() payload array.
	 *
	 * @param array|ObjectEntity $synchronization The synchronization to normalise.
	 *
	 * @return array The synchronization payload array.
	 */
	private function toSynchronization(array|ObjectEntity $synchronization): array {
		if ($synchronization instanceof ObjectEntity) {
			return $synchronization->jsonSerialize();
		}

		return $synchronization;
	}//end toSynchronization()

	/**
	 * Find a single synchronization OpenRegister object by its id/uuid.
	 *
	 * @param string|int $id The OpenRegister id (UUID) of the synchronization.
	 *
	 * @return ObjectEntity The OpenRegister synchronization object.
	 *
	 * @throws DoesNotExistException When no synchronization matches the id.
	 */
	private function findSynchronizationObject(string|int $id): ObjectEntity {
		$object = $this->orObjectService->find(
			id: (string)$id,
			register: 'integriq',
			schema: 'synchronization'
		);

		if ($object === null) {
			throw new DoesNotExistException('The synchronization you are looking for does not exist');
		}

		return $object;
	}//end findSynchronizationObject()

	/**
	 * Find a single synchronization by id and return its payload array.
	 *
	 * @param string|int $id The OpenRegister id (UUID) of the synchronization.
	 *
	 * @return array The synchronization payload array.
	 *
	 * @throws DoesNotExistException When no synchronization matches the id.
	 */
	private function findSynchronization(string|int $id): array {
		return $this->toSynchronization(synchronization: $this->findSynchronizationObject(id: $id));
	}//end findSynchronization()

	/**
	 * Find all synchronization OpenRegister objects matching the given filters.
	 *
	 * @param array $filters Filters keyed by synchronization field (e.g. `sourceId`, `sourceType`).
	 *
	 * @return ObjectEntity[] The OpenRegister synchronization objects.
	 */
	private function findAllSynchronizationObjects(array $filters = []): array {
		$config = ['filters' => array_merge(['register' => 'integriq', 'schema' => 'synchronization'], $filters)];
		try {
			$matches = $this->orObjectService->findAll(config: $config);
		} catch (DoesNotExistException $e) {
			// The `openconnector` register/schema has not been provisioned on this
			// instance yet (InitializeRegister repair step never ran, or it was
			// removed). With no synchronization store there is nothing to trigger,
			// so return an empty set instead of letting the missing-register lookup
			// escape — this method runs synchronously from the OpenRegister
			// object-event listener and would otherwise abort completely unrelated
			// object saves in other apps.
			//
			// Logged at debug only: this fires on every object create/update/delete
			// instance-wide (and several times per event), so an error/warning here
			// would flood the log on any instance without the register — including
			// the bulk-import scenario this guard exists for. The operator-facing
			// signal already lives at app registration ("legacy storage has not been
			// migrated… run occ integriq:migrate-storage").
			$this->logger->debug(
				'integriq synchronization store not found; skipping event synchronization: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}//end try

		return array_values(($matches['results'] ?? $matches));
	}//end findAllSynchronizationObjects()

	/**
	 * Find all synchronizations matching the given filters and hydrate them.
	 *
	 * @param array $filters Filters keyed by synchronization field (e.g. `sourceId`, `sourceType`).
	 *
	 * @return array[] The synchronization payload arrays.
	 */
	private function findAllSynchronizations(array $filters = []): array {
		return array_map(
			fn ($object): array => $this->toSynchronization(synchronization: $object),
			$this->findAllSynchronizationObjects(filters: $filters)
		);
	}//end findAllSynchronizations()

	/**
	 * Whether this run is close enough to PHP's memory limit to stop fetching.
	 *
	 * Configurable per source via `maxMemoryPercent`, defaulting to
	 * {@see self::DEFAULT_MAX_MEMORY_PERCENT}. Set it to 0 to disable the
	 * ceiling for a source that legitimately needs the whole limit and runs
	 * somewhere nothing else shares.
	 *
	 * Uses `memory_get_usage(true)` — real allocated memory, not PHP's
	 * bookkeeping of what it thinks is in use — because the thing being guarded
	 * against is the allocator hitting the ceiling, not the object graph's
	 * nominal size.
	 *
	 * Returns false when the limit is unlimited (`-1`), which is the CLI default:
	 * a cron run with no limit cannot exceed one, and inventing a ceiling for it
	 * would stop background imports that are working.
	 *
	 * @param array $sourceConfig The source configuration.
	 *
	 * @return boolean True when fetching should stop.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function exceededMemoryCeiling(array $sourceConfig): bool {
		$percent = (int)($sourceConfig['maxMemoryPercent'] ?? self::DEFAULT_MAX_MEMORY_PERCENT);
		if ($percent <= 0) {
			return false;
		}

		$limit = $this->phpMemoryLimitBytes();
		if ($limit <= 0) {
			return false;
		}

		return (memory_get_usage(true) > ($limit * ($percent / 100)));
	}//end exceededMemoryCeiling()

	/**
	 * PHP's configured memory limit in bytes, or -1 when unlimited.
	 *
	 * `memory_limit` is a shorthand string (`512M`, `2G`, `-1`), so it cannot be
	 * compared numerically without conversion — reading it as an int gives 512
	 * for `512M`, which would make every run look like it had exceeded its
	 * ceiling on the first page.
	 *
	 * @return int The limit in bytes, or -1 for unlimited.
	 */
	private function phpMemoryLimitBytes(): int {
		$raw = trim((string)ini_get('memory_limit'));
		if ($raw === '' || $raw === '-1') {
			return -1;
		}

		$value = (int)$raw;
		switch (strtolower(substr($raw, -1))) {
			case 'g':
				return ($value * 1024 * 1024 * 1024);
			case 'm':
				return ($value * 1024 * 1024);
			case 'k':
				return ($value * 1024);
			default:
				return $value;
		}
	}//end phpMemoryLimitBytes()

	/**
	 * Persist the synchronization's page cursor, but only for a rate-limited source.
	 *
	 * The early return is the point: a source that advertises no rate limit is
	 * paged straight through, so there is no cursor worth a write per page.
	 * `getAllObjectsFromApi()` reads `currentPage` back under two conditions: the
	 * source reports a rate limit, so a run cut off mid-crawl resumes where it
	 * stopped, or the synchronization reads a JSONL source, whose one batch per
	 * run is saved once at the end of the run rather than here.
	 *
	 * It is not a cheap write: a full OpenRegister object save of the
	 * synchronization, inside the fetch loop, and serial, so no amount of page
	 * concurrency can overlap it. Measured 2026-08-14 against a static JSON source
	 * answering in 5.3 ms: 40 pages cost 297.9 ms each serially with the write on
	 * every page, and 156.9 ms with it written only when it is read.
	 *
	 * @param array $synchronization The synchronization to persist.
	 * @param array $source The source, read for its `rateLimitLimit`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function persistPageCursor(array $synchronization, array $source): void {
		if (($source['rateLimitLimit'] ?? null) === null) {
			return;
		}

		$this->persistSynchronization(synchronization: $synchronization);
	}//end persistPageCursor()

	/**
	 * Upsert a synchronization through OpenRegister.
	 *
	 * @param array $synchronization The synchronization payload to save.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function persistSynchronization(array $synchronization): void {
		$object = $synchronization;

		// OpenRegister keys the upsert on the `uuid` parameter; the payload's
		// legacy int `id` is not an OpenRegister identifier and would break OR's
		// `trim($object['id'])` upsert probe, so address by uuid and drop the id.
		$uuid = ($synchronization['uuid'] ?? null);
		if (($uuid === null || $uuid === '') && isset($object['id']) === true && is_string($object['id']) === true) {
			$uuid = $object['id'];
		}

		unset($object['id']);

		$uuidValue = null;
		if ($uuid !== null && $uuid !== '') {
			$uuidValue = (string)$uuid;
		}

		$this->orObjectService->saveObject(
			object: $object,
			register: 'integriq',
			schema: 'synchronization',
			uuid: $uuidValue
		);
	}//end persistSynchronization()

	/**
	 * Find all synchronization contract OpenRegister objects matching the filters.
	 *
	 * Reads contracts straight from OpenRegister (register `openconnector`, schema
	 * `synchronization_contract`) so the cleanup path can scope-check each target
	 * object itself, rather than relying on the retired QBMapper JOIN.
	 *
	 * @param array $filters Filters keyed by contract field (e.g. `synchronizationId`).
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity[] The OpenRegister contract objects.
	 */
	private function findAllContractObjects(array $filters = []): array {
		if ($this->synchronizationContractService !== null) {
			return $this->synchronizationContractService->findAllObjects(filters: $filters);
		}

		$config = ['filters' => array_merge(['register' => 'integriq', 'schema' => 'synchronization_contract'], $filters)];
		$matches = $this->orObjectService->findAll(config: $config);

		return array_values(($matches['results'] ?? $matches));
	}//end findAllContractObjects()

	/**
	 * Origin ids for the pre-loop contract index, skipping what cannot resolve.
	 *
	 * 🔑 A ROW THIS PRE-PASS CANNOT READ IS SKIPPED, NOT FATAL.
	 *
	 * That boundary is real and sits a few lines below the caller: the loop
	 * catches per item, records the failure to the dead-letter service and
	 * carries on, which is what `testOneBadItemDoesNotAbortTheSyncPass` asserts —
	 * one bad item out of three leaves `invalid: 1, skipped: 2, found: 3`.
	 * Batching the contract lookup for speed moved the resolution OUT of that
	 * boundary and the isolation guarantee went with it; keeping the batch and
	 * skipping the unreadable row keeps both.
	 *
	 * Skipping is safe because this index is an OPTIMISATION, not a source of
	 * truth: an item missing from it resolves its own contract in the loop
	 * exactly as it did before the batch existed — and an item whose origin id
	 * cannot be read will fail there, on its own, and be dead-lettered with a
	 * message naming the item rather than killing the page.
	 *
	 * Deliberately total. `getOriginId()` throws when an item carries no id at
	 * the configured position, and this runs before the per-item try/catch — so
	 * a throw here ends the whole synchronization rather than dead-lettering one
	 * record. The index is a CACHE; failing to warm it for one item must cost
	 * that item a lookup, not cost the run.
	 *
	 * @param array $synchronization The synchronization being run.
	 * @param array $objectList The fetched source items.
	 *
	 * @return array<int, string> The resolvable origin ids.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
	 */
	private function originIdsForIndex(array $synchronization, array $objectList): array {
		$originIds = [];
		foreach ($objectList as $item) {
			$asArray = ['id' => $item];
			if (is_array($item) === true) {
				$asArray = $item;
			}

			try {
				$originIds[] = $this->getOriginId(synchronization: $synchronization, object: $asArray);
			} catch (\Throwable $exception) {
				// FULLY QUALIFIED deliberately: this file does not import
				// Throwable, so an unqualified catch resolves to
				// OCA\Integriq\Service\Throwable and silently matches
				// nothing — the exception sails straight through a catch block
				// that looks correct.
				// The loop will raise this again for this item, in the place that
				// knows how to record it against that item.
				continue;
			}
		}

		return $originIds;
	}//end originIdsForIndex()

	/**
	 * Find a single synchronization contract OpenRegister object by id/uuid.
	 *
	 * @param string|int $id The OpenRegister id/uuid of the contract.
	 *
	 * @return ObjectEntity|null The OpenRegister contract object, or null if not found.
	 */
	private function findContractObject(string|int $id): ?ObjectEntity {
		if ($this->synchronizationContractService !== null) {
			return $this->synchronizationContractService->findObject(id: $id);
		}

		return $this->orObjectService->find(
			id: (string)$id,
			register: 'integriq',
			schema: 'synchronization_contract'
		);
	}//end findContractObject()

	/**
	 * Find a single synchronization contract by id and return its payload array.
	 *
	 * Replaces the legacy `SynchronizationContractMapper::find($id)`.
	 *
	 * @param string|int $id The OpenRegister id/uuid of the contract.
	 *
	 * @return array The contract payload array.
	 *
	 * @throws DoesNotExistException When no contract matches the id.
	 */
	private function findContract(string|int $id): array {
		$object = $this->findContractObject(id: $id);
		if ($object === null) {
			throw new DoesNotExistException('The synchronization contract you are looking for does not exist');
		}

		return $object->jsonSerialize();
	}//end findContract()

	/**
	 * Find a contract by synchronizationId + originId (and optionally just origin).
	 *
	 * Replaces the legacy
	 * `SynchronizationContractMapper::findSyncContractByOriginId()`.
	 *
	 * @param string $synchronizationId The synchronization id.
	 * @param string $originId The origin id.
	 * @param bool|null $justByOriginId When true, match on origin id only.
	 * @param array|null $allMatches By-reference output parameter: populated with ALL
	 *                               matching contract payload arrays (not just the
	 *                               first), so the caller can hand them to
	 *                               detectDuplicateContracts() without issuing a
	 *                               second query (REQ-013).
	 *
	 * @return array|null The found contract payload array or null when not found.
	 */

	/**
	 * Read a whole page's contracts in ONE query, keyed by origin id.
	 *
	 * The per-item lookup this replaces cost one query per source record.
	 * Measured on a 100-record sync: 200 contract reads before, and the page is
	 * bounded (200 per page is a common API ceiling) so the IN list stays small
	 * and the query stays planable. Fetching every contract for the
	 * synchronization up front would trade a per-item query for an unbounded
	 * result set on a register that already holds thousands.
	 *
	 * The per-object loop is untouched — lookup and processing are separate
	 * concerns, so each item still resolves its own contract, from memory.
	 *
	 * @param string $synchronizationId The synchronization the contracts belong to.
	 * @param array $originIds The page's origin ids.
	 * @param bool $justByOriginId Match on origin id alone.
	 *
	 * @return array<string, array<int, array>> Origin id => all matching contract payloads.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors findContractBySyncAndOrigin's
	 * own flag, which is the source-config option `findContractByOriginIdOnly`.
	 */
	private function indexContractsByOrigin(
		string $synchronizationId,
		array $originIds,
		bool $justByOriginId = false,
	): array {
		$originIds = array_values(array_unique(array_filter($originIds, static fn ($id): bool => $id !== '')));
		if ($originIds === []) {
			return [];
		}

		$filters = ['originId' => $originIds];
		if ($justByOriginId === false) {
			$filters['synchronizationId'] = $synchronizationId;
		}

		$index = [];
		foreach ($this->findAllContractObjects(filters: $filters) as $match) {
			$payload = $match->jsonSerialize();
			$key = (string)($payload['originId'] ?? '');
			if ($key === '') {
				continue;
			}

			// ALL matches per origin id, not just the first: detectDuplicateContracts
			// needs the full list to spot a duplicated (synchronizationId, originId)
			// pair, and collapsing to one here would silently disable that check.
			$index[$key][] = $payload;
		}

		return $index;
	}//end indexContractsByOrigin()

	/**
	 * Find a contract by synchronizationId + originId (and optionally just origin).
	 *
	 * @param string $synchronizationId The synchronization id.
	 * @param string $originId The origin id.
	 * @param bool|null $justByOriginId When true, match on origin id only.
	 * @param array|null $allMatches By-reference output: ALL matching contract
	 *                               payloads.
	 * @param-out array $allMatches        Callers may pass null IN, but this method
	 *                                     always assigns an array back out — empty
	 *                                     when there is no match.
	 *
	 * @return array|null The found contract payload array or null when not found.
	 */
	private function findContractBySyncAndOrigin(
		string $synchronizationId,
		string $originId,
		?bool $justByOriginId = false,
		?array &$allMatches = null,
	): ?array {
		if ($justByOriginId === true) {
			$filters = ['originId' => $originId];
		} else {
			$filters = ['synchronizationId' => $synchronizationId, 'originId' => $originId];
		}

		$matches = $this->findAllContractObjects(filters: $filters);
		$allMatches = array_map(
			static fn ($match): array => $match->jsonSerialize(),
			array_values($matches)
		);
		if (empty($matches) === true) {
			return null;
		}

		return $matches[0]->jsonSerialize();
	}//end findContractBySyncAndOrigin()

	/**
	 * Read-only diagnostic: surface duplicate contracts for one (synchronizationId, originId) pair.
	 *
	 * When more than one `SynchronizationContract` exists for the same pair
	 * (e.g. data created before the originId-matching flow was pinned, or a
	 * race between two concurrent runs), a warning identifying ALL duplicate
	 * contract ids is logged and the duplicates are returned for the caller
	 * to surface. NOTHING is deleted, merged, or mutated — an automated
	 * cleanup could itself remove the wrong contract, which is exactly the
	 * class of bug the sync-safety guardrails exist to prevent (REQ-013).
	 *
	 * @param string $synchronizationId The synchronization id.
	 * @param string $originId The origin id.
	 * @param array|null $contracts The already-fetched contract payload arrays for the
	 *                              pair (from findContractBySyncAndOrigin()'s
	 *                              `$allMatches` out-parameter), so the common case
	 *                              adds no query cost. When null, they are fetched.
	 *
	 * @return array The duplicate contract payload arrays (empty when 0 or 1 contract exists).
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-duplicate-synchronization-contracts-are-surfaced-never-silently-removed-req-013
	 */
	private function detectDuplicateContracts(string $synchronizationId, string $originId, ?array $contracts = null): array {
		if ($contracts === null) {
			$contracts = array_map(
				static fn ($match): array => $match->jsonSerialize(),
				array_values(
					$this->findAllContractObjects(
						filters: [
							'synchronizationId' => $synchronizationId,
							'originId' => $originId,
						]
					)
				)
			);
		}

		if (count($contracts) <= 1) {
			return [];
		}

		$contractIds = array_map(
			static fn (array $contract) => (($contract['id'] ?? null) ?? ($contract['uuid'] ?? null)),
			$contracts
		);

		$this->logger->warning(
			'detectDuplicateContracts: multiple synchronization contracts found for the same '
			. '(synchronizationId, originId) pair — surfaced for admin review, NOT auto-deleted',
			[
				'synchronizationId' => $synchronizationId,
				'originId' => $originId,
				'contractIds' => $contractIds,
				'count' => count($contracts),
			]
		);

		return $contracts;
	}//end detectDuplicateContracts()

	/**
	 * Find a contract by origin id (single match).
	 *
	 * Replaces the legacy `SynchronizationContractMapper::findByOriginId()`.
	 *
	 * @param string $originId The origin id.
	 *
	 * @return array|null The found contract payload array or null when not found.
	 */
	private function findContractByOriginId(string $originId): ?array {
		$matches = $this->findAllContractObjects(filters: ['originId' => $originId]);
		if (empty($matches) === true) {
			return null;
		}

		return $matches[0]->jsonSerialize();
	}//end findContractByOriginId()

	/**
	 * Find the targetId for a contract addressed by originId.
	 *
	 * Replaces the legacy
	 * `SynchronizationContractMapper::findTargetIdByOriginId()`.
	 *
	 * @param string $originId The origin id.
	 *
	 * @return string|null The target id when present, otherwise null.
	 */
	private function findTargetIdByContractOrigin(string $originId): ?string {
		$contract = $this->findContractByOriginId(originId: $originId);
		if ($contract === null) {
			return null;
		}

		$targetId = ($contract['targetId'] ?? null);
		if ($targetId === null || $targetId === '') {
			return null;
		}

		return (string)$targetId;
	}//end findTargetIdByContractOrigin()

	/**
	 * Can the mapping be ruled out as a reason to re-map this object?
	 *
	 * True means "the mapping is not a reason to rewrite" — no mapping at all, or
	 * a mapping demonstrably last touched before this contract was last checked.
	 *
	 * THE BUG THIS REPLACES. The clause read
	 * `$mapping->getUpdated() < $contract['sourceLastChecked']`, comparing a
	 * `DateTime` against an ATOM *string*. PHP does not order an object against a
	 * string, so it was false for every mapping that HAD a timestamp, and the
	 * skip branch could never be taken: a mapped synchronization rewrote every
	 * object on every run. Measured on the 2000-dataset CKAN benchmark, identical
	 * source data: 1854 skipped unmapped, 0 skipped mapped; 6.8 s against 17.7 s.
	 *
	 * THE DIRECTION EACH UNKNOWN FALLS, AND WHY THEY DIFFER. An absent mapping
	 * timestamp is NOT evidence the mapping changed, so it must not block a skip —
	 * `null < 'somestring'` was true under the old code and that behaviour is
	 * deliberately kept. An absent `sourceLastChecked` is different: without a
	 * previous check there is nothing to be newer than, so the run must fall
	 * through and write.
	 *
	 * @param mixed $mapping The resolved mapping, or null when none is set.
	 * @param mixed $lastChecked The contract's `sourceLastChecked`.
	 *
	 * @return bool True when the mapping is no reason to rewrite.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-change-detection-is-independent-of-how-a-timestamp-is-typed-req-024
	 */
	private function mappingUnchangedSince(mixed $mapping, mixed $lastChecked): bool {
		if ($mapping === null) {
			return true;
		}

		$updated = $mapping->getUpdated();
		if ($this->toTimestamp(value: $updated) === null) {
			// Nothing says this mapping changed; it must not pin the object to
			// the update path forever.
			return true;
		}

		return $this->isBefore(moment: $updated, reference: $lastChecked);
	}//end mappingUnchangedSince()

	/**
	 * Is `$moment` strictly earlier than `$reference`?
	 *
	 * WHY THIS EXISTS. The skip test compares a mapping's `getUpdated()` — a
	 * `DateTime` — against a contract's `sourceLastChecked`, an ATOM *string*.
	 * PHP does not order an object against a string, so `<` was false for every
	 * mapped synchronization and the skip branch could never be taken: a
	 * synchronization WITH a mapping rewrote every object on every run, forever.
	 * Measured on the 2000-dataset CKAN benchmark: 1854 skipped without a
	 * mapping, 0 skipped with one, and 6.8 s -> 17.7 s for identical data.
	 *
	 * Both sides are normalised to a Unix timestamp. An unparseable or absent
	 * value returns false — "cannot prove it is older" must fall through to the
	 * update path, never silently skip a write.
	 *
	 * @param mixed $moment The earlier candidate (DateTimeInterface or string).
	 * @param mixed $reference The later candidate (DateTimeInterface or string).
	 *
	 * @return bool True when both parse and `$moment` is strictly earlier.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-change-detection-is-independent-of-how-a-timestamp-is-typed-req-024
	 */
	private function isBefore(mixed $moment, mixed $reference): bool {
		$momentStamp = $this->toTimestamp(value: $moment);
		$referenceStamp = $this->toTimestamp(value: $reference);

		if ($momentStamp === null || $referenceStamp === null) {
			return false;
		}

		return ($momentStamp < $referenceStamp);
	}//end isBefore()

	/**
	 * Normalise a timestamp-ish value to a Unix timestamp.
	 *
	 * @param mixed $value A DateTimeInterface, a parseable date string, or null.
	 *
	 * @return int|null The timestamp, or null when it cannot be established.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-change-detection-is-independent-of-how-a-timestamp-is-typed-req-024
	 */
	private function toTimestamp(mixed $value): ?int {
		if ($value instanceof DateTimeInterface === true) {
			return $value->getTimestamp();
		}

		if (is_string($value) === true && trim($value) !== '') {
			$parsed = strtotime($value);
			if ($parsed !== false) {
				return $parsed;
			}
		}

		return null;
	}//end toTimestamp()

	/**
	 * Give a contract a `uuid` to be written under, WITHOUT discarding the one it
	 * already has.
	 *
	 * THIS IS WHERE THE DUPLICATE-PER-RERUN DEFECT ACTUALLY LIVED. Both call
	 * sites read:
	 *
	 *     if (($contract['uuid'] ?? null) === null) {
	 *         $contract['uuid'] = (string)Uuid::v4();
	 *     }
	 *
	 * A contract loaded back from OpenRegister carries `id`, never `uuid` — so
	 * that test was true on every rerun and minted a FRESH identity each time.
	 * Everything downstream then faithfully upserted on that brand-new uuid and
	 * created a row. Fixing `persist()`, `persistContract()`, `persistBulk()` and
	 * `updateFromArray()` to derive identity correctly changed nothing measurable,
	 * because the identity was already destroyed before any of them ran: three
	 * successive live runs still added exactly one contract per updated object
	 * (8237 -> 8334 -> 8431 -> 8528).
	 *
	 * `contractIdentity()` reads `uuid`, else a non-numeric `id`. Only when there
	 * is genuinely neither is a uuid minted, which is what these sites were for.
	 *
	 * @param array $contract The contract payload, modified in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	private function assignContractIdentity(array &$contract): void {
		$identity = $this->contractIdentity(object: $contract);
		if ($identity === null) {
			$identity = (string)Uuid::v4();
		}

		$contract['uuid'] = $identity;
	}//end assignContractIdentity()

	/**
	 * The OpenRegister identifier to upsert a contract on.
	 *
	 * WHY THIS EXISTS. This used to read `$object['uuid']` alone and then drop
	 * `$object['id']`, on the reasoning that `id` was a legacy *integer* and not
	 * an OpenRegister identifier. A contract payload carries no `uuid` property
	 * at all — its identity comes back from OpenRegister AS `id`, and that `id`
	 * is a uuid string. So the upsert key was always null and every save CREATED:
	 * four distinct contract objects for one (synchronizationId, originId), one
	 * per run, each carrying an identical `originHash`. `synchronization_contract`
	 * grew without bound — 528,656 rows on the dev instance.
	 *
	 * A numeric `id` is still refused, which is what the original comment was
	 * actually protecting against.
	 *
	 * @param array $object The contract payload.
	 *
	 * @return string|null The uuid to upsert on, or null to create.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	private function contractIdentity(array $object): ?string {
		$uuid = ($object['uuid'] ?? null);
		if ($uuid !== null && (string)$uuid !== '') {
			return (string)$uuid;
		}

		$id = ($object['id'] ?? null);
		if ($id === null || is_numeric($id) === true) {
			return null;
		}

		$id = (string)$id;
		if ($id === '') {
			return null;
		}

		return $id;
	}//end contractIdentity()

	/**
	 * Persist a contract payload array to OpenRegister.
	 *
	 * Keyed for upsert on the contract's own identity (`uuid`, else a non-numeric
	 * `id` — see {@see self::contractIdentity()}), dropping a legacy int `id` so
	 * OpenRegister's upsert probe does not get confused.
	 *
	 * @param array $contract The contract payload array to persist.
	 * @param bool $ensureUuid When true, auto-assign a uuid if absent.
	 *
	 * @return array The persisted contract payload array.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	private function persistContract(array $contract, bool $ensureUuid = false): array {
		if ($this->synchronizationContractService !== null) {
			return $this->synchronizationContractService->persist(
				contract: $contract,
				ensureUuid: $ensureUuid
			);
		}

		$object = $contract;

		// Read the identity BEFORE dropping `id` — for a contract loaded back from
		// OpenRegister, `id` IS the uuid and is the only identifier present.
		$uuidValue = $this->contractIdentity(object: $object);

		// `ensureUuid` mints an identity for a contract that HAS none. It used to
		// test `empty($object['uuid'])`, which is true for every contract ever
		// loaded from OpenRegister — they carry `id`, never `uuid` — so it minted
		// a fresh uuid on top of a perfectly good identity and the save created
		// instead of updating. Both persists at the end of synchronizeContract
		// pass `ensureUuid: true`, so that was EVERY update.
		if ($ensureUuid === true && $uuidValue === null) {
			$uuidValue = (string)Uuid::v4();
			$object['uuid'] = $uuidValue;
		}

		// A legacy int `id` is not an OpenRegister identifier and would break OR's
		// `trim($object['id'])` upsert probe, so drop it either way.
		unset($object['id']);

		$saved = $this->orObjectService->saveObject(
			object: $object,
			register: 'integriq',
			schema: 'synchronization_contract',
			uuid: $uuidValue
		);

		return $saved->jsonSerialize();
	}//end persistContract()

	/**
	 * Persist a contract from array data, auto-filling uuid + version.
	 *
	 * Replaces the legacy `SynchronizationContractMapper::createFromArray()`.
	 *
	 * @param array $object Array of contract data.
	 *
	 * @return array The persisted contract payload array.
	 */
	private function createContractFromArray(array $object): array {
		if ($this->synchronizationContractService !== null) {
			return $this->synchronizationContractService->createFromArray(object: $object);
		}

		if (empty($object['uuid']) === true) {
			$object['uuid'] = (string)Uuid::v4();
		}

		if (empty($object['version']) === true) {
			$object['version'] = '0.0.1';
		}

		unset($object['id']);

		$uuid = $object['uuid'];
		$saved = $this->orObjectService->saveObject(
			object: $object,
			register: 'integriq',
			schema: 'synchronization_contract',
			uuid: $uuid
		);

		return $saved->jsonSerialize();
	}//end createContractFromArray()

	/**
	 * Update an existing contract from array data, bumping the patch version.
	 *
	 * Replaces the legacy `SynchronizationContractMapper::updateFromArray()`.
	 *
	 * @param string|int $id The contract id/uuid.
	 * @param array $object Array of updated contract data.
	 *
	 * @return array The persisted contract payload array.
	 *
	 * @throws DoesNotExistException When the contract does not exist.
	 */
	private function updateContractFromArray(string|int $id, array $object): array {
		if ($this->synchronizationContractService !== null) {
			return $this->synchronizationContractService->updateFromArray(id: $id, object: $object);
		}

		$existing = $this->findContract(id: $id);

		$existingVersion = ($existing['version'] ?? null);
		if (empty($existingVersion) === true) {
			$object['version'] = '0.0.1';
		} elseif (empty($object['version']) === true) {
			$version = explode('.', (string)$existingVersion);
			if (isset($version[2]) === true) {
				$version[2] = ((int)$version[2] + 1);
				$object['version'] = implode('.', $version);
			}
		}

		$merged = array_merge($existing, $object);

		// Identity BEFORE dropping `id` — the delegate's copy of this method was
		// corrected first; this fallback carried the same defect, reading a `uuid`
		// a contract payload never has and so upserting on null.
		$uuidValue = $this->contractIdentity(object: $merged);
		unset($merged['id']);

		$saved = $this->orObjectService->saveObject(
			object: $merged,
			register: 'integriq',
			schema: 'synchronization_contract',
			uuid: $uuidValue
		);

		return $saved->jsonSerialize();
	}//end updateContractFromArray()

	/**
	 * Find a single source OpenRegister object by its id/uuid/slug.
	 *
	 * Reads sources straight from OpenRegister (register `openconnector`, schema
	 * `source`) so the engine no longer has to go through the SourceMapper
	 * adapter. Replaces the legacy `$this->sourceMapper->findObject($id)` and
	 * `$this->sourceMapper->find($id)` calls.
	 *
	 * @param string|int $id The OpenRegister id/uuid/slug of the source.
	 *
	 * @return ObjectEntity The OpenRegister source object.
	 *
	 * @throws DoesNotExistException When no source matches the identifier.
	 */
	private function findSourceObject(string|int $id): ObjectEntity {
		// System context (ocon#147). The `source` schema is now admin-only, because it
		// is admin-owned configuration and — until the plaintext credential fields are
		// removed — it carries secrets. But a synchronisation is legitimately triggered
		// by non-admins and by cron, and it is the ENGINE that needs the source, not the
		// user: the source never leaves this method, and the user never sees it. Reading
		// it as the acting user would either break every non-admin sync or force the
		// schema back open. Neither is acceptable, so the engine reads as the system.
		$object = $this->orObjectService->find(
			id: (string)$id,
			register: 'integriq',
			schema: 'source',
			_rbac: false,
			_multitenancy: false
		);

		if ($object === null) {
			throw new DoesNotExistException('The source you are looking for does not exist');
		}

		return $object;
	}//end findSourceObject()

	/**
	 * Find a single source by id and return its OpenRegister payload array.
	 *
	 * @param string|int $id The OpenRegister id/uuid/slug of the source.
	 *
	 * @return array The OpenRegister source payload array.
	 *
	 * @throws DoesNotExistException When no source matches the identifier.
	 */
	private function findSource(string|int $id): array {
		return $this->findSourceObject(id: $id)->jsonSerialize();
	}//end findSource()

	/**
	 * Find a source by its `location`, or build a TRANSIENT one if no match exists.
	 *
	 * Reimplements the legacy `SourceMapper::findOrCreateByLocation()` over the
	 * OpenRegister ObjectService so the engine no longer depends on the adapter.
	 *
	 * A genuinely unmatched location resolves to a transient, in-memory source
	 * configuration for the current call only — it is NEVER persisted as a new,
	 * enabled Source object (REQ-012 / ConductionNL/integriq#1009: an
	 * ad-hoc, caller-supplied location string must not silently become
	 * reviewable-config-grade state; an admin who needs a reusable Source for
	 * that location should configure one, which is the intended path). The
	 * find-by-location half is unchanged: an admin-configured Source whose
	 * `location` matches is returned exactly as before, rate-limit watermark
	 * state included.
	 *
	 * @param string $location The source location (URL/identifier).
	 * @param array $defaultData Additional fields to seed the transient source with.
	 *
	 * @return array The existing (persisted) or transient source payload array;
	 *               a transient one carries `_transient => true` so downstream
	 *               resolution (callSourceObject()) knows not to re-fetch it.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
	 */
	private function findOrCreateSourceByLocation(string $location, array $defaultData = []): array {
		$config = ['filters' => ['register' => 'integriq', 'schema' => 'source', 'location' => $location], 'limit' => 1];
		$matches = $this->orObjectService->findAll(config: $config);
		$objects = array_values(($matches['results'] ?? $matches));

		if (empty($objects) === false) {
			return $objects[0]->jsonSerialize();
		}

		$sourceData = array_merge(
			[
				'location' => $location,
				'name' => basename($location),
				'type' => 'api',
				'enabled' => true,
			],
			$defaultData
		);

		if (empty($sourceData['uuid']) === true) {
			$sourceData['uuid'] = (string)Uuid::v4();
		}

		if (empty($sourceData['version']) === true) {
			$sourceData['version'] = '0.0.1';
		}

		// The transient source addresses itself by its generated uuid; a stray
		// int `id` from $defaultData would shadow it downstream.
		unset($sourceData['id']);

		// Deliberately NOT persisted (no orObjectService->saveObject()) — see
		// the method docblock. The transient source carries no credentials, so
		// it can only ever call an unauthenticated URL, and it loses cross-call
		// rate-limit watermark tracking (checkRateLimit() no-ops without
		// rateLimitLimit) — both are the intended #1009 tradeoff.
		$sourceData['_transient'] = true;

		return $sourceData;
	}//end findOrCreateSourceByLocation()

	/**
	 * Find synchronizations triggered by a mutation on a related object.
	 *
	 * Reimplements the removed SynchronizationMapper::findAllByRelatedObjectTrigger
	 * over OpenRegister-stored synchronizations.
	 *
	 * @param string|int $register The register id of the mutated object.
	 * @param string|int $schema The schema id of the mutated object.
	 * @param string $mutationType The mutation type: create|update|delete.
	 *
	 * @return array[] The synchronization payload arrays whose related-object trigger matches.
	 */
	private function findAllByRelatedObjectTrigger(string|int $register, string|int $schema, string $mutationType): array {
		if (in_array($mutationType, self::VALID_MUTATION_TYPES, true) === false) {
			return [];
		}

		$relatedSourceId = "$register/$schema";
		$synchronizations = $this->findAllSynchronizations(filters: ['sourceType' => 'register/schema']);

		return array_values(
			array_filter(
				$synchronizations,
				function (array $synchronization) use ($relatedSourceId, $mutationType): bool {
					$sourceConfig = ($synchronization['sourceConfig'] ?? []);
					$triggerConfig = ($sourceConfig['triggerFromRelatedObjects'] ?? null);

					if (is_array($triggerConfig) === false || isset($triggerConfig[$relatedSourceId]) === false) {
						return false;
					}

					return $this->isRelatedTriggerConfigAllowed(triggerSourceConfig: $triggerConfig[$relatedSourceId], mutationType: $mutationType);
				}
			)
		);
	}//end findAllByRelatedObjectTrigger()

	/**
	 * Validates trigger configuration for one related source entry.
	 *
	 * Expected shape: {"<relationKey>": ["create","update","delete"]}.
	 *
	 * @param mixed $triggerSourceConfig Config value for one register/schema key.
	 * @param string $mutationType Current mutation type to validate.
	 *
	 * @return bool True when the config allows the given mutation type.
	 */
	private function isRelatedTriggerConfigAllowed(mixed $triggerSourceConfig, string $mutationType): bool {
		if (is_array($triggerSourceConfig) === false) {
			return false;
		}

		if ($this->isAssociativeArray(array: $triggerSourceConfig) === true) {
			$firstRelationKey = array_key_first($triggerSourceConfig);
			if (is_string($firstRelationKey) === false || trim($firstRelationKey) === '') {
				return false;
			}

			return $this->isRelatedObjectMutationAllowed(
				mutationConfig: ($triggerSourceConfig[$firstRelationKey] ?? []),
				mutationType: $mutationType
			);
		}

		return false;
	}//end isRelatedTriggerConfigAllowed()

	/**
	 * Checks whether a mutation list allows the given mutation type.
	 *
	 * @param mixed $mutationConfig Array of allowed mutations.
	 * @param string $mutationType Current mutation type.
	 *
	 * @return bool True when allowed (or "all" is present), false otherwise.
	 */
	private function isRelatedObjectMutationAllowed(mixed $mutationConfig, string $mutationType): bool {
		if (is_array($mutationConfig) === false) {
			return false;
		}

		$normalizedMutations = array_map(
			static fn (mixed $mutation): string => strtolower((string)$mutation),
			$mutationConfig
		);

		if (in_array('all', $normalizedMutations, true) === true) {
			return true;
		}

		$normalMutation = strtolower($mutationType);

		// Create and update are treated as one "upsert" group for trigger checks.
		if ($normalMutation === 'create' || $normalMutation === 'update') {
			return in_array('create', $normalizedMutations, true) || in_array('update', $normalizedMutations, true);
		}

		// Delete remains strict and must be explicitly configured.
		return $normalMutation === 'delete' && in_array('delete', $normalizedMutations, true);
	}//end isRelatedObjectMutationAllowed()

	/**
	 * Calculates the used retention for created logs. Consists of the maximum of the retention from the
	 * source, and the global retention, unless either of both is 0, in which case retention is indefinite.
	 *
	 * @param int[] ...$retentions The list of retentions in milliseconds to find the maximum duration for.
	 *
	 * @return DateTime|null The calculated expiry.
	 *
	 * @throws \DateMalformedStringException
	 *
	 * @TODO: At a later point in time this should be changed to using the most specific source for expiration
	 */
	private function calculateExpires(...$retentions): ?\DateTime {
		if (in_array(0, $retentions, true) === true) {
			return null;
		}

		return new DateTime('now +' . max($retentions) . 'milliseconds');
	}//end calculateExpires()

	/**
	 * Whether an object meets a synchronization's conditions.
	 *
	 * The synchronization schema types `conditions` as a list of JsonLogic
	 * groups, and the editor stores it that way (`[{"and": [...]}]`), while
	 * older seeds hold one bare JsonLogic object. JsonLogic::apply() on a list
	 * answers a list (`[false]`), which is never `false`, so a list-shaped
	 * condition used to let every object through. A list holds when every
	 * group in it holds. JsonLogic::apply() returns a range of types, so only
	 * a literal `false` fails a group, as before.
	 *
	 * @param mixed $conditions The conditions: [] (none), a JsonLogic object, or a list of them.
	 * @param array $data       The object to test.
	 *
	 * @return bool True when there are no conditions or every one holds.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-synchronization-orchestration-and-direction-routing-req-001
	 */
	private static function conditionsHold(mixed $conditions, array $data): bool {
		if ($conditions === [] || $conditions === null) {
			return true;
		}

		if (is_array($conditions) === true && array_is_list($conditions) === true) {
			foreach ($conditions as $group) {
				if (self::conditionsHold(conditions: $group, data: $data) === false) {
					return false;
				}
			}

			return true;
		}

		return JsonLogic::apply($conditions, $data) !== false;
	}//end conditionsHold()

	/**
	 * Finds all synchronizations by the given source ID, which is a combination of register and schema.
	 *
	 * @param $register The register id.
	 * @param $schema The schema id.
	 *
	 * @return array The list of records matching the source ID.
	 */
	public function findAllBySourceId($register, $schema) {
		$sourceId = "$register/$schema";
		return $this->findAllSynchronizationObjects(filters: ['sourceId' => $sourceId]);
	}//end findAllBySourceId()

	/**
	 * Check if a synchronization should trigger for the given object event type.
	 *
	 * Supported sourceConfig key:
	 * - triggerOnlyOnEvents: array|string of CREATE|UPDATE|DELETE
	 *
	 * @param array $synchronization The synchronization configuration.
	 * @param string $eventMutationType The triggering mutation type.
	 *
	 * @return bool True when the synchronization should trigger for this event.
	 */
	private function shouldTriggerOnEvent(array $synchronization, string $eventMutationType): bool {
		$sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));
		if (array_key_exists('triggerOnlyOnEvents', $sourceConfig) === false) {
			return true;
		}

		$allowedEvents = $sourceConfig['triggerOnlyOnEvents'];
		if (is_string($allowedEvents) === true) {
			$allowedEvents = [$allowedEvents];
		}

		if (is_array($allowedEvents) === false) {
			return true;
		}

		$allowedEvents = array_map(
			static fn ($event): string => strtoupper(trim((string)$event)),
			$allowedEvents
		);

		return in_array(strtoupper($eventMutationType), $allowedEvents, true);
	}//end shouldTriggerOnEvent()

	/**
	 * Handle synchronization for object create/update/delete events.
	 *
	 * This centralizes event listener behavior:
	 * - run direct source synchronizations for the object's register/schema
	 * - run related-object trigger synchronizations
	 *
	 * @param ObjectEntity $object The object from the event.
	 * @param string $eventMutationType The triggering mutation: create|update|delete
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-synchronization-orchestration-and-direction-routing-req-001
	 */
	public function handleObjectEventSynchronization(ObjectEntity $object, string $eventMutationType): void {
		// Integriq subscribes to OpenRegister's object lifecycle events
		// synchronously, so this runs inside the host save that triggered the
		// event — even when the saving app has nothing to do with Integriq.
		// A failure here must never unwind into that save (which would 500 the
		// host operation), so swallow everything and log instead.
		try {
			$this->doHandleObjectEventSynchronization(object: $object, eventMutationType: $eventMutationType);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to handle object event synchronization: ' . $e->getMessage(),
				[
					'exception' => $e,
					'eventMutationType' => $eventMutationType,
				]
			);
		}
	}//end handleObjectEventSynchronization()

	/**
	 * Run direct and related-object-trigger synchronizations for an object event.
	 *
	 * @param ObjectEntity $object The object from the event.
	 * @param string $eventMutationType The triggering mutation: create|update|delete
	 *
	 * @return void
	 */
	private function doHandleObjectEventSynchronization(ObjectEntity $object, string $eventMutationType): void {
		if (in_array($eventMutationType, self::VALID_MUTATION_TYPES, true) === false) {
			return;
		}

		$register = $object->getRegister();
		$schema = $object->getSchema();
		if ($register === null || $schema === null) {
			return;
		}

		$objectArray = $object->jsonSerialize();
		$processedSynchronizationIds = [];

		$directSynchronizations = $this->findAllBySourceId(register: $register, schema: $schema);
		foreach ($directSynchronizations as $synchronizationObject) {
			$synchronization = $this->toSynchronization(synchronization: $synchronizationObject);
			if ($this->shouldTriggerOnEvent(synchronization: $synchronization, eventMutationType: $eventMutationType) === false) {
				continue;
			}

			try {
				if ($eventMutationType === 'delete') {
					$eventObject = $object;
					$this->synchronize(
						synchronization: $synchronization,
						force: true,
						object: $eventObject,
						mutationType: 'delete'
					);
				} else {
					$this->pushOnObjectEvent(synchronization: $synchronization, object: $object, objectArray: $objectArray);
				}

				$processedSynchronizationIds[] = ($synchronization['id'] ?? null);
			} catch (TargetWriteRefusedException $e) {
				$this->recordWriteBackRefusal(synchronization: $synchronization, object: $object, refusal: $e);
			} catch (\Exception $e) {
				$this->logger->error(
					'Failed to process object event: ' . $e->getMessage() . ' for synchronization ' . ($synchronization['id'] ?? null),
					[
						'exception' => $e,
						'eventMutationType' => $eventMutationType,
						'register' => $register,
						'schema' => $schema,
					]
				);
			}//end try
		}//end foreach

		$triggeredSynchronizations = $this->findAllByRelatedObjectTrigger(
			register: $register,
			schema: $schema,
			mutationType: $eventMutationType
		);

		foreach ($triggeredSynchronizations as $synchronization) {
			if (in_array(($synchronization['id'] ?? null), $processedSynchronizationIds, true) === true) {
				continue;
			}

			if ($this->shouldTriggerOnEvent(synchronization: $synchronization, eventMutationType: $eventMutationType) === false) {
				continue;
			}

			try {
				$parentObjectArray = $this->resolveParentObjectForRelatedObjectTrigger(
					synchronization: $synchronization,
					triggerObject: $objectArray,
					triggerRegister: $register,
					triggerSchema: $schema,
					mutationType: $eventMutationType
				);

				if ($parentObjectArray === null) {
					continue;
				}

				$this->synchronize(
					synchronization: $synchronization,
					force: true,
					object: $parentObjectArray
				);
			} catch (\Exception $e) {
				$this->logger->error(
					'Failed to process related-object trigger: ' . $e->getMessage() . ' for synchronization ' . ($synchronization['id'] ?? null),
					[
						'exception' => $e,
						'eventMutationType' => $eventMutationType,
						'register' => $register,
						'schema' => $schema,
					]
				);
			}//end try
		}//end foreach
	}//end doHandleObjectEventSynchronization()

	/**
	 * Push a created or updated object, and clear a recorded conflict once the target accepts it.
	 *
	 * @param array                                $synchronization The push synchronization.
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object          The local object.
	 * @param array                                $objectArray     Its serialised form.
	 *
	 * @return void
	 *
	 * @throws TargetWriteRefusedException When the target refused the write.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function pushOnObjectEvent(array $synchronization, \OCA\OpenRegister\Db\ObjectEntity $object, array $objectArray): void {
		$this->synchronize(synchronization: $synchronization, force: true, object: $objectArray);
		$this->markWriteBack(
			object: $object,
			property: (string)($synchronization['targetConfig']['conflictStatusProperty'] ?? ''),
			from: self::WRITE_BACK_CONFLICT,
			to: self::WRITE_BACK_SYNCED
		);
	}//end pushOnObjectEvent()

	/**
	 * Keep the local edit and mark the object: the target refused the write.
	 *
	 * The edit stays because the push runs after the local save and nothing on
	 * this path writes the object's data back.
	 *
	 * @param array                                $synchronization The push synchronization.
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object          The local object.
	 * @param TargetWriteRefusedException          $refusal         What the target answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function recordWriteBackRefusal(
		array $synchronization,
		\OCA\OpenRegister\Db\ObjectEntity $object,
		TargetWriteRefusedException $refusal,
	): void {
		$this->logger->warning(
			'Write-back refused for synchronization ' . ($synchronization['id'] ?? '') . ': ' . $refusal->getMessage(),
			['statusCode' => $refusal->getStatusCode(), 'objectId' => $object->getUuid()]
		);
		$this->markWriteBack(object: $object, property: $refusal->getStatusProperty(), from: null, to: self::WRITE_BACK_CONFLICT);
	}//end recordWriteBackRefusal()

	/**
	 * The remote id a push target already has for this object, read at `targetConfig.targetIdPosition`.
	 *
	 * @param array $targetConfig The synchronization's target configuration.
	 * @param array $object       The mapped object about to be written.
	 *
	 * @return string|null The remote id, or null when none is declared or present.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function targetIdFromObject(array $targetConfig, array $object): ?string {
		$position = ($targetConfig['targetIdPosition'] ?? null);
		if (is_string($position) === false || $position === '') {
			return null;
		}

		$value = (new Dot($object))->get($position);
		if (is_scalar($value) === false || (string)$value === '') {
			return null;
		}

		return (string)$value;
	}//end targetIdFromObject()

	/**
	 * The endpoint for an absolute remote id, relative to the target's location.
	 *
	 * The call carries the target's credentials, so an id on another host is
	 * refused rather than called.
	 *
	 * @param string $url            The absolute remote id.
	 * @param string $targetLocation The target source's location.
	 *
	 * @return string The endpoint relative to the location.
	 *
	 * @throws Exception When the url is not under the location.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function endpointUnderTarget(string $url, string $targetLocation): string {
		$location = rtrim($targetLocation, '/');
		if ($location === '' || str_starts_with($url, $location . '/') === false) {
			throw new Exception(
				'Refused to write to ' . $url . ': it is not under the target source location. '
				. 'A remote id may only address the store the synchronization writes to.'
			);
		}

		return substr($url, strlen($location));
	}//end endpointUnderTarget()

	/**
	 * Raise a refusal for a 4xx answer, when the synchronization records conflicts.
	 *
	 * A target is called with `http_errors` off, so a refusal otherwise reads as
	 * a success. Only a synchronization declaring
	 * `targetConfig.conflictStatusProperty` changes; a 5xx is an outage, not a
	 * conflict.
	 *
	 * @param ObjectEntity $callLog      The call log of the write.
	 * @param array        $targetConfig The synchronization's target configuration.
	 *
	 * @return void
	 *
	 * @throws TargetWriteRefusedException When the target answered 4xx.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function refuseOnTargetConflict(ObjectEntity $callLog, array $targetConfig): void {
		$property = ($targetConfig['conflictStatusProperty'] ?? null);
		if (is_string($property) === false || $property === '') {
			return;
		}

		$status = (int)$this->callLogStatusCode(callLog: $callLog);
		if ($status >= 400 && $status < 500) {
			throw new TargetWriteRefusedException(statusCode: $status, statusProperty: $property);
		}
	}//end refuseOnTargetConflict()

	/**
	 * Record the write-back state on the local object, silently.
	 *
	 * Silent because a normal save fires the object event, which runs the push
	 * again: a refused push would be refused again and loop.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object   The local object.
	 * @param string                           $property The property recording the state ('' = none).
	 * @param string|null                      $from     Only change it from this value (null = from any).
	 * @param string                           $to       The value to record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function markWriteBack(\OCA\OpenRegister\Db\ObjectEntity $object, string $property, ?string $from, string $to): void {
		$data    = $object->getObject();
		$current = ($data[$property] ?? null);
		if ($property === '' || $current === $to || ($from !== null && $current !== $from)) {
			return;
		}

		$data[$property] = $to;
		try {
			$this->orObjectService->saveObject(
				object: $data,
				register: $object->getRegister(),
				schema: $object->getSchema(),
				uuid: $object->getUuid(),
				silent: true
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Could not record ' . $property . ' = ' . $to . ' on object ' . $object->getUuid()
				. ' (schema ' . $object->getSchema() . '): add a string property "' . $property . '" to that schema. '
				. $e->getMessage()
			);
		}
	}//end markWriteBack()

	/**
	 * Resolve and fetch the parent object for a related-object trigger.
	 *
	 * @param array $synchronization The synchronization that should run.
	 * @param array $triggerObject The related object payload from the event.
	 * @param string|int $triggerRegister The register of the related object source.
	 * @param string|int $triggerSchema The schema of the related object source.
	 * @param string|null $mutationType The triggering mutation type (reserved for future filtering).
	 *
	 * @return array|null The fetched parent object as array, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function resolveParentObjectForRelatedObjectTrigger(
		array $synchronization,
		array $triggerObject,
		string|int $triggerRegister,
		string|int $triggerSchema,
		?string $mutationType = null,
	): ?array {
		if (($synchronization['sourceType'] ?? null) !== 'register/schema') {
			return null;
		}

		$sourceId = ($synchronization['sourceId'] ?? null);
		if (empty($sourceId) === true || str_contains($sourceId, '/') === false) {
			return null;
		}

		$triggerSourceId = "$triggerRegister/$triggerSchema";
		$relationKey = $this->relatedObjectRelationKey(synchronization: $synchronization, triggerSourceId: $triggerSourceId);
		if ($relationKey === null) {
			return null;
		}

		$relationReference = (new Dot($triggerObject))->get($relationKey);
		if (is_string($relationReference) === false || trim($relationReference) === '') {
			return null;
		}

		$relationReference = trim($relationReference);
		$parentObjectId = $this->parentObjectIdFromReference(reference: $relationReference);
		if ($parentObjectId === null) {
			return null;
		}

		[$parentRegister, $parentSchema] = explode('/', $sourceId, 2);
		$openRegisters = $this->objectService->getOpenRegisters();
		if ($openRegisters === null) {
			return null;
		}

		try {
			$parentObject = $openRegisters->find(
				id: $parentObjectId,
				register: $parentRegister,
				schema: $parentSchema
			);

			return $parentObject?->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Failed resolving related parent object via configured relation key.',
				[
					'synchronizationId' => ($synchronization['id'] ?? null),
					'sourceId' => $sourceId,
					'triggerSourceId' => $triggerSourceId,
					'relationKeys' => [$relationKey],
					'relationReference' => $relationReference,
					'parentObjectId' => $parentObjectId,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}//end try
	}//end resolveParentObjectForRelatedObjectTrigger()

	/**
	 * The relation key a synchronization configures for objects of a related source.
	 *
	 * The key is the first key of `sourceConfig.triggerFromRelatedObjects.<register>/<schema>`,
	 * trimmed; it is the dot path in the related object that references the parent.
	 *
	 * @param array  $synchronization The synchronization that should run.
	 * @param string $triggerSourceId The related object's source, as `<register>/<schema>`.
	 *
	 * @return string|null The relation key, or null when none is configured.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function relatedObjectRelationKey(array $synchronization, string $triggerSourceId): ?string {
		$sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));
		$relationConfig = ($sourceConfig['triggerFromRelatedObjects'][$triggerSourceId] ?? null);
		if (is_array($relationConfig) === false) {
			return null;
		}

		$relationKey = array_key_first($relationConfig);
		if (is_string($relationKey) === false || trim($relationKey) === '') {
			return null;
		}

		return trim($relationKey);
	}//end relatedObjectRelationKey()

	/**
	 * The parent object id a relation reference names: the reference itself when it is
	 * a UUID, or the last path segment of a URL when that segment is a UUID.
	 *
	 * @param string $reference The trimmed relation reference.
	 *
	 * @return string|null The parent object id, or null when the reference names none.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function parentObjectIdFromReference(string $reference): ?string {
		if (Uuid::isValid($reference) === true) {
			return $reference;
		}

		if (filter_var($reference, FILTER_VALIDATE_URL) === false) {
			return null;
		}

		$path = trim((string)parse_url($reference, PHP_URL_PATH), '/');
		$segments = array_values(array_filter(explode('/', $path), static fn ($segment) => $segment !== ''));
		$lastSegment = end($segments);
		if (is_string($lastSegment) === true && Uuid::isValid($lastSegment) === true) {
			return $lastSegment;
		}

		return null;
	}//end parentObjectIdFromReference()

	/**
	 * Synchronizes internal data to external sources based on synchronization rules.
	 *
	 * @param array $synchronization The synchronization configuration.
	 * @param \OCA\OpenRegister\Db\ObjectEntity|array $object The object to be synchronized, also
	 *                                                        referenced so it is updated in parents.
	 * @param SynchronizationRunLog $log The log object to record details.
	 * @param FlowToken $flowToken The flow token shared across the run.
	 * @param bool $isTest Whether this is a test run (no persist).
	 * @param bool|null $force Whether to force regardless of changes.
	 * @param string|null $mutationType For single object sync: the mutation
	 *                                  type, 'create', 'update' or 'delete'.
	 *                                  Used for syncs to external sources.
	 * @param ExecutionTraceContext|null $trace The active execution trace context (execution-trace REQ-001).
	 *
	 * @return array|null Returns a synchronization contract, an array for test cases, or null when not met.
	 */
	private function synchronizeInternToExtern(
		array $synchronization,
		\OCA\OpenRegister\Db\ObjectEntity|array &$object,
		SynchronizationRunLog $log,
		FlowToken &$flowToken,
		?bool $isTest = false,
		?bool $force = false,
		?string $mutationType = null,
		?ExecutionTraceContext $trace = null,
	): ?array {
		$serializedObject = $this->extendInternBeforeConditions(synchronization: $synchronization, object: $object);
		if (self::conditionsHold(conditions: ($synchronization['conditions'] ?? []), data: (array)$serializedObject) === false) {
			return null;
		}

		// Keep the working object in sync with pre-condition enrichment so mapping
		// and target payload generation use the same extended data.
		if (is_array($serializedObject) === true) {
			$object = $serializedObject;
		}

		$originId = $this->internOriginId(object: $object);
		if ($object instanceof \OCA\OpenRegister\Db\ObjectEntity === true && empty($object->getUuid()) === false) {
			$originId = $object->getUuid();
			$object = $object->getObject();
		}

		$object = $this->extendInternForTarget(targetConfig: ($synchronization['targetConfig'] ?? []), object: $object);

		$synchronizationContract = $this->findInternContract(synchronization: $synchronization, originId: $originId);
		if (is_array($synchronizationContract) === false) {
			$synchronizationContract = [
				'synchronizationId' => ($synchronization['id'] ?? null),
				'originId' => $originId,
			];
			// Only persist if not test.
			if ($isTest === false) {
				$synchronizationContract = $this->createContractFromArray(object: $synchronizationContract);
			}
		}

		$result = $this->synchronizeContract(
			synchronizationContract: $synchronizationContract,
			synchronization: $synchronization,
			flowToken: $flowToken,
			object: $object,
			isTest: $isTest,
			force: $force,
			log: $log,
			mutationType: $mutationType,
			trace: $trace
		);

		return $this->internContractResult(result: $result, isTest: $isTest);
	}//end synchronizeInternToExtern()

	/**
	 * What an intern-to-extern run answers for a contract synchronization's result.
	 *
	 * The result is an Exception or the
	 * `['log' => ..., 'contract' => ..., 'resultAction' => ...]` shape. Test
	 * mode returns the shape upstream; non-test extracts the contract.
	 *
	 * @param array|Exception $result The synchronizeContract() result.
	 * @param bool|null       $isTest Whether this is a test run.
	 *
	 * @return array|null
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function internContractResult(array|Exception $result, ?bool $isTest): ?array {
		if ($result instanceof Exception) {
			return null;
		}

		if ($isTest === true) {
			return $result;
		}

		return ($result['contract'] ?? null);
	}//end internContractResult()

	/**
	 * The object serialized, and extended with the properties the source
	 * configuration asks for before the conditions are checked.
	 *
	 * @param array                                   $synchronization The synchronization configuration.
	 * @param \OCA\OpenRegister\Db\ObjectEntity|array $object          The object to be synchronized.
	 *
	 * @return mixed The (extended) serialized object.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function extendInternBeforeConditions(array $synchronization, \OCA\OpenRegister\Db\ObjectEntity|array $object): mixed {
		$serializedObject = $object;
		if ($object instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
			$serializedObject = $object->jsonSerialize();
		}

		$sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));
		if (isset($sourceConfig[self::EXTEND_BEFORE_CONDITIONS_LOCATION]) === false) {
			return $serializedObject;
		}

		$this->logger->debug('internToExtern before extendInputBeforeConditions', $this->internExtendLogContext(object: $serializedObject));

		$fetchObject = false;
		if (isset($sourceConfig[self::EXTEND_BEFORE_CONDITIONS_FETCH_OBJECT]) === true) {
			$fetchObject = filter_var($sourceConfig[self::EXTEND_BEFORE_CONDITIONS_FETCH_OBJECT], FILTER_VALIDATE_BOOLEAN) === true;
		}

		$serializedObject = $this->processExtendInputRule(
			config: [
				'extend_input' => [
					'properties' => $sourceConfig[self::EXTEND_BEFORE_CONDITIONS_LOCATION],
					'fetchObject' => $fetchObject,
				],
			],
			data: $serializedObject
		);

		$this->logger->debug('internToExtern after extendInputBeforeConditions', $this->internExtendLogContext(object: $serializedObject));

		return $serializedObject;
	}//end extendInternBeforeConditions()

	/**
	 * The log context of a pre-condition extension: the object's id and its betrokkeneIdentificatie.
	 *
	 * @param mixed $object The serialized object.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function internExtendLogContext(mixed $object): array {
		return [
			'objectId' => $object['@self']['id'] ?? $object['id'] ?? null,
			'betrokkeneIdentificatie' => $object['betrokkeneIdentificatie'] ?? null,
		];
	}//end internExtendLogContext()

	/**
	 * The origin id of an object in array form: its `@self.id`, else its `id`.
	 *
	 * @param mixed $object The object to be synchronized.
	 *
	 * @return mixed The origin id, or null.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function internOriginId(mixed $object): mixed {
		if (is_array($object) === false) {
			return null;
		}

		if (isset($object['@self']['id']) === true) {
			return $object['@self']['id'];
		}

		return ($object['id'] ?? null);
	}//end internOriginId()

	/**
	 * The object extended with the properties the target configuration's `extend_input` names.
	 *
	 * With `extend_input_fetch_object` the object is fetched again including
	 * those extends, so it hooks into the existing extend functionality.
	 *
	 * @param array $targetConfig The target configuration.
	 * @param mixed $object       The object to be synchronized.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function extendInternForTarget(array $targetConfig, mixed $object): mixed {
		if (isset($targetConfig['extend_input']) === false) {
			return $object;
		}

		$fetchObject = false;
		if (isset($targetConfig['extend_input_fetch_object']) === true) {
			// Loose comparison on purpose: it decides which configured values fetch.
			switch ($targetConfig['extend_input_fetch_object']) {
				case 0:
				case true:
				case 'true':
					$fetchObject = true;
					break;
				default:
					$fetchObject = false;
					break;
			}
		}

		$extendedObject = $this->processExtendInputRule(
			config: ['extend_input' => ['properties' => $targetConfig['extend_input'], 'fetchObject' => $fetchObject]],
			data: $object
		);

		return array_merge($object, $extendedObject);
	}//end extendInternForTarget()

	/**
	 * The synchronization's contract for an origin id, or null when there is none (or no origin id).
	 *
	 * @param array $synchronization The synchronization configuration.
	 * @param mixed $originId        The origin id.
	 *
	 * @return array|null
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function findInternContract(array $synchronization, mixed $originId): ?array {
		if ($originId === null) {
			return null;
		}

		return $this->findContractBySyncAndOrigin(
			synchronizationId: (string)(($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? null)),
			originId: $originId
		);
	}//end findInternContract()

	/**
	 * Synchronizes external source data to the internal system.
	 *
	 * This method retrieves objects from the external source as configured in the synchronization payload.
	 * Each object is processed and mapped internally, and optionally, invalid internal objects are deleted.
	 * If the synchronization is part of a chain, any defined follow-ups are also executed.
	 *
	 * If a rate limit error occurs during the external request, a `TooManyRequestsHttpException` is thrown.
	 *
	 * @param array $synchronization The synchronization configuration and state.
	 * @param SynchronizationRunLog $log The log object to record details and results.
	 * @param FlowToken $flowToken The flow token shared across the run.
	 * @param bool|null $isTest Optional flag to run in test mode (no deletions/persist).
	 * @param bool|null $force Optional flag to bypass change checks and force all.
	 * @param string|null $source The source to synchronize; defaults to the sync source.
	 * @param array|null $data The data to synchronize; defaults to the sync data.
	 * @param string|null $mutationType The current mutation type from this::VALID_MUTATION_TYPES.
	 * @param bool|null $forceDeletion Explicit override for the deletion-ratio guard
	 *                                 (REQ-010); ignored when `$isTest === true`.
	 * @param string|null $approvalRequestId Bypass token: the id of a specific approved
	 *                                       `approval_request` to consume when
	 *                                       `sourceConfig.requiresApproval` gates this
	 *                                       run (synchronization-engine REQ-015).
	 *                                       Optional — when omitted, any
	 *                                       approved+unconsumed request for this
	 *                                       synchronization satisfies the gate.
	 * @param ExecutionTraceContext|null $trace The active execution trace context (execution-trace REQ-001).
	 *
	 * @return SynchronizationRunLog Returns the updated synchronization log with processing results.
	 *
	 * @throws TooManyRequestsHttpException If the external source responds with a rate limiting error.
	 * @throws Exception If the source ID is empty or synchronization cannot proceed.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011
	 * @spec openspec/specs/synchronization-engine/spec.md
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-source-object-fetching-and-pagination-req-002
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Backward-compatible optional flags
	 * (isTest/force pre-exist; forceDeletion is mandated by design.md Decision 2/3).
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) $approvalRequestId is an additive,
	 * backward-compatible optional bypass token (hitl-approval-rule-action design.md
	 * Decision 6); all 9 preceding parameters pre-exist this change.
	 */
	private function synchronizeExternToIntern(
		array $synchronization,
		SynchronizationRunLog $log,
		FlowToken &$flowToken,
		?bool $isTest = false,
		?bool $force = false,
		?string $source = null,
		?array $data = null,
		?string $mutationType = null,
		?bool $forceDeletion = false,
		?string $approvalRequestId = null,
		?ExecutionTraceContext $trace = null,
	): SynchronizationRunLog {
		$overallStartTime = microtime(true);

		$run = new ExternSyncRun();
		$run->synchronization = $synchronization;
		$run->log = $log;
		$run->flowToken = &$flowToken;
		$run->isTest = $isTest;
		$run->force = $force;
		$run->data = $data;
		$run->mutationType = $mutationType;
		$run->forceDeletion = $forceDeletion;
		$run->approvalRequestId = $approvalRequestId;
		$run->trace = $trace;

		$this->validateExternRun(run: $run, source: $source);

		if ($data !== null && $mutationType === 'delete') {
			$this->processExternDelete(run: $run);
		} elseif ($this->runExternBatch(run: $run) === false) {
			return $run->log;
		}

		// The gate passed and the write phase has completed: mark the approval
		// consumed so it cannot re-authorize a later run (REQ-015).
		if ($run->gatedApprovalRequest !== null) {
			$this->approvalGate->markConsumed(approvalRequest: $run->gatedApprovalRequest);
		}

		$this->runFollowUps(run: $run);

		return $this->finishExternRun(run: $run, overallStartTime: $overallStartTime);
	}//end synchronizeExternToIntern()

	/**
	 * Stage 1 of an extern-to-intern run: load the configuration and validate the source.
	 *
	 * A source location, when given, replaces the synchronization's own source. A
	 * missing source or an unset declared prerequisite stops the run before any fetch.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param string|null $source A source location to use instead of the synchronization's source.
	 *
	 * @return void
	 *
	 * @throws Exception When the source is missing or a prerequisite is not set.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function validateExternRun(ExternSyncRun $run, ?string $source): void {
		$run->result = $run->log->getResult();
		$run->result['timing'] = ['stages' => [], 'total_ms' => 0];

		$stageStartTime = microtime(true);
		$run->sourceConfig = $this->callService->applyConfigDot(($run->synchronization['sourceConfig'] ?? []));

		if ($source !== null) {
			$run->source = $this->findOrCreateSourceByLocation(location: $source);
			$run->synchronization['sourceId'] = (string)($run->source['id'] ?? $run->source['uuid'] ?? '');
		}

		if (empty(($run->synchronization['sourceId'] ?? null)) === true && $run->source === null) {
			$this->failExternRun(run: $run, message: 'sourceId of synchronization cannot be empty. Canceling synchronization...');
		}

		$missingPrerequisite = $this->prerequisiteGuard?->missing(sourceConfig: $run->sourceConfig);
		if ($missingPrerequisite !== null) {
			$this->failExternRun(run: $run, message: $missingPrerequisite);
		}

		$run->result['timing']['stages']['configuration_validation'] = [
			'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
			'description' => 'Configuration loading and source validation',
		];
	}//end validateExternRun()

	/**
	 * Records a refusal on the run log and stops the run.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param string $message Why the run stops.
	 *
	 * @return never
	 *
	 * @throws Exception Always.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function failExternRun(ExternSyncRun $run, string $message): never {
		$run->log->setMessage($message);
		$run->log = $this->synchronizationLogService->update(log: $run->log);
		throw new Exception($message);
	}//end failExternRun()

	/**
	 * Processes a single-object delete run, which is never fetched, gated or cleaned up.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function processExternDelete(ExternSyncRun $run): void {
		$this->processSynchronizationObject(
			synchronization: $run->synchronization,
			object: $run->data,
			result: $run->result,
			isTest: $run->isTest,
			force: $run->force,
			log: $run->log,
			flowToken: $run->flowToken,
			mutationType: $run->mutationType,
			trace: $run->trace
		);
	}//end processExternDelete()

	/**
	 * Runs the batch stages: fetch, prepare, gate, process, embed and clean up.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return bool False when the approval gate paused the run.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function runExternBatch(ExternSyncRun $run): bool {
		$this->fetchExternObjects(run: $run);
		$this->prepareExternObjectList(run: $run);

		if ($this->passesApprovalGate(run: $run) === false) {
			return false;
		}

		$stageStartTime = microtime(true);
		$this->processExternObjects(run: $run);
		$this->embedRunContracts(run: $run);
		$this->recordProcessingTiming(run: $run, stageStartTime: $stageStartTime);
		$this->cleanupExternRun(run: $run);

		return true;
	}//end runExternBatch()

	/**
	 * Stage 2: fetches the objects from the source.
	 *
	 * A 429 aborts the fetch before it can report its own completeness, so it is
	 * recorded as an incomplete fetch explicitly (REQ-009) and rethrown at the end.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function fetchExternObjects(ExternSyncRun $run): void {
		$stageStartTime = microtime(true);
		$fetchInfo = ['complete' => true, 'pagesFetched' => 0, 'failureReason' => null];
		try {
			// The resolved source is threaded through so the fetch chain never
			// re-resolves it by id (REQ-012).
			$run->objectList = $this->getAllObjectsFromSource(
				synchronization: $run->synchronization,
				isTest: $run->isTest,
				data: $run->data,
				fetchInfo: $fetchInfo,
				resolvedSource: $run->source
			);
		} catch (TooManyRequestsHttpException $e) {
			$run->rateLimitException = $e;
			$run->objectList = [];
			$fetchInfo = ['complete' => false, 'pagesFetched' => 0, 'failureReason' => 'rate_limited'];
		}

		$run->fetchInfo = $fetchInfo;
		$this->applyBatchResume(run: $run);

		$run->result['timing']['stages']['fetch_objects'] = [
			'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
			'description' => 'Fetching objects from external source (optimized pagination)',
			'objects_fetched' => count($run->objectList),
			'rate_limited' => $run->rateLimitException !== null,
			'fetch_method' => 'optimized_sequential',
		];
	}//end fetchExternObjects()

	/**
	 * Carries the batch a batched (JSONL) source resumes at onto the run's synchronization.
	 *
	 * A batch still to come, after a batch that read records, is reported so the
	 * job comes back on the next cron tick; a failed download keeps its batch and
	 * waits for the job's own interval.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function applyBatchResume(ExternSyncRun $run): void {
		$resumePage = ($run->fetchInfo['resumePage'] ?? null);
		if ($run->isTest !== false || $resumePage === null) {
			return;
		}

		$run->synchronization['currentPage'] = $resumePage;
		$run->synchronization['currentPageVersion'] = ($run->fetchInfo['sourceVersion'] ?? '');
		if ($resumePage > 1 && $run->fetchInfo['failureReason'] === 'batch_limit_reached') {
			$run->result['resume'] = ['page' => $resumePage, 'reason' => $run->fetchInfo['failureReason']];
		}
	}//end applyBatchResume()

	/**
	 * Stage 3: counts the fetched objects and wraps a single `_object` result in a list.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function prepareExternObjectList(ExternSyncRun $run): void {
		$stageStartTime = microtime(true);
		$run->result['objects']['found'] = count($run->objectList);

		if (($run->sourceConfig['resultsPosition'] ?? null) === '_object') {
			if (array_is_list($run->objectList) === false) {
				$run->objectList = [$run->objectList];
			}

			$run->result['objects']['found'] = count($run->objectList);
		}

		$run->result['timing']['stages']['object_preparation'] = [
			'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
			'description' => 'Object list preparation and counting',
			'final_object_count' => count($run->objectList),
		];
	}//end prepareExternObjectList()

	/**
	 * The batch-level approval gate (REQ-015), checked before any write.
	 *
	 * A test run is never gated. Without an approved request, or when the source
	 * changed after the previewed change set (REQ-INAV-004), the run is paused.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return bool True when the run may write.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function passesApprovalGate(ExternSyncRun $run): bool {
		if ($run->isTest !== false || (bool)($run->sourceConfig['requiresApproval'] ?? false) !== true) {
			return true;
		}

		// `id` first: OpenRegister exposes no top-level `uuid`.
		$synchronizationId = (string)(($run->synchronization['id'] ?? null) ?? ($run->synchronization['uuid'] ?? ''));
		$run->gatedApprovalRequest = $this->approvalGate->resolve(
			synchronizationId: $synchronizationId,
			bypassApprovalId: $run->approvalRequestId
		);

		// What this run would create, change and remove (REQ-INAV-003); removals
		// only where REQ-010 and REQ-018 would allow a deletion in this run.
		$changeSet = $this->buildGateChangeSet(
			synchronization: $run->synchronization,
			objectList: $run->objectList,
			removalsAllowed: (
				$run->rateLimitException === null
				&& ($run->fetchInfo['complete'] ?? true) === true
				&& (string)($run->synchronization['syncMode'] ?? 'full') !== 'incremental'
			)
		);

		$superseded = $this->isApprovalSuperseded(approvalRequest: $run->gatedApprovalRequest, fingerprint: $changeSet['fingerprint']);
		if ($run->gatedApprovalRequest !== null && $superseded === false) {
			return true;
		}

		$this->pauseGatedRun(run: $run, synchronizationId: $synchronizationId, changeSet: $changeSet, superseded: $superseded);

		return false;
	}//end passesApprovalGate()

	/**
	 * Checks whether an approval was given for a different change set than this run's.
	 *
	 * A request stored before previews existed carries no fingerprint and passes.
	 *
	 * @param ObjectEntity|null $approvalRequest The approval request, if any.
	 * @param mixed $fingerprint The fingerprint of this run's change set.
	 *
	 * @return bool True when the approval is superseded.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function isApprovalSuperseded(?ObjectEntity $approvalRequest, mixed $fingerprint): bool {
		$previewedFingerprint = null;
		if ($approvalRequest !== null) {
			$previewedFingerprint = ($approvalRequest->getObject()['fingerprint'] ?? null);
		}

		return is_string($previewedFingerprint) === true
			&& $previewedFingerprint !== ''
			&& $previewedFingerprint !== $fingerprint;
	}//end isApprovalSuperseded()

	/**
	 * Pauses a gated run: asks for approval and records the run as skipped.
	 *
	 * No writes, no garbage collection and no follow-ups run for a paused run.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param string $synchronizationId The synchronization id.
	 * @param array $changeSet The run's change set.
	 * @param bool $superseded Whether an earlier approval was superseded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function pauseGatedRun(ExternSyncRun $run, string $synchronizationId, array $changeSet, bool $superseded): void {
		$newRequest = $this->suspendGatedRun(
			synchronization: $run->synchronization,
			synchronizationId: $synchronizationId,
			changeSet: $changeSet
		);

		$message = 'pending_approval';
		if ($superseded === true) {
			$this->approvalGate->markSuperseded(
				approvalRequest: $run->gatedApprovalRequest,
				supersededBy: (string)$newRequest->getUuid()
			);
			$run->result['approval'] = [
				'superseded' => true,
				'previous' => $run->gatedApprovalRequest->getUuid(),
				'supersededBy' => $newRequest->getUuid(),
			];
			$message = 'approval_superseded';
			$run->gatedApprovalRequest = null;
		}

		$count = count($run->objectList);
		$run->result['objects'] = array_merge(
			($run->result['objects'] ?? []),
			['found' => $count, 'created' => 0, 'updated' => 0, 'skipped' => $count, 'deleted' => 0]
		);

		$run->log->setResult($run->result);
		$run->log->setMessage($message);
		$run->log = $this->synchronizationLogService->update(log: $run->log);
	}//end pauseGatedRun()

	/**
	 * Stage 4: processes every fetched object, with batched writes.
	 *
	 * Target and contract writes are buffered for the loop and flushed as batched
	 * upserts (see flushWriteBuffers()). A test run is excluded (REQ-011). The
	 * `finally` resets the buffer flag: this shared service re-enters itself, so a
	 * flag left on by a throw would make a later call buffer writes nothing flushes.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
	 */
	private function processExternObjects(ExternSyncRun $run): void {
		// One contract read for the whole page, before the loop; a row this
		// pre-pass cannot read is skipped, not fatal (see originIdsForIndex()).
		$contractIndex = $this->indexContractsByOrigin(
			synchronizationId: (string)($run->synchronization['id'] ?? ''),
			originIds: $this->originIdsForIndex(synchronization: $run->synchronization, objectList: $run->objectList),
			justByOriginId: (
				isset($run->sourceConfig['findContractByOriginIdOnly']) === true
				&& filter_var($run->sourceConfig['findContractByOriginIdOnly'], FILTER_VALIDATE_BOOLEAN) === true
			)
		);

		$this->targetWriteBuffer = [];
		$this->contractWriteBuffer = [];
		$this->writeFlushMs = 0.0;
		$this->bufferWrites = ($run->isTest === false);

		try {
			$progressProcessed = 0;
			foreach ($run->objectList as $object) {
				$progressProcessed++;
				$this->processExternObject(run: $run, object: $object, contractIndex: $contractIndex, processed: $progressProcessed);
			}
		} finally {
			$this->bufferWrites = false;
			$this->flushWriteBuffers();
		}
	}//end processExternObjects()

	/**
	 * Processes one fetched object, isolating its failure from the rest of the run.
	 *
	 * A bare scalar item is wrapped as `['value' => ...]` first, so it flows through
	 * mapping, identity and write like any other item (REQ-002/REQ-008).
	 *
	 * @param ExternSyncRun $run The run.
	 * @param mixed $object The fetched object.
	 * @param array|null $contractIndex The run's contracts indexed by origin id.
	 * @param int $processed How many objects this run has reached, this one included.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
	 */
	private function processExternObject(ExternSyncRun $run, mixed $object, ?array $contractIndex, int $processed): void {
		$this->tickRunProgress(run: $run, processed: $processed);

		if (is_array($object) === false) {
			$object = ['value' => $object];
		}

		$objectStartTime = microtime(true);
		try {
			$processResult = $this->processSynchronizationObject(
				synchronization: $run->synchronization,
				flowToken: $run->flowToken,
				object: $object,
				result: $run->result,
				isTest: $run->isTest,
				force: $run->force,
				log: $run->log,
				trace: $run->trace,
				contractIndex: $contractIndex
			);
		} catch (\Throwable $itemException) {
			$this->recordExternItemFailure(run: $run, object: $object, exception: $itemException);
			$run->objectTimes[] = round((microtime(true) - $objectStartTime) * 1000, 2);
			return;
		}

		$run->objectTimes[] = round((microtime(true) - $objectStartTime) * 1000, 2);
		$this->recordExternItemResult(run: $run, processResult: $processResult);
	}//end processExternObject()

	/**
	 * Takes a processed item's result into the run, and flushes full write buffers.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param array $processResult What processSynchronizationObject() returned.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function recordExternItemResult(ExternSyncRun $run, array $processResult): void {
		$run->result = $this->takeValidationFindings(result: $processResult['result']);

		if ($processResult['targetId'] !== null) {
			$run->targetIds[] = $processResult['targetId'];
		}

		// Bound peak memory: the loop covers the run's whole object list.
		if ($this->countBufferedTargetRows() >= self::WRITE_BUFFER_FLUSH_SIZE) {
			$this->flushWriteBuffers();
		}
	}//end recordExternItemResult()

	/**
	 * Reports the run's progress; the progress service's time throttle decides what is written.
	 *
	 * Counted from the loop, because the run log's tallies are only filled in at the end.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param int $processed How many objects this run has reached.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function tickRunProgress(ExternSyncRun $run, int $processed): void {
		$this->runProgressService?->tick(
			counters: [
				'found' => count($run->objectList),
				'processed' => $processed,
				'created' => (int)($run->result['objects']['created'] ?? 0),
				'updated' => (int)($run->result['objects']['updated'] ?? 0),
				'deleted' => (int)($run->result['objects']['deleted'] ?? 0),
				'invalid' => (int)($run->result['objects']['invalid'] ?? 0),
			]
		);
	}//end tickRunProgress()

	/**
	 * Counts a failed item as invalid, logs why and dead-letters it.
	 *
	 * `invalid` also counts non-array items and unrecognised result actions, so
	 * the log line states that this item threw.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param array $object The item.
	 * @param \Throwable $exception What the item threw.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
	 */
	private function recordExternItemFailure(ExternSyncRun $run, array $object, \Throwable $exception): void {
		$run->result['objects']['invalid']++;

		$this->logger->warning(
			'Synchronization item counted as invalid: item processing threw',
			[
				'synchronization' => ($run->synchronization['name'] ?? ($run->synchronization['uuid'] ?? null)),
				'exception' => $exception->getMessage(),
				'exceptionClass' => get_class($exception),
				'file' => $exception->getFile() . ':' . $exception->getLine(),
			]
		);

		$this->captureSyncItemFailure(synchronization: $run->synchronization, object: $object, exception: $exception);
		$run->result = $this->takeValidationFindings(result: $run->result);
	}//end recordExternItemFailure()

	/**
	 * Resolves the run's contracts once, in one query, into `_embed.contracts`.
	 *
	 * `_embed.contracts` stays positionally aligned with `contracts`, with null for
	 * a contract without uuid or one that no longer resolves. Embedding is bounded:
	 * past `maxEmbeddedContracts` it is omitted, and the uuids stay in `contracts`.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function embedRunContracts(ExternSyncRun $run): void {
		$contractIds = array_values(
			array_filter(
				($run->result['contracts'] ?? []),
				static fn ($id): bool => $id !== null
			)
		);

		$embedCap = (int)($run->sourceConfig['maxEmbeddedContracts'] ?? self::MAX_EMBEDDED_CONTRACTS);
		if ($embedCap > 0 && count($contractIds) > $embedCap) {
			$this->logger->info(
				'Skipped _embed.contracts for {count} contracts (cap {cap}): inlining them would '
				. 'cost more memory and CPU than the synchronization itself. The uuids remain in '
				. '`contracts`.',
				['count' => count($contractIds), 'cap' => $embedCap]
			);
			return;
		}

		$resolved = $this->resolveContractsForEmbed(contractIds: $contractIds);
		$run->result['_embed']['contracts'] = array_map(
			static function ($contractId) use ($resolved) {
				if ($contractId === null) {
					return null;
				}

				return ($resolved[(string)$contractId] ?? null);
			},
			($run->result['contracts'] ?? [])
		);
	}//end embedRunContracts()

	/**
	 * Loads contracts by uuid, keyed by id; best-effort, a failure yields none.
	 *
	 * @param array $contractIds The contract uuids.
	 *
	 * @return array<string, array> The serialized contracts, keyed by id.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function resolveContractsForEmbed(array $contractIds): array {
		$resolved = [];
		if ($contractIds === []) {
			return $resolved;
		}

		try {
			foreach ($this->findAllContractObjects(filters: ['uuid' => $contractIds]) as $match) {
				$payload = $match->jsonSerialize();
				$key = (string)(($payload['id'] ?? null) ?? ($payload['uuid'] ?? ''));
				if ($key !== '') {
					$resolved[$key] = $payload;
				}
			}
		} catch (\Throwable $embedException) {
			$this->logger->warning(
				'Could not resolve contracts for _embed: ' . $embedException->getMessage()
			);
		}

		return $resolved;
	}//end resolveContractsForEmbed()

	/**
	 * Records the timing of stage 4, split into database writes and everything else.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param float $stageStartTime When stage 4 started.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function recordProcessingTiming(ExternSyncRun $run, float $stageStartTime): void {
		$totalProcessingDuration = round((microtime(true) - $stageStartTime) * 1000, 2);
		$objectCount = count($run->objectList);

		$averagePerObjectMs = 0;
		if ($objectCount > 0) {
			$averagePerObjectMs = round($totalProcessingDuration / $objectCount, 2);
		}

		$run->result['timing']['stages']['process_objects'] = array_merge(
			[
				'duration_ms' => $totalProcessingDuration,
				'description' => 'Processing and synchronizing individual objects',
				'bulk_write_ms' => round($this->writeFlushMs, 2),
				'engine_ms' => round(($totalProcessingDuration - $this->writeFlushMs), 2),
				'objects_processed' => $objectCount,
				'average_per_object_ms' => $averagePerObjectMs,
			],
			$this->summarizeObjectTimes(times: $run->objectTimes)
		);
	}//end recordProcessingTiming()

	/**
	 * Summarizes per-object processing times as minimum, maximum and median.
	 *
	 * @param float[] $times The processing times, in milliseconds.
	 *
	 * @return array{min_object_ms: float|int, max_object_ms: float|int, median_object_ms: float|int}
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function summarizeObjectTimes(array $times): array {
		if (count($times) === 0) {
			return ['min_object_ms' => 0, 'max_object_ms' => 0, 'median_object_ms' => 0];
		}

		return [
			'min_object_ms' => min($times),
			'max_object_ms' => max($times),
			'median_object_ms' => $this->calculateMedian(numbers: $times),
		];
	}//end summarizeObjectTimes()

	/**
	 * Stage 5: deletes the objects the source no longer has, and advances the cursor.
	 *
	 * A test run deletes nothing (REQ-011); the pass is skipped, not merely guarded.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function cleanupExternRun(ExternSyncRun $run): void {
		$stageStartTime = microtime(true);
		$deletedCount = 0;
		$guardInfo = null;
		if ($run->isTest === false) {
			$fetchComplete = ($run->rateLimitException === null && ($run->fetchInfo['complete'] ?? true));
			$deletedCount = $this->deleteMissingObjects(run: $run, fetchComplete: $fetchComplete, guardInfo: $guardInfo);
			$this->advanceCursorWatermark(run: $run, fetchComplete: $fetchComplete);
		}

		$run->result['objects']['deleted'] = $deletedCount;
		$run->result['objects']['deletionGuard'] = $guardInfo;
		// REQ-SDP-001/003: purges are counted apart from soft deletes, with refusals listed.
		$run->result['objects']['purged'] = ($guardInfo['purgedCount'] ?? 0);
		$run->result['objects']['purgeRefusals'] = ($guardInfo['purgeRefusals'] ?? []);

		$run->result['timing']['stages']['cleanup_invalid'] = [
			'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
			'description' => 'Deleting invalid/orphaned objects',
			'objects_deleted' => $deletedCount,
		];
	}//end cleanupExternRun()

	/**
	 * Runs the deletion pass, unless the run is incremental or one batch of a larger source.
	 *
	 * An incremental run never runs the bulk source-diff cleanup (REQ-018), and a
	 * JSONL source larger than one batch never has a complete fetch (REQ-006);
	 * each is reported with its own guard reason.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param bool $fetchComplete Whether the fetch was complete.
	 * @param array|null $guardInfo Receives why deletion was guarded, if it was.
	 *
	 * @return int The number of deleted objects.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018
	 */
	private function deleteMissingObjects(ExternSyncRun $run, bool $fetchComplete, ?array &$guardInfo): int {
		$syncMode = (string)($run->synchronization['syncMode'] ?? 'full');
		$batchRun = (($run->fetchInfo['failureReason'] ?? null) === 'batch_limit_reached');
		if ($syncMode === 'incremental' || $batchRun === true) {
			$reason = 'batch_run';
			if ($syncMode === 'incremental') {
				$reason = 'incremental_mode';
			}

			$guardInfo = ['guarded' => true, 'reason' => $reason, 'ratio' => null, 'threshold' => null];
			return 0;
		}

		return $this->deleteInvalidObjects(
			synchronization: $run->synchronization,
			synchronizedTargetIds: $run->targetIds,
			deleteRestriction: (isset($run->sourceConfig['restrictDeletion']) === true && (bool)$run->sourceConfig['restrictDeletion']),
			data: ($run->data ?? []),
			fetchComplete: $fetchComplete,
			forceDeletion: ($run->forceDeletion ?? false),
			guardInfo: $guardInfo
		);
	}//end deleteMissingObjects()

	/**
	 * Advances an incremental run's cursor watermark after a complete fetch (REQ-017).
	 *
	 * A null computed watermark is not persisted; the prior watermark is kept.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param bool $fetchComplete Whether the fetch was complete.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017
	 */
	private function advanceCursorWatermark(ExternSyncRun $run, bool $fetchComplete): void {
		if ((string)($run->synchronization['syncMode'] ?? 'full') !== 'incremental' || $fetchComplete === false) {
			return;
		}

		$newWatermark = $this->computeCursorWatermark(synchronization: $run->synchronization, objectList: $run->objectList);
		if ($newWatermark !== null) {
			$run->synchronization['cursorWatermark'] = $newWatermark;
		}
	}//end advanceCursorWatermark()

	/**
	 * Stage 6: runs the follow-up synchronizations, skipping any already on the chain.
	 *
	 * The shared chain stack bounds follow-up recursion: a follow-up already running
	 * higher up the stack is skipped and reported, not re-entered.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function runFollowUps(ExternSyncRun $run): void {
		$stageStartTime = microtime(true);
		$selfId = (string)($run->synchronization['id'] ?? ($run->synchronization['uuid'] ?? ''));
		if ($selfId !== '') {
			self::$syncChainStack[] = $selfId;
		}

		try {
			[$followUpCount, $followUpSkipped] = $this->runFollowUpChain(run: $run);
		} finally {
			if ($selfId !== '') {
				array_pop(self::$syncChainStack);
			}
		}

		$run->result['timing']['stages']['follow_ups'] = [
			'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
			'description' => 'Executing follow-up synchronizations',
			'follow_ups_executed' => $followUpCount,
			'follow_ups_skipped' => $followUpSkipped,
		];
	}//end runFollowUps()

	/**
	 * Runs each follow-up synchronization that is not already on the chain.
	 *
	 * @param ExternSyncRun $run The run.
	 *
	 * @return array{0: int, 1: string[]} The number run and the ids skipped.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function runFollowUpChain(ExternSyncRun $run): array {
		$followUpCount = 0;
		$followUpSkipped = [];
		foreach (($run->synchronization['followUps'] ?? []) as $followUp) {
			if (in_array((string)$followUp, self::$syncChainStack, true) === true) {
				$followUpSkipped[] = (string)$followUp;
				$this->logger->warning(
					'Skipped follow-up synchronization "' . $followUp . '": it is already running on this chain '
					. '(cycle). Express the chain as an OpenRegister flow instead.'
				);
				continue;
			}

			$followUpSynchronization = $this->findSynchronization(id: $followUp);
			$this->synchronize(synchronization: $followUpSynchronization, isTest: $run->isTest, force: $run->force);
			$followUpCount++;
		}

		return [$followUpCount, $followUpSkipped];
	}//end runFollowUpChain()

	/**
	 * Completes a run: timing summary, rate-limit rethrow and synchronization persist.
	 *
	 * A test run persists no change to the synchronization (REQ-011).
	 *
	 * @param ExternSyncRun $run The run.
	 * @param float $overallStartTime When the run started.
	 *
	 * @return SynchronizationRunLog The updated run log.
	 *
	 * @throws TooManyRequestsHttpException When the fetch was rate limited.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function finishExternRun(ExternSyncRun $run, float $overallStartTime): SynchronizationRunLog {
		$this->summarizeRunTiming(run: $run, overallStartTime: $overallStartTime);
		$run->log->setResult($run->result);

		if ($run->rateLimitException !== null) {
			$run->log->setMessage($run->rateLimitException->getMessage());
			$run->log = $this->synchronizationLogService->update(log: $run->log);

			// Named arguments: Symfony's positional signature starts with $retryAfter.
			throw new TooManyRequestsHttpException(
				message: $run->rateLimitException->getMessage(),
				code: 429,
				headers: $run->rateLimitException->getHeaders()
			);
		}

		if ($run->isTest === false) {
			$run->synchronization['targetLastSynced'] = (new DateTime())->format(DateTime::ATOM);
			$this->persistSynchronization(synchronization: $run->synchronization);
		}

		return $run->log;
	}//end finishExternRun()

	/**
	 * Records the run's total time and its performance summary.
	 *
	 * @param ExternSyncRun $run The run.
	 * @param float $overallStartTime When the run started.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function summarizeRunTiming(ExternSyncRun $run, float $overallStartTime): void {
		$run->result['timing']['total_ms'] = round((microtime(true) - $overallStartTime) * 1000, 2);

		$objectsPerSecond = 0;
		if ($run->objectList !== null && count($run->objectList) > 0) {
			$objectsPerSecond = round(count($run->objectList) / ($run->result['timing']['total_ms'] / 1000), 2);
		}

		$run->result['timing']['summary'] = [
			'slowest_stage' => $this->getSlowestInternship(stages: $run->result['timing']['stages']),
			'efficiency_ratio' => $this->calculateEfficiencyRatio(stages: $run->result['timing']['stages']),
			'objects_per_second' => $objectsPerSecond,
		];
	}//end summarizeRunTiming()

	/**
	 * Open the approval_request that pauses a gated run, carrying its change set.
	 *
	 * @param array $synchronization The gated synchronization.
	 * @param string $synchronizationId Its id.
	 * @param array $changeSet What the run would write (ChangeSetBuilder::build()).
	 *
	 * @return ObjectEntity The pending request.
	 *
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
	 */
	private function suspendGatedRun(array $synchronization, string $synchronizationId, array $changeSet): ObjectEntity {
		$approvalConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig']['approval'] ?? []));

		return $this->approvalGate->suspendForSynchronization(
			synchronizationId: $synchronizationId,
			approverGroup: (string)($approvalConfig['approverGroup'] ?? ''),
			onReject: (string)($approvalConfig['onReject'] ?? 'error'),
			onTimeout: (string)($approvalConfig['onTimeout'] ?? 'error'),
			ttlSeconds: (int)($approvalConfig['ttlSeconds'] ?? ApprovalService::DEFAULT_TTL_SECONDS),
			changeSet: $changeSet
		);
	}//end suspendGatedRun()

	/**
	 * Build the change set of a gated run: each fetched object mapped, set
	 * against the target its contract points at, plus the targets the
	 * source no longer carries.
	 *
	 * Reads only. The preview runs the extra-data fetch and the mapping, not
	 * the `before` rules: a rule may call out or write, and nothing may be
	 * written before the accept (design D3). An object that cannot be read or
	 * mapped is left out; the write loop dead-letters it as it always has.
	 *
	 * @param array $synchronization The gated synchronization.
	 * @param array $objectList The fetched source objects.
	 * @param bool $removalsAllowed Whether this run may delete at all.
	 *
	 * @return array The change set (ChangeSetBuilder::build()).
	 *
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
	 */
	private function buildGateChangeSet(array $synchronization, array $objectList, bool $removalsAllowed): array {
		$synchronizationId = (string)(($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? ''));
		$sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));

		$mapping = null;
		if (empty($synchronization['sourceTargetMapping']) === false) {
			$mapping = $this->orObjectService->find(
				id: (string)$synchronization['sourceTargetMapping'],
				register: 'integriq',
				schema: 'mapping'
			);
		}

		$contractIndex = $this->indexContractsByOrigin(
			synchronizationId: $synchronizationId,
			originIds: $this->originIdsForIndex(synchronization: $synchronization, objectList: $objectList),
			justByOriginId: (
				isset($sourceConfig['findContractByOriginIdOnly']) === true
				&& filter_var($sourceConfig['findContractByOriginIdOnly'], FILTER_VALIDATE_BOOLEAN) === true
			)
		);

		$entries = [];
		$seen = [];
		foreach ($objectList as $object) {
			if (is_array($object) === false) {
				$object = ['value' => $object];
			}

			try {
				$originId = $this->getOriginId(synchronization: $synchronization, object: $object);
				$object = $this->fetchMultipleExtraData(synchronization: $synchronization, sourceConfig: $sourceConfig, object: $object);
				$mapped = $object;
				if ($mapping !== null) {
					$mapped = $this->mappingService->executeMapping(mapping: $mapping, input: $object);
				}
			} catch (\Throwable $exception) {
				continue;
			}

			$seen[$originId] = true;
			$targetId = ($contractIndex[$originId][0]['targetId'] ?? null);
			$entries[] = [
				'originId' => $originId,
				'targetId' => $targetId,
				'mapped' => $mapped,
				'existing' => $this->readGateTarget(synchronization: $synchronization, targetId: $targetId),
			];
		}//end foreach

		$removed = [];
		if ($removalsAllowed === true) {
			$removed = $this->gateRemovals(synchronizationId: $synchronizationId, seen: $seen);
		}

		return (new ChangeSetBuilder())->build(entries: $entries, removed: $removed, removalsAllowed: $removalsAllowed);
	}//end buildGateChangeSet()

	/**
	 * The targets a gated run would remove: contracts of this synchronization
	 * whose origin the fetch no longer carried.
	 *
	 * @param string $synchronizationId The synchronization.
	 * @param array<string, bool> $seen The origin ids the fetch carried.
	 *
	 * @return array<int, array{originId: string, targetId: string}>
	 *
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
	 */
	private function gateRemovals(string $synchronizationId, array $seen): array {
		$removed = [];
		foreach ($this->findAllContractObjects(filters: ['synchronizationId' => $synchronizationId]) as $contract) {
			$payload = $contract->jsonSerialize();
			$originId = (string)($payload['originId'] ?? '');
			if ($originId === '' || isset($seen[$originId]) === true || empty($payload['targetId']) === true) {
				continue;
			}

			$removed[] = ['originId' => $originId, 'targetId' => (string)$payload['targetId']];
		}

		return $removed;
	}//end gateRemovals()

	/**
	 * The stored target a contract points at, or null when there is none.
	 *
	 * @param array $synchronization The synchronization (its `targetId` is `register/schema`).
	 * @param string|null $targetId The contract's target object id.
	 *
	 * @return array|null The stored object, or null when it does not exist or cannot be read.
	 *
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
	 */
	private function readGateTarget(array $synchronization, ?string $targetId): ?array {
		$parts = explode('/', (string)($synchronization['targetId'] ?? ''));
		if ($targetId === null || $targetId === '' || count($parts) !== 2) {
			return null;
		}

		try {
			$target = $this->orObjectService->find(id: $targetId, register: $parts[0], schema: $parts[1]);
		} catch (\Throwable $exception) {
			return null;
		}

		if ($target instanceof ObjectEntity === false) {
			return null;
		}

		return $target->getObject();
	}//end readGateTarget()

	/**
	 * Best-effort capture of a per-item sync failure to `sync_item_dead_letter`
	 * (synchronization-engine spec REQ-008). Never throws — a failure to
	 * capture the dead-letter entry itself must not compound the original
	 * item failure by also aborting the sync pass; it is logged instead.
	 *
	 * @param array $synchronization The synchronization payload.
	 * @param mixed $object The raw source object that failed processing.
	 * @param \Throwable $exception The caught exception.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
	 */
	private function captureSyncItemFailure(array $synchronization, mixed $object, \Throwable $exception): void {
		if ($this->syncItemDeadLetterService === null) {
			$this->logger->warning(
				'SynchronizationService: sync-item failure could not be dead-lettered — SyncItemDeadLetterService unavailable.',
				['synchronizationId' => ($synchronization['uuid'] ?? $synchronization['id'] ?? null), 'exception' => $exception->getMessage()]
			);
			return;
		}

		$originId = null;
		$payload = ['value' => $object];
		if (is_array($object) === true) {
			$payload = $object;

			try {
				$originId = $this->getOriginId(synchronization: $synchronization, object: $object);
			} catch (\Throwable $originException) {
				// Origin id could not be resolved before the failure — leave null.
				unset($originException);
			}
		}

		try {
			$this->syncItemDeadLetterService->recordFailure(
				synchronization: $synchronization,
				payload: $payload,
				error: $exception->getMessage(),
				originId: $originId,
			);
		} catch (\Throwable $captureException) {
			$this->logger->warning(
				'SynchronizationService: failed to persist sync_item_dead_letter entry.',
				[
					'synchronizationId' => ($synchronization['uuid'] ?? $synchronization['id'] ?? null),
					'exception' => $captureException->getMessage(),
				]
			);
		}

	}//end captureSyncItemFailure()

	/**
	 * Re-invokes processSynchronizationObject() for a single payload against
	 * its synchronization — used by SyncItemDeadLetterService::replayMessage()
	 * to manually re-attempt a dead-lettered sync item (dead-letter-replay
	 * spec REQ-DLR-009). Unlike a full synchronize() pass, this does not
	 * fetch from the source, does not run follow-ups, and does not persist a
	 * new synchronization_log entry — a single, synchronous, immediate
	 * re-attempt only.
	 *
	 * @param array $synchronization The synchronization payload (as returned by getSynchronization()->jsonSerialize()).
	 * @param array $payload The raw source object to re-process (the dead-lettered payload).
	 * @param boolean $isTest False by default, preserving `dead-letter-replay` REQ-DLR-009's existing
	 *                        hardcoded-real-write behaviour for its own direct callers. When `true`
	 *                        (execution-trace REQ-005's dry-run sync-entryPoint replay branch,
	 *                        `ExecutionTraceService::replay()` always passes `isTest: !$force`), no
	 *                        target write occurs — reuses `synchronization-engine` REQ-011's
	 *                        existing no-write guarantee rather than inventing a second dry-run
	 *                        mechanism.
	 * @param ExecutionTraceContext|null $trace Active execution trace context for the replay, when called from
	 *                                          `ExecutionTraceService::replay()`.
	 *
	 * @return array{result: array, targetId: string|null} The processSynchronizationObject() outcome.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 */
	public function replaySynchronizationItem(
		array $synchronization,
		array $payload,
		bool $isTest = false,
		?ExecutionTraceContext $trace = null,
	): array {
		$log = new SynchronizationRunLog();
		$flowToken = new FlowToken();

		$result = [
			'objects' => [
				'found' => 1,
				'skipped' => 0,
				'created' => 0,
				'updated' => 0,
				'deleted' => 0,
				'invalid' => 0,
			],
			'contracts' => [],
			'logs' => [],
		];

		return $this->processSynchronizationObject(
			synchronization: $synchronization,
			object: $payload,
			result: $result,
			isTest: $isTest,
			force: true,
			log: $log,
			flowToken: $flowToken,
			trace: $trace,
		);

	}//end replaySynchronizationItem()

	/**
	 * Synchronizes a given synchronization (or a complete source).
	 *
	 * @param array $synchronization The synchronization configuration.
	 * @param bool|null $isTest False by default; for the test endpoint.
	 * @param bool|null $force False by default; if true always update.
	 * @param array|\OCA\OpenRegister\Db\ObjectEntity|null $object Object to synchronize, by reference.
	 * @param string|null $mutationType For single object sync: the mutation
	 *                                  type, 'create', 'update' or
	 *                                  'delete'. Used for syncs to external
	 *                                  sources.
	 * @param string|null $source The source; defaults to the sync source.
	 * @param array|null $data The data; defaults to the sync data.
	 * @param FlowToken|null $flowToken The flow token shared across the run.
	 * @param bool|null $forceDeletion False by default; explicit override for
	 *                                 the deletion-ratio guard (REQ-010). Not
	 *                                 applicable to test runs.
	 * @param string|null $approvalRequestId Bypass token: the id of a specific
	 *                                       approved `approval_request` to consume
	 *                                       when `sourceConfig.requiresApproval`
	 *                                       gates this run (synchronization-engine
	 *                                       REQ-015).
	 * @param ExecutionTraceContext|null $trace The active execution trace context.
	 *                                          When null (a manual/cron-triggered
	 *                                          top-level run), a fresh
	 *                                          `sync`-entryPoint context is minted
	 *                                          and its persistence owned by this
	 *                                          method. When supplied (a
	 *                                          `synchronization` rule inside an
	 *                                          already-traced endpoint pipeline),
	 *                                          reused instead (execution-trace
	 *                                          REQ-001).
	 * @param string|null $triggeredBy What started the run, for the run record:
	 *                                 `rerun` from Run again; null reads the
	 *                                 trace (cron or manual).
	 *
	 * @return array|array|null
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 * @throws Exception
	 * @throws TooManyRequestsHttpException
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 * @spec openspec/specs/synchronization-engine/spec.md
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Backward-compatible optional flags
	 * (isTest/force pre-exist; forceDeletion is mandated by design.md Decision 2/3).
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) $approvalRequestId is an additive,
	 * backward-compatible optional bypass token (hitl-approval-rule-action design.md
	 * Decision 6); all 9 preceding parameters pre-exist this change. $trace is likewise
	 * additive (execution-trace-observability design.md Decision 1).
	 */
	public function synchronize(
		array|ObjectEntity $synchronization,
		?bool $isTest = false,
		?bool $force = false,
		array|\OCA\OpenRegister\Db\ObjectEntity|null &$object = null,
		?string $mutationType = null,
		?string $source = null,
		?array $data = null,
		?FlowToken &$flowToken = null,
		?bool $forceDeletion = false,
		?string $approvalRequestId = null,
		?ExecutionTraceContext $trace = null,
		?string $triggeredBy = null,
	): ?array {
		// Controllers and cron jobs fetch the synchronization as an OpenRegister
		// object (register `openconnector`, schema `synchronization`); hydrate it
		// into the typed value object the engine operates on.
		$synchronization = $this->toSynchronization(synchronization: $synchronization);
		$this->pendingValidationFindings = [];

		if ($flowToken === null) {
			$flowToken = new FlowToken();
		}

		// Execution-trace REQ-001: a manual synchronization run is one of the
		// four traced entry points. When `synchronize()` is invoked directly
		// (no active trace supplied — e.g. a manual run or JobService's own
		// dispatch), mint a fresh `sync`-entryPoint context and own its
		// persistence below. When `$trace` is already supplied (e.g. a
		// `synchronization` rule inside an already-traced endpoint pipeline,
		// EndpointService::processSyncRule()), reuse the SAME context so the
		// sync's steps join the caller's single execution trace instead of
		// starting a disconnected one.
		$ownsTrace = ($trace === null);
		if ($ownsTrace === true) {
			$trace = new ExecutionTraceContext(
				entryPoint: 'sync',
				// `id` first: OpenRegister returns an object's identifier as
				// `id` (mirrored on `@self.id`) and does NOT expose a top-level
				// `uuid`, so reading `uuid` alone always yielded null and every
				// sync-entryPoint trace was stored without an entryPointId.
				entryPointId: (($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? null)),
				triggeredBy: 'manual'
			);
		}

		if ($mutationType !== null && in_array($mutationType, $this::VALID_MUTATION_TYPES) === false) {
			throw new Exception(
				sprintf(
					'Invalid mutation type: %s given. Allowed mutation types are: %s',
					$mutationType,
					implode(', ', $this::VALID_MUTATION_TYPES)
				)
			);
		}

		// Start execution time measurement.
		$startTime = microtime(true);

		// Prepare initial log array.
		//
		// `id` first, `uuid` as the fallback. This read `uuid` alone, and the
		// hydrated synchronization does not carry that key — every other use in
		// this service reads `id` (the contract lookups, the contract's own
		// synchronizationId). So every run log was written with an EMPTY
		// synchronization id, and filtering logs by synchronization returned
		// nothing at all: the rows were there, just unattributable. Measured by
		// trying it — a filtered query came back empty for a run that had just
		// written 374 objects, which reads exactly like "the sync did nothing".
		$log = [
			'synchronizationId' => (($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? null)),
			'result' => [
				'objects' => [
					'found' => 0,
					'skipped' => 0,
					'created' => 0,
					'updated' => 0,
					'deleted' => 0,
					'invalid' => 0,
				],
				'contracts' => [],
				'logs' => [],
			],
			'test' => $isTest,
			'force' => $force,
			'expires' => $this->calculateExpires(...[$this->errorRetention]),
		];

		// Shortcut for intern-to-extern sync.
		if (($synchronization['sourceType'] ?? null) === 'register/schema' && $object !== null) {
			// Build the in-memory log first so it carries a stable uuid for the
			// contract logs; the append-only row is persisted once at the end.
			$log['result']['type'] = 'internToExtern';
			$log = $this->synchronizationLogService->createFromArray(object: $log);

			$contract = $this->synchronizeInternToExtern(
				synchronization: $synchronization,
				object: $object,
				log: $log,
				flowToken: $flowToken,
				isTest: $isTest,
				force: $force,
				mutationType: $mutationType,
				trace: $trace,
			);

			// Write-once finalize of the (append-only) run-log for this branch.
			$this->synchronizationLogService->persist(log: $log);

			if ($ownsTrace === true) {
				$this->persistOwnedTrace(trace: $trace, status: 'success');
			}

			return $contract;
		}//end if

		$log['result']['type'] = 'externToIntern';

		// Build the in-memory log first so it carries a stable uuid for the
		// contract logs; the append-only row is persisted once at the end.
		$log = $this->synchronizationLogService->createFromArray(object: $log);

		// OPEN THE PROGRESS RECORD BEFORE ANY WORK. The run-log above is
		// in-memory until the very end and its schema is appendOnly+immutable,
		// so until this row exists the run is invisible for its whole duration.
		$this->runProgressService?->start(
			synchronizationId: (string)($synchronization['id'] ?? $synchronization['uuid'] ?? ''),
			// Opt-out per synchronization. Defaults ON: a run nobody can watch
			// is the defect being fixed, so invisibility should be the choice,
			// not the default. Also the arm-switch for the overhead control.
			enabled: (bool)($synchronization['sourceConfig']['recordRunProgress'] ?? true),
			// The source as it is now, and what started the run, so a summary
			// per source per day never has to guess (connection-run-monitoring
			// REQ-CRUN-001).
			sourceId: $this->runSourceId(synchronization: $synchronization),
			triggeredBy: SynchronizationRunProgressService::resolveTrigger(
				requested: $triggeredBy,
				traceTrigger: $trace?->getTriggeredBy()
			)
		);

		// Handle full extern-to-intern sync.
		$log = $this->synchronizeExternToIntern(
			synchronization: $synchronization,
			log: $log,
			flowToken: $flowToken,
			isTest: $isTest,
			force: $force,
			source: $source,
			data: $data,
			mutationType: $mutationType,
			forceDeletion: $forceDeletion,
			approvalRequestId: $approvalRequestId,
			trace: $trace
		);

		// A gated, not-yet-approved run already finalized its own log with a
		// `pending_approval` message and made no writes — do not overwrite it
		// with 'Success' (synchronization-engine REQ-015). Nor a resumed run
		// whose source changed after the preview (`approval_superseded`,
		// REQ-INAV-004): it wrote nothing and opened a new request.
		$pausedMessage = $log->getMessage();
		if ($pausedMessage === 'pending_approval' || $pausedMessage === 'approval_superseded') {
			// Terminal for this run even though no work happened — leaving it
			// `running` would show as hung forever.
			$this->runProgressService?->finish(
				status: 'success',
				counters: $this->progressCountersFromLog(log: $log),
				message: $pausedMessage
			);

			if ($ownsTrace === true) {
				$this->persistOwnedTrace(trace: $trace, status: 'short_circuited');
			}

			return $log->jsonSerialize();
		}

		// Finalize log.
		$executionTime = (int)round((microtime(true) - $startTime) * 1000);
		$log->setExecutionTime($executionTime);
		$log->setMessage('Success');
		$log->setExpires($this->calculateExpires(...[$this->successRetention, $this->successRetention]));

		$log = $this->synchronizationLogService->update(log: $log);

		// NOT throttled — the terminal write must always land, or a finished run
		// stays `running` and every watcher reads it as hung.
		$this->runProgressService?->finish(
			status: 'success',
			counters: $this->progressCountersFromLog(log: $log)
		);

		if ($ownsTrace === true) {
			$this->persistOwnedTrace(trace: $trace, status: 'success');
		}

		return $log->jsonSerialize();
	}//end synchronize()

	/**
	 * The source id a run records: the synchronization's `sourceId` as it is now.
	 *
	 * @param array $synchronization The hydrated synchronization.
	 *
	 * @return string|null The source id, or null when the synchronization names none.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-every-run-records-its-source-and-what-started-it-req-crun-001
	 */
	private function runSourceId(array $synchronization): ?string {
		$sourceId = ($synchronization['sourceId'] ?? null);
		if (is_scalar($sourceId) === false || (string)$sourceId === '') {
			return null;
		}

		return (string)$sourceId;
	}//end runSourceId()

	/**
	 * Project a run-log's object counters onto the progress record's scalars.
	 *
	 * Kept in one place so the live counters and the final ones cannot drift
	 * into describing the same run differently.
	 *
	 * @param SynchronizationRunLog $log The run log to read counters from.
	 *
	 * @return array<string, int> The progress counters.
	 */
	private function progressCountersFromLog(SynchronizationRunLog $log): array {
		// No `?? []`: getResult() is declared `: array` and cannot be null, so
		// the coalesce was dead code phpstan correctly refused.
		$result = $log->getResult();
		$objects = ($result['objects'] ?? []);

		return [
			'found' => (int)($objects['found'] ?? 0),
			'processed' => (int)($objects['found'] ?? 0),
			'created' => (int)($objects['created'] ?? 0),
			'updated' => (int)($objects['updated'] ?? 0),
			'deleted' => (int)($objects['deleted'] ?? 0),
			'invalid' => (int)($objects['invalid'] ?? 0),
		];
	}//end progressCountersFromLog()

	/**
	 * Best-effort persist of a `sync`-entryPoint trace this method minted
	 * itself (design.md's "single create per entry point" rule, REQ-004). A
	 * persistence failure MUST NOT fail the synchronization run it is
	 * observing — mirrors the existing best-effort posture of
	 * `captureSyncItemFailure()` in this class.
	 *
	 * @param ExecutionTraceContext $trace The self-minted trace context to persist.
	 * @param string $status running|success|failed|short_circuited.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004
	 */
	private function persistOwnedTrace(ExecutionTraceContext $trace, string $status): void {
		try {
			$this->containerInterface->get(ExecutionTraceService::class)->persist(trace: $trace, status: $status);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'SynchronizationService: failed to persist execution_trace for a self-minted sync trace.',
				[
					'traceId' => $trace->getTraceId(),
					'exception' => $exception->getMessage(),
				]
			);
		}

	}//end persistOwnedTrace()

	/**
	 * Gets id from object as is in the origin.
	 *
	 * A synchronization whose source returns bare-scalar items is coerced
	 * to a `['value' => <scalar>]` shape by the per-item loop in
	 * `synchronizeExternToIntern()` (change sync-engine-scalar-items)
	 * before this method is ever called. Such a synchronization MUST set
	 * `sourceConfig.idPosition` to `'value'` — the default `idPosition`
	 * (`'id'`) will not resolve on the coerced shape and, per the existing
	 * behaviour below, throws a clear `Exception` naming the missing key
	 * rather than silently failing.
	 *
	 * @param array $synchronization The synchronization containing the source config.
	 * @param array $object The object to extract the origin id from.
	 *
	 * @return string The origin id.
	 *
	 * @throws Exception
	 */
	private function getOriginId(array $synchronization, array $object): string {
		// Default ID position is 'id' if not specified in source config.
		$originIdPosition = 'id';
		$sourceConfig = ($synchronization['sourceConfig'] ?? []);

		// Check if a custom ID position is defined in the source configuration.
		if (isset($sourceConfig['idPosition']) === true && empty($sourceConfig['idPosition']) === false) {
			// Override default with custom ID position from config.
			$originIdPosition = $sourceConfig['idPosition'];
		}

		// Create Dot object for easy access to nested array values.
		$objectDot = new Dot($object);

		// Try to get the ID value from the specified position in the object.
		$originId = $objectDot->get($originIdPosition);

		// If no ID was found at the specified position, throw an error.
		if ($originId === null) {
			throw new Exception('Could not find origin id in object for key: ' . $originIdPosition);
		}

		// The synchronization contract stores originId as a string. Coerce
		// scalar source ids (e.g. a numeric latitude or integer id) so they
		// pass validation instead of failing the whole sync.
		if (is_scalar($originId) === true) {
			$originId = (string)$originId;
		}

		// Return the found ID value.
		return $originId;
	}//end getOriginId()

	/**
	 * Compute the new cursor high-watermark from a run's fetched records
	 * (REQ-017, change cdc-incremental-sync) — the maximum value of the
	 * configured `sourceConfig.cursorField` across all fetched records,
	 * mirroring {@see getOriginId()}'s dotted-path-lookup-with-throw
	 * convention (REQ-003) so a record missing the configured field fails
	 * loudly instead of silently producing a too-low watermark that would
	 * permanently skip its siblings on every subsequent run.
	 *
	 * Taking the maximum across ALL fetched records (not the last one
	 * processed) means out-of-order pagination or concurrent per-page
	 * fetching (REQ-002's optimized parallel mode) cannot regress the
	 * watermark.
	 *
	 * @param array $synchronization The synchronization containing sourceConfig.cursorField.
	 * @param array $objectList The records fetched during this run.
	 *
	 * @return string|null The new high-watermark, or null when there is nothing to
	 *                     compute from (no `cursorField` configured, or an empty
	 *                     fetch) — the caller leaves `cursorWatermark` unchanged
	 *                     in that case rather than persisting a null/empty value.
	 *
	 * @throws Exception When a fetched record has no value at the configured
	 *                   `cursorField` path.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017
	 */
	private function computeCursorWatermark(array $synchronization, array $objectList): ?string {
		$sourceConfig = ($synchronization['sourceConfig'] ?? []);
		$cursorField = ($sourceConfig['cursorField'] ?? null);

		if (empty($cursorField) === true || empty($objectList) === true) {
			return null;
		}

		$maxCursor = null;
		foreach ($objectList as $object) {
			if (is_array($object) === false) {
				continue;
			}

			$objectDot = new Dot($object);
			$cursorValue = $objectDot->get($cursorField);

			if ($cursorValue === null) {
				throw new Exception('Could not find cursor field in object for key: ' . $cursorField);
			}

			if (is_scalar($cursorValue) === true) {
				$cursorValue = (string)$cursorValue;
			}

			if ($maxCursor === null || $cursorValue > $maxCursor) {
				$maxCursor = $cursorValue;
			}
		}

		return $maxCursor;
	}//end computeCursorWatermark()

	/**
	 * Clear a Synchronization's stored cursor watermark (REQ-019, change
	 * cdc-incremental-sync).
	 *
	 * Persists `cursorWatermark` as cleared (null/absent). Leaves `syncMode`
	 * and every other field untouched, and performs no target write/delete
	 * of its own — REQ-018's deletion block is keyed on `syncMode`, not on
	 * cursor state, so clearing the watermark alone never re-enables
	 * `deleteInvalidObjects()` for an incremental Synchronization (design.md
	 * Decision 3 / Risks). Following a reset, the Synchronization's next run
	 * resolves `{{ cursor }}` to an empty string (REQ-016's "no prior
	 * watermark" case).
	 *
	 * @param array|ObjectEntity $synchronization The synchronization whose watermark to clear.
	 *
	 * @return array The updated synchronization payload (for response/confirmation).
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019
	 */
	public function resetCursor(array|ObjectEntity $synchronization): array {
		$synchronization = $this->toSynchronization(synchronization: $synchronization);
		$synchronization['cursorWatermark'] = null;
		$this->persistSynchronization(synchronization: $synchronization);

		return $synchronization;
	}//end resetCursor()

	/**
	 * Fetch an object from a specific endpoint.
	 *
	 * @param array $synchronization The synchronization containing the source.
	 * @param string $endpoint The endpoint to request to fetch the desired object.
	 * @param string|int|null $source The source to request if object is in other source than synchronization.
	 *
	 * @return array The resulting object.
	 *
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-source-object-fetching-and-pagination-req-002
	 */
	public function getObjectFromSource(array $synchronization, string $endpoint, string|int|null $source = null): array {
		$sourceId = ($synchronization['sourceId'] ?? null);

		// If source passed down used that instead.
		if ($source !== null) {
			$sourceId = $source;
		}

		$source = $this->findSource(id: $sourceId);

		// Let's get the source config.
		$sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));

		$config = [];
		if (empty($sourceConfig['headers']) === false) {
			$config['headers'] = $sourceConfig['headers'];
		}

		if (empty($sourceConfig['query']) === false) {
			$config['query'] = $sourceConfig['query'];
		}

		if (str_starts_with($endpoint, (string)($source['location'] ?? '')) === true) {
			$endpoint = str_replace(search: (string)($source['location'] ?? ''), replace: '', subject: $endpoint);
		}

		// Make the initial API call, read denotes that we call an endpoint for a single object.
		$response = $this->callLogResponse(
			callLog: $this->callSourceObject(source: $source, endpoint: $endpoint, config: $config, read: true)
		);

		return json_decode($response['body'], true);
	}//end getObjectFromSource()

	/**
	 * Fetches additional data for a given object based on the synchronization configuration.
	 *
	 * This method retrieves extra data using either a dynamically determined endpoint from the object
	 * or a statically defined endpoint in the configuration. The extra data can be merged with the original
	 * object or returned as-is, based on the provided configuration.
	 *
	 * @param array $synchronization The synchronization instance containing configuration details.
	 * @param array $extraDataConfig The configuration array specifying how to retrieve and handle
	 *                               the extra data (dynamic/static endpoint, key, merge flag, etc.).
	 * @param array $object The original object for which extra data needs to be fetched.
	 * @param string|null $originId The origin id used to resolve the static endpoint placeholder.
	 *
	 * @return array The original object merged with the extra data, or the extra data itself.
	 *
	 * @throws Exception|GuzzleException If both endpoint configurations are missing or cannot be determined.
	 */
	private function fetchExtraDataForObject(
		array $synchronization,
		array $extraDataConfig,
		array $object, ?string $originId = null,
	): array {
		if (isset($extraDataConfig[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION]) === false
			&& isset($extraDataConfig[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION]) === false
		) {
			return $object;
		}

		$fetch = new ExtraDataFetch(synchronization: $synchronization, config: $extraDataConfig, object: $object);
		$fetch->originId = $originId;
		$this->resolveExtraDataEndpoint(fetch: $fetch);

		$sourceConfig = ($fetch->synchronization['sourceConfig'] ?? []);
		$unsetKey     = ($extraDataConfig[$this::UNSET_CONFIG_KEY_LOCATION] ?? null);
		if ($unsetKey !== null && isset($sourceConfig[$unsetKey]) === true) {
			unset($sourceConfig[$unsetKey]);
			$fetch->synchronization['sourceConfig'] = $sourceConfig;
		}

		$source = null;
		if (isset($extraDataConfig['source']) === true && is_scalar($extraDataConfig['source']) === true) {
			$source = $extraDataConfig['source'];
		}

		$extraData = $this->getObjectFromSource(synchronization: $fetch->synchronization, endpoint: $fetch->endpoint, source: $source);
		$extraData = $this->fetchExtraDataPerResult(fetch: $fetch, extraData: $extraData);

		// Set new key if configured.
		if (isset($extraDataConfig[$this::KEY_FOR_EXTRA_DATA_LOCATION]) === true) {
			$extraData = [$extraDataConfig[$this::KEY_FOR_EXTRA_DATA_LOCATION] => $extraData];
		}

		// Merge with the earlier fetched object if configured.
		$merge = ($extraDataConfig[$this::MERGE_EXTRA_DATA_OBJECT_LOCATION] ?? null);
		if ($merge === true || $merge === 'true') {
			return array_merge($object, $extraData);
		}

		return $extraData;
	}//end fetchExtraDataForObject()

	/**
	 * Resolve the endpoint of an extra-data fetch: dynamic (read from the
	 * object), static (from the configuration, origin and sub-object ids filled
	 * in, winning over dynamic), then the optional endpoint template.
	 *
	 * @param ExtraDataFetch $fetch The fetch being resolved.
	 *
	 * @return void
	 *
	 * @throws Exception When no endpoint can be determined.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function resolveExtraDataEndpoint(ExtraDataFetch $fetch): void {
		$config = $fetch->config;
		if (isset($config[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION]) === true) {
			$fetch->endpoint = (new Dot($fetch->object))->get($config[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION] ?? null);
		}

		if (isset($config[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION]) === true) {
			$this->staticExtraDataEndpoint(fetch: $fetch);
		}

		$template = ($config[$this::EXTRA_DATA_ENDPOINT_TEMPLATE_LOCATION] ?? null);
		if (is_string($template) === true && $template !== '') {
			$fetch->endpoint = $this->mappingService->renderTemplateString(
				template: $template,
				context: [
					'endpoint' => $fetch->endpoint,
					'object' => $fetch->object,
					'originId' => $fetch->originId,
					'extraDataConfig' => $config,
				]
			);
		}

		if (empty($fetch->endpoint) === true) {
			throw new Exception(
				sprintf(
					'Could not get static or dynamic endpoint, object: %s',
					json_encode($fetch->object)
				)
			);
		}
	}//end resolveExtraDataEndpoint()

	/**
	 * The static endpoint with `{{ originId }}` and `{{ subObjectId }}` filled in.
	 *
	 * The origin id is the one passed in, else the one at `endpointIdLocation`,
	 * else the synchronization's origin id of the object.
	 *
	 * @param ExtraDataFetch $fetch The fetch being resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function staticExtraDataEndpoint(ExtraDataFetch $fetch): void {
		$config = $fetch->config;
		if ($fetch->originId === null) {
			$fetch->originId = $this->getOriginId(synchronization: $fetch->synchronization, object: $fetch->object);
		}

		if (isset($config['endpointIdLocation']) === true) {
			$fetch->originId = (new Dot($fetch->object))->get($config['endpointIdLocation']);
		}

		if ($fetch->originId === null) {
			$fetch->originId = $this->getOriginId(synchronization: $fetch->synchronization, object: $fetch->object);
		}

		$endpoint = str_replace(search: '{{ originId }}', replace: $fetch->originId, subject: $config[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION]);
		$endpoint = str_replace(search: '{{originId}}', replace: $fetch->originId, subject: $endpoint);

		$subObjectId = null;
		if (isset($config['subObjectId']) === true) {
			$subObjectId = (new Dot($fetch->object))->get($config['subObjectId']);
		}

		if ($subObjectId !== null) {
			$endpoint = str_replace(search: '{{ subObjectId }}', replace: $subObjectId, subject: $endpoint);
			$endpoint = str_replace(search: '{{subObjectId}}', replace: $subObjectId, subject: $endpoint);
		}

		$fetch->endpoint = $endpoint;
	}//end staticExtraDataEndpoint()

	/**
	 * Fetch extra data for each result of the extra data, when `extraDataConfigPerResult` asks for it.
	 *
	 * @param ExtraDataFetch $fetch     The fetch that ran.
	 * @param array          $extraData The extra data fetched.
	 *
	 * @return mixed The extra data, or its results each with their own extra data.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function fetchExtraDataPerResult(ExtraDataFetch $fetch, array $extraData): mixed {
		if (isset($fetch->config['extraDataConfigPerResult']) === false) {
			return $extraData;
		}

		$results = $extraData;
		if (isset($fetch->config['resultsLocation']) === true) {
			$results = (new Dot($extraData))->get($fetch->config['resultsLocation']);
		}

		foreach ($results as $key => $result) {
			$results[$key] = $this->fetchExtraDataForObject(
				synchronization: $fetch->synchronization,
				extraDataConfig: $fetch->config['extraDataConfigPerResult'],
				object: $result,
				originId: $fetch->originId
			);
		}

		return $results;
	}//end fetchExtraDataPerResult()

	/**
	 * Fetches multiple extra data entries for an object based on the source configuration.
	 *
	 * This method iterates through a list of extra data configurations, fetches the additional data for each configuration,
	 * and merges it with the original object.
	 *
	 * @param array $synchronization The synchronization instance containing configuration details.
	 * @param array $sourceConfig The source configuration containing extra data retrieval settings.
	 * @param array $object The original object for which extra data needs to be fetched.
	 *
	 * @return array The updated object with all fetched extra data merged into it.
	 * @throws GuzzleException
	 */
	private function fetchMultipleExtraData(array $synchronization, array $sourceConfig, array $object): array {
		if (isset($sourceConfig[$this::EXTRA_DATA_CONFIGS_LOCATION]) === true) {
			foreach ($sourceConfig[$this::EXTRA_DATA_CONFIGS_LOCATION] as $extraDataConfig) {
				$object = array_merge(
					$object,
					$this->fetchExtraDataForObject(
						synchronization: $synchronization,
						extraDataConfig: $extraDataConfig,
						object: $object
					)
				);
			}
		}

		return $object;
	}//end fetchMultipleExtraData()

	/**
	 * Maps a given object using a source hash mapping configuration.
	 *
	 * This function retrieves a hash mapping configuration for a synchronization instance, if available,
	 * and applies it to the input object using the mapping service.
	 *
	 * @param array $synchronization The synchronization instance containing the hash mapping configuration.
	 * @param array $object The input object to be mapped.
	 *
	 * @return array|Exception The mapped object, or the original object if no mapping is found.
	 * @throws LoaderError
	 * @throws SyntaxError
	 */
	private function mapHashObject(array $synchronization, array $object): array|Exception {
		if (empty($synchronization['sourceHashMapping']) === false) {
			try {
				$sourceHashMapping = $this->orObjectService->find(
					id: (string)$synchronization['sourceHashMapping'],
					register: 'integriq',
					schema: 'mapping'
				);
			} catch (DoesNotExistException $exception) {
				return new Exception($exception->getMessage());
			}

			// Execute mapping if found.
			if ($sourceHashMapping !== null) {
				return $this->mappingService->executeMapping(mapping: $sourceHashMapping, input: $object);
			}
		}

		return $object;
	}//end mapHashObject()

	/**
	 * Deletes invalid objects associated with a synchronization.
	 *
	 * This function identifies and removes objects that are no longer valid or do not exist
	 * in the source data for a given synchronization. It compares the target IDs from the
	 * synchronization contract with the synchronized target IDs and deletes the unmatched ones.
	 *
	 * Two safety guards gate the bulk source-diff cleanup path (the
	 * `$deleteRestriction === false` case; the single-object event-driven
	 * delete path is exempt from both — see the ratio-guard block below):
	 * - `$fetchComplete === false` unconditionally skips deletion — a known
	 *   incomplete fetch is never a safe basis for a diff-based cleanup.
	 *   `$forceDeletion` does NOT bypass this check.
	 * - A deletion-ratio guard aborts the pass when the number of candidate
	 *   deletions exceeds `sourceConfig.deletionRatioThreshold` (default
	 *   `self::DEFAULT_DELETION_RATIO_THRESHOLD`) of the synchronization's
	 *   total existing contracts, unless `$forceDeletion === true`. Below
	 *   `self::MIN_CONTRACTS_FOR_DELETION_RATIO_GUARD` total contracts the
	 *   ratio guard is skipped (see that constant's docblock).
	 *
	 * @param array $synchronization The synchronization entity to process.
	 * @param array|null $synchronizedTargetIds An array of target IDs that are still valid in the source.
	 * @param bool $deleteRestriction Sets if deletion should be restricted to identifiers in $data.
	 * @param array $data The data to be checked when $deleteRestriction is true.
	 * @param bool $fetchComplete Whether the fetch preceding this call completed
	 *                            (REQ-009). Defaults `true` (today's implicit
	 *                            assumption) so direct callers that are not the
	 *                            extern→intern pipeline are unaffected.
	 * @param bool $forceDeletion Explicit override for the deletion-ratio guard.
	 *                            Distinct from the pre-existing `$force`
	 *                            parameter used elsewhere in this class (see
	 *                            design.md Decision 2) — never bypasses the
	 *                            fetch-completeness check above.
	 * @param array|null $guardInfo By-reference output parameter: populated with
	 *                              guard outcome details (`guarded`, `reason`,
	 *                              `ratio`, `threshold`, `candidateCount`,
	 *                              `totalContracts`) when applicable.
	 *
	 * @return int The count of objects that were deleted.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface|\OCP\DB\Exception If any database or
	 *                                                                                  deletion error occurs.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-source-deleted-rows-are-removed-only-under-the-shared-deletion-safety-guard-req-005
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Backward-compatible optional flags
	 * (deleteRestriction pre-exists; fetchComplete/forceDeletion are mandated by
	 * design.md Decision 2 as appended, defaulting-to-current-behaviour parameters).
	 */
	public function deleteInvalidObjects(
		array|ObjectEntity $synchronization,
		?array $synchronizedTargetIds = [],
		bool $deleteRestriction = false,
		array $data = [],
		bool $fetchComplete = true,
		bool $forceDeletion = false,
		?array &$guardInfo = null,
	): int {
		$run = new DeletionRun();
		$run->synchronization = $this->toSynchronization(synchronization: $synchronization);
		$run->synchronizationId = (($run->synchronization['id'] ?? null) ?? ($run->synchronization['uuid'] ?? null));
		$run->sourceConfig = $this->callService->applyConfigDot(($run->synchronization['sourceConfig'] ?? []));
		$run->keptTargetIds = $synchronizedTargetIds;
		$run->deleteRestriction = $deleteRestriction;
		$run->data = $data;
		$run->forceDeletion = $forceDeletion;
		$run->guardInfo = $guardInfo;
		$run->applier = new DisappearanceApplier();

		$refusal = $this->deletionRefusal(run: $run, fetchComplete: $fetchComplete);
		if ($refusal !== null) {
			$guardInfo = $refusal;
			return 0;
		}

		$deletedObjectsCount = 0;
		switch ($run->synchronization['targetType'] ?? null) {
			case 'register/schema':
				$deletedObjectsCount = $this->deleteInvalidRegisterObjects(run: $run);
				break;
			case 'nextcloud-table':
				$deletedObjectsCount = $this->deleteInvalidTableRows(
					synchronization: $run->synchronization,
					synchronizedTargetIds: $synchronizedTargetIds,
					deleteRestriction: $deleteRestriction,
					data: $data
				);
				break;
		}

		$guardInfo = $run->guardInfo;

		return $deletedObjectsCount;
	}//end deleteInvalidObjects()

	/**
	 * Read the disappearance policy of a deletion pass and decide whether the pass may run.
	 *
	 * @param DeletionRun $run           The deletion pass; its policy, values and run time are set.
	 * @param bool        $fetchComplete Whether the preceding fetch completed.
	 *
	 * @return array|null The guard outcome when the pass is refused, otherwise null.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018
	 */
	private function deletionRefusal(DeletionRun $run, bool $fetchComplete): ?array {
		// REQ-SOR-002 and REQ-CMKT-003: a disappearance policy, or retirement values,
		// this engine does not know are refused rather than read as the default,
		// because silently deleting under a misspelled policy is the failure the
		// declaration exists to prevent.
		try {
			$run->policy = DisappearancePolicy::fromSourceConfig($run->sourceConfig);
			$run->values = $run->applier->valuesFrom(sourceConfig: $run->sourceConfig);
		} catch (\InvalidArgumentException $policyException) {
			return $this->refuseDeletion(
				run: $run,
				refusal: [
					'reason' => 'unknown_disappearance_policy',
					'why' => $policyException->getMessage(),
					'extra' => ['message' => $policyException->getMessage()],
				]
			);
		}//end try

		$run->runAt = gmdate('c');

		// REQ-018: never delete against an incremental synchronization, whoever calls,
		// even when its fetch was complete for what it asked for.
		if ((string)($run->synchronization['syncMode'] ?? 'full') === 'incremental') {
			return $this->refuseDeletion(
				run: $run,
				refusal: ['reason' => 'incremental_mode', 'why' => 'synchronization is in incremental sync mode']
			);
		}

		if ($fetchComplete === false) {
			return $this->refuseDeletion(
				run: $run,
				refusal: ['reason' => 'fetch_incomplete', 'why' => 'preceding fetch did not complete']
			);
		}

		return null;
	}//end deletionRefusal()

	/**
	 * Refuse a deletion pass: log it, dispatch the guarded event and report why.
	 *
	 * @param DeletionRun $run     The deletion pass.
	 * @param array       $refusal `reason` (machine-readable), `why` (as logged) and optional `extra` guard fields.
	 *
	 * @return array The guard outcome.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function refuseDeletion(DeletionRun $run, array $refusal): array {
		$guardInfo = array_merge(
			[
				'guarded' => true,
				'reason' => $refusal['reason'],
				'ratio' => null,
				'threshold' => null,
				'candidateCount' => null,
				'totalContracts' => null,
			],
			($refusal['extra'] ?? [])
		);
		$this->logger->warning(
			'deleteInvalidObjects: skipped — ' . $refusal['why'],
			['synchronizationId' => $run->synchronizationId]
		);
		$this->dispatchDeletionGuardedEvent(synchronizationId: (string)$run->synchronizationId, reason: $refusal['reason']);

		return $guardInfo;
	}//end refuseDeletion()

	/**
	 * The register/schema branch of deleteInvalidObjects(): retire the target objects
	 * whose contracts the source no longer carries.
	 *
	 * Only the target ids are read for every contract. The full contract bodies are read
	 * only for the contracts being retired, after the ratio guard: reading all of them up
	 * front held 1.39 GB for a 19,822-contract synchronization that had deleted nothing
	 * (measured 2026-08-14 on data.overheid.nl).
	 *
	 * @param DeletionRun $run The deletion pass.
	 *
	 * @return int The count of objects that were deleted.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function deleteInvalidRegisterObjects(DeletionRun $run): int {
		$targetIdParts = explode(separator: '/', string: (string)($run->synchronization['targetId'] ?? null));
		if (count($targetIdParts) !== 2 || $targetIdParts[0] === '' || $targetIdParts[1] === '') {
			$this->logger->warning(
				'deleteInvalidObjects: targetId not in register/schema format',
				['synchronizationId' => $run->synchronizationId, 'targetId' => ($run->synchronization['targetId'] ?? null)]
			);
			return 0;
		}

		[$run->registerId, $run->schemaId] = $targetIdParts;

		$contractObjects = $this->findAllContractObjects(filters: ['synchronizationId' => $run->synchronizationId]);
		$allContractTargetIds = $this->contractTargetIds(contractObjects: $contractObjects);
		$targetIdsToDelete = array_diff($allContractTargetIds, ($run->keptTargetIds ?? []));

		$counts = ['candidates' => count($targetIdsToDelete), 'total' => count($allContractTargetIds)];
		if ($this->deletionRatioGuardTrips(run: $run, counts: $counts) === true) {
			return 0;
		}

		[$sourceIds, $run->contractsByTargetId] = $this->contractsForTargets(contractObjects: $contractObjects, targetIds: $targetIdsToDelete);

		if ($run->deleteRestriction === true) {
			$encodedData = json_encode($run->data);
			$targetIdsToDelete = array_filter(
				$targetIdsToDelete,
				fn (string|int $targetId) => str_contains($encodedData, $sourceIds[$targetId])
			);
		}

		$deletedObjectsCount = 0;
		foreach ($targetIdsToDelete as $targetIdToDelete) {
			$deletedObjectsCount += $this->retireDisappearedTarget(run: $run, targetId: $targetIdToDelete);
		}

		// REQ-SOR-003: the run reports how many records the source stopped carrying.
		$run->guardInfo['endedCount'] = $run->policyCounts['ended'];
		$run->guardInfo['flaggedCount'] = $run->policyCounts['flagged'];
		$run->guardInfo['purgedCount'] = $run->purgeInfo['purgedCount'];
		$run->guardInfo['purgeRefusals'] = $run->purgeInfo['purgeRefusals'];
		$run->guardInfo['disappearancePolicy'] = $run->policy;

		return $deletedObjectsCount;
	}//end deleteInvalidRegisterObjects()

	/**
	 * The target ids of a synchronization's contracts.
	 *
	 * @param array $contractObjects The contract objects.
	 *
	 * @return array The target ids, skipping contracts without one.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function contractTargetIds(array $contractObjects): array {
		$targetIds = [];
		foreach ($contractObjects as $contractObject) {
			$targetId = ($contractObject->getObject()['targetId'] ?? null);
			if ($targetId !== null) {
				$targetIds[] = $targetId;
			}
		}

		return $targetIds;
	}//end contractTargetIds()

	/**
	 * The origin ids and full contracts of the contracts with the given target ids.
	 *
	 * @param array $contractObjects The contract objects.
	 * @param array $targetIds       The target ids to read.
	 *
	 * @return array{0: array, 1: array} The origin ids and the contracts, both keyed by target id.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function contractsForTargets(array $contractObjects, array $targetIds): array {
		$wantedTargetIds = array_flip($targetIds);
		$sourceIds = [];
		$contractsByTargetId = [];
		foreach ($contractObjects as $contractObject) {
			$contract = $contractObject->jsonSerialize();
			$targetId = ($contract['targetId'] ?? null);
			if ($targetId === null || isset($wantedTargetIds[$targetId]) === false) {
				continue;
			}

			$sourceIds[$targetId] = ($contract['originId'] ?? null);
			$contractsByTargetId[$targetId] = $contract;
		}

		return [$sourceIds, $contractsByTargetId];
	}//end contractsForTargets()

	/**
	 * Apply the deletion-ratio guard to a bulk deletion pass.
	 *
	 * The guard is skipped for a restricted (single-object) delete and below
	 * MIN_CONTRACTS_FOR_DELETION_RATIO_GUARD contracts. It trips when the share of
	 * candidates exceeds the threshold, unless deletion is forced.
	 *
	 * @param DeletionRun $run    The deletion pass; its guard outcome is updated.
	 * @param array       $counts `candidates`, the number of targets to delete, and `total`, the contracts with a target.
	 *
	 * @return bool True when the guard tripped and nothing may be deleted.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function deletionRatioGuardTrips(DeletionRun $run, array $counts): bool {
		$candidateCount = $counts['candidates'];
		$totalContracts = $counts['total'];
		$run->guardInfo = ['guarded' => false, 'reason' => null, 'ratio' => null, 'threshold' => null];
		if ($run->deleteRestriction === true || $totalContracts < self::MIN_CONTRACTS_FOR_DELETION_RATIO_GUARD) {
			return false;
		}

		$ratio = ($candidateCount / $totalContracts);
		$threshold = ($run->sourceConfig['deletionRatioThreshold'] ?? self::DEFAULT_DELETION_RATIO_THRESHOLD);
		if ($ratio > $threshold && $run->forceDeletion === false) {
			$run->guardInfo = [
				'guarded' => true,
				'reason' => 'ratio_threshold_exceeded',
				'ratio' => $ratio,
				'threshold' => $threshold,
				'candidateCount' => $candidateCount,
				'totalContracts' => $totalContracts,
			];
			$this->logger->warning(
				'deleteInvalidObjects: deletion ratio guard tripped',
				$run->guardInfo + ['synchronizationId' => $run->synchronizationId]
			);
			$this->dispatchDeletionGuardedEvent(
				synchronizationId: (string)$run->synchronizationId,
				reason: 'ratio_threshold_exceeded',
				ratio: $ratio,
				threshold: $threshold,
				candidateCount: $candidateCount,
				totalContracts: $totalContracts
			);
			return true;
		}//end if

		$run->guardInfo['ratio'] = $ratio;
		$run->guardInfo['threshold'] = $threshold;

		return false;
	}//end deletionRatioGuardTrips()

	/**
	 * Retire one target object the source no longer carries, under the declared policy.
	 *
	 * `purge` removes the object and its files for good (REQ-SDP-001), `markEnded` and
	 * `keepAndFlag` keep it and record the absence (REQ-SOR-003), and `delete` deletes it.
	 *
	 * @param DeletionRun $run      The deletion pass, with the contracts to retire by target id.
	 * @param string|int  $targetId The target object id.
	 *
	 * @return int 1 when the object was deleted, otherwise 0.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function retireDisappearedTarget(DeletionRun $run, string|int $targetId): int {
		$contract = ($run->contractsByTargetId[$targetId] ?? null);
		$targetObject = $this->findTargetInScope(run: $run, targetId: $targetId);
		if ($targetObject === null || $contract === null) {
			return 0;
		}

		if ($run->policy === DisappearancePolicy::PURGE) {
			$this->purgeTarget(
				synchronization: $run->synchronization,
				contract: $contract,
				trigger: self::PURGE_TRIGGER_FULL_RUN,
				reference: null,
				purgeInfo: $run->purgeInfo
			);
			return 0;
		}

		if ($run->policy !== DisappearancePolicy::DELETE) {
			$this->applyDisappearancePolicy(
				policy: $run->policy,
				applier: $run->applier,
				targetObject: $targetObject,
				contract: $contract,
				registerId: $run->registerId,
				schemaId: $run->schemaId,
				runAt: $run->runAt,
				counts: $run->policyCounts,
				values: $run->values
			);
			return 0;
		}

		$contract = $this->updateTarget(synchronizationContract: $contract, action: 'delete');
		$this->persistContract(contract: $contract);

		return 1;
	}//end retireDisappearedTarget()

	/**
	 * Find a target object only when it lives in the pass's register and schema.
	 *
	 * This prevents a uuid collision across magic tables from deleting a foreign
	 * object (OR#1638, hydra#309). RBAC and multitenancy are off to match the reach
	 * of the former unscoped join.
	 *
	 * @param DeletionRun $run      The deletion pass.
	 * @param string|int  $targetId The target object id.
	 *
	 * @return mixed The target object, or null when it is gone, out of scope or the lookup failed.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function findTargetInScope(DeletionRun $run, string|int $targetId): mixed {
		try {
			return $this->orObjectService->find(
				id: (string)$targetId,
				register: $run->registerId,
				schema: $run->schemaId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			return null;
		} catch (\Throwable $throwable) {
			$this->logger->warning(
				'deleteInvalidObjects: Scope check failed',
				[
					'synchronizationId' => $run->synchronizationId,
					'targetId' => $targetId,
					'error' => $throwable->getMessage(),
				]
			);
			return null;
		}//end try
	}//end findTargetInScope()

	/**
	 * Apply a non-deleting disappearance policy to one record.
	 *
	 * The record stays where it is. Under `markEnded` it gains an end date
	 * taken from the run that first did not see it; under `keepAndFlag` its
	 * values are left exactly as they are and only the absence is written,
	 * with both timestamps.
	 *
	 * @param string $policy The declared policy.
	 * @param DisappearanceApplier $applier The applier.
	 * @param ObjectEntity $targetObject The object the source stopped carrying.
	 * @param array<string,mixed> $contract The contract that maintains it.
	 * @param string $registerId The target register.
	 * @param string $schemaId The target schema.
	 * @param string $runAt ISO timestamp of this run.
	 * @param array<string,int> $counts Per-policy counts, updated in place.
	 * @param array<string,scalar|null> $values Declared retirement values written onto the object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#requirement-an-ended-record-keeps-its-history-and-says-when-the-source-dropped-it-req-sor-003
	 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-withdrawn-course-is-retired-never-deleted-req-cmkt-003
	 */
	private function applyDisappearancePolicy(
		string $policy,
		DisappearanceApplier $applier,
		ObjectEntity $targetObject,
		array $contract,
		string $registerId,
		string $schemaId,
		string $runAt,
		array &$counts,
		array $values=[],
	): void {
		try {
			$objectData = $applier->applyToObject(
				policy: $policy,
				objectData: $targetObject->getObject(),
				runAt: $runAt,
				values: $values
			);

			$this->orObjectService->saveObject(
				object: $objectData,
				register: $registerId,
				schema: $schemaId,
				uuid: (string)$targetObject->getUuid()
			);

			$this->persistContract(
				contract: $applier->applyToContract(policy: $policy, contract: $contract, runAt: $runAt)
			);
		} catch (\Throwable $throwable) {
			$this->logger->warning(
				'deleteInvalidObjects: disappearance policy could not be applied',
				[
					'policy' => $policy,
					'targetId' => (string)$targetObject->getUuid(),
					'error' => $throwable->getMessage(),
				]
			);

			return;
		}//end try

		$counts[$applier->countKey(policy: $policy)]++;
	}//end applyDisappearancePolicy()

	/**
	 * `nextcloud-table` branch of {@see deleteInvalidObjects()} — extracted to
	 * its own method (rather than inlined in the `switch`) purely to keep
	 * `deleteInvalidObjects()`'s own cyclomatic complexity/length from
	 * growing further; behaviourally this IS the register/schema branch's
	 * diff-and-delete loop without the OR-object scope-check step (a Tables
	 * row is not an OpenRegister object, so there is nothing to scope-check
	 * against). Per tables-bridge REQ-005, this diff — and the shared
	 * `updateTarget()` delete path it calls — IS the composition point
	 * `sync-safety-guardrails`'s run-completeness and deletion-ratio guard is
	 * expected to wrap; this method adds no `nextcloud-table`-specific
	 * threshold or bypass of its own.
	 *
	 * @param array $synchronization The synchronization entity to process.
	 * @param array|null $synchronizedTargetIds Target ids that are still valid in the source.
	 * @param bool $deleteRestriction Sets if deletion should be restricted to identifiers in $data.
	 * @param array $data The data to be checked when $deleteRestriction is true.
	 *
	 * @return int The count of rows that were deleted.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-source-deleted-rows-are-removed-only-under-the-shared-deletion-safety-guard-req-005
	 */
	private function deleteInvalidTableRows(
		array $synchronization,
		?array $synchronizedTargetIds,
		bool $deleteRestriction,
		array $data,
	): int {
		$deletedCount = 0;

		// The synchronization identifier is the OpenRegister id, falling
		// back to the uuid when the id is not separately populated.
		$synchronizationId = (($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? null));

		$contractObjects = $this->findAllContractObjects(filters: ['synchronizationId' => $synchronizationId]);
		$allContractTargetIds = [];
		$allContractSourceIds = [];
		$contractsByTargetId = [];
		foreach ($contractObjects as $contractObject) {
			$contract = $contractObject->jsonSerialize();
			$contractTargetId = ($contract['targetId'] ?? null);
			if ($contractTargetId !== null) {
				$allContractTargetIds[] = $contractTargetId;
				$allContractSourceIds[$contractTargetId] = ($contract['originId'] ?? null);
				$contractsByTargetId[$contractTargetId] = $contract;
			}
		}

		// Initialize $synchronizedTargetIds as empty array if null.
		if ($synchronizedTargetIds === null) {
			$synchronizedTargetIds = [];
		}

		// Rows whose contracts no longer appear among this run's
		// synchronized target ids are candidates for deletion.
		$targetIdsToDelete = array_diff($allContractTargetIds, $synchronizedTargetIds);
		if ($deleteRestriction === true) {
			$encodedData = json_encode($data);
			$targetIdsToDelete = array_filter(
				$targetIdsToDelete,
				function (string|int $targetId) use ($encodedData, $allContractSourceIds) {
					$sourceId = $allContractSourceIds[$targetId];
					return str_contains($encodedData, $sourceId);
				}
			);
		}

		foreach ($targetIdsToDelete as $targetIdToDelete) {
			$synchronizationContract = ($contractsByTargetId[$targetIdToDelete] ?? null);
			if ($synchronizationContract === null) {
				continue;
			}

			// The updateTarget() call returns an array, so no is_array() guard.
			$synchronizationContract = $this->updateTarget(synchronizationContract: $synchronizationContract, action: 'delete');
			$this->persistContract(contract: $synchronizationContract);

			$deletedCount++;
		}//end foreach

		return $deletedCount;
	}//end deleteInvalidTableRows()

	/**
	 * Dispatch a SynchronizationDeletionGuardedEvent, when the event
	 * dispatcher was resolved (see the constructor's lazy resolution block).
	 *
	 * @param string $synchronizationId The guarded synchronization's id.
	 * @param string $reason `fetch_incomplete` or `ratio_threshold_exceeded`.
	 * @param float|null $ratio The computed deletion ratio, when applicable.
	 * @param float|null $threshold The configured/default threshold, when applicable.
	 * @param integer|null $candidateCount The number of contracts that would have been deleted.
	 * @param integer|null $totalContracts The total number of existing contracts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	private function dispatchDeletionGuardedEvent(
		string $synchronizationId,
		string $reason,
		?float $ratio = null,
		?float $threshold = null,
		?int $candidateCount = null,
		?int $totalContracts = null,
	): void {
		if ($this->eventDispatcher === null) {
			return;
		}

		$this->eventDispatcher->dispatchTyped(
			new SynchronizationDeletionGuardedEvent(
				synchronizationId: $synchronizationId,
				reason: $reason,
				ratio: $ratio,
				threshold: $threshold,
				candidateCount: $candidateCount,
				totalContracts: $totalContracts
			)
		);

	}//end dispatchDeletionGuardedEvent()

	/**
	 * Recursively sort an associative array by key.
	 *
	 * @param mixed $array The array to sort.
	 *
	 * @return bool Whether or not the sort is successful.
	 */
	public function sortNestedArray(mixed &$array): bool {
		if (is_array($array) === false) {
			return false;
		}

		ksort($array);
		foreach (array_keys($array) as $k) {
			$this->sortNestedArray(array: $array[$k]);
		}

		return true;
	}//end sortNestedArray()

	/**
	 * Hash an object in a unified order, so the order in which keys are given does not influence the hash.
	 *
	 * @param array $object The object to hash.
	 *
	 * @return string The object hash.
	 */
	private function hashObject(array $object): string {
		$this->sortNestedArray(array: $object);

		return md5(serialize($object));
	}//end hashObject()

	/**
	 * Synchronize a contract.
	 *
	 * @param array $synchronizationContract The contract payload array to synchronize.
	 * @param FlowToken $flowToken The flow token shared across the run. Declared
	 *                             BEFORE the optional parameters: a required
	 *                             parameter after an optional one is deprecated
	 *                             as of PHP 8.1, and every caller passes this
	 *                             by name, so the position is free to move.
	 * @param array|null $synchronization The synchronization configuration.
	 * @param array $object The object being synchronized.
	 * @param bool|null $isTest False by default, added for the test endpoint.
	 * @param bool|null $force False by default; if true, always update.
	 * @param SynchronizationRunLog|null $log The log to update.
	 * @param string|null $mutationType For single object sync: the mutation type,
	 *                                  'create', 'update' or 'delete'. Used for
	 *                                  syncs to external sources.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, threaded through
	 *                                          to the outbound target dispatch (execution-trace REQ-001).
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 *
	 * @return array|Exception
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws GuzzleException
	 */
	public function synchronizeContract(
		array $synchronizationContract,
		FlowToken &$flowToken,
		?array $synchronization = null,
		array &$object = [],
		?bool $isTest = false,
		?bool $force = false,
		?SynchronizationRunLog $log = null,
		?string $mutationType = null,
		?ExecutionTraceContext $trace = null,
	): array|Exception {
		$run = new ContractRun();
		$run->synchronization = $synchronization;
		$run->flowToken = $flowToken;
		$run->isTest = $isTest;
		$run->mutationType = $mutationType;
		$run->trace = $trace;
		$run->force = $force;
		$run->object = &$object;
		$run->sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));
		$run->contractLog = $this->openContractLog(run: $run, synchronizationContract: $synchronizationContract);
		if ($run->contractLog !== null) {
			$run->contractLog['synchronizationLogId'] = $log->getId();
		}

		$flowToken->setSyncInputOriginal($object);
		if ($this->extraDataFetchedBeforeConditions(sourceConfig: $run->sourceConfig) === false) {
			$object = $this->fetchMultipleExtraData(synchronization: $synchronization, sourceConfig: $run->sourceConfig, object: $object);
		}

		$flowToken->setSyncOutputAmended($object);

		// Some fields can make the object look changed when it is not, so the hash is taken over the mapped hash object.
		$hashObject = $this->mapHashObject(synchronization: $synchronization, object: $object);
		$run->originHash = $this->hashObject(object: $hashObject);

		$run->mapping = $this->findSourceTargetMapping(synchronization: $synchronization);
		if ($run->mapping instanceof Exception) {
			return $run->mapping;
		}

		if ($force === false && $this->contractIsCurrent(run: $run, contract: $synchronizationContract) === true) {
			$synchronizationContract['sourceLastChecked'] = (new DateTime())->format(DateTime::ATOM);

			return [
				'log' => $this->closeContractLog(contractLog: $run->contractLog),
				'contract' => $synchronizationContract,
				'resultAction' => 'skip',
			];
		}

		$synchronizationContract['originHash'] = $run->originHash;
		$synchronizationContract['sourceLastChanged'] = (new DateTime())->format(DateTime::ATOM);
		$synchronizationContract['sourceLastChecked'] = (new DateTime())->format(DateTime::ATOM);

		return $this->writeChangedContract(run: $run, contract: $synchronizationContract);
	}//end synchronizeContract()

	/**
	 * Map a changed object, write it to the target and record the contract.
	 *
	 * A test run stops after the mapping and the `before` rules, and writes nothing.
	 *
	 * @param ContractRun $run      The contract run; its object is updated in place as it is mapped.
	 * @param array       $contract The synchronization contract, marked as changed.
	 *
	 * @return array The contract log, the contract and the result action.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function writeChangedContract(ContractRun $run, array $contract): array {
		$run->objectBeforeMapping = $run->object;
		$this->transformContractObject(run: $run);
		$contract = $this->stampTargetSynced(contract: $contract, object: $run->object);

		if ($run->isTest === true) {
			return [
				'log' => $this->closeContractLog(contractLog: $run->contractLog, fields: ['targetResult' => 'test']),
				'contract' => $contract,
				'resultAction' => 'skip',
			];
		}

		$contract = $this->updateTarget(
			synchronizationContract: $contract,
			targetObject: $run->object,
			mutationType: $run->mutationType,
			trace: $run->trace,
			synchronization: $run->synchronization
		);
		$contract = $this->persistContractBeforeAfterRules(contract: $contract, synchronization: $run->synchronization);
		$this->runAfterRules(run: $run, contract: $contract);

		$contractLog = $this->closeContractLog(
			contractLog: $run->contractLog,
			fields: ['targetResult' => ($contract['targetLastAction'] ?? null)]
		);
		$contract = $this->finalizeContract(contract: $contract);

		$resultLog = [];
		if (empty($contractLog) === false) {
			$resultLog = $contractLog;
		}

		// The resultAction here represents an update/create.
		return ['log' => $resultLog, 'contract' => $contract, 'resultAction' => 'update'];
	}//end writeChangedContract()

	/**
	 * Map the run's object to its target shape and run the `before` rules on it.
	 *
	 * The object is updated in place after each step, so the caller holds the latest
	 * shape even when a later step throws.
	 *
	 * @param ContractRun $run The contract run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function transformContractObject(ContractRun $run): void {
		if ($run->mapping !== null && $run->mutationType !== 'delete') {
			$run->flowToken->setSyncOutputOriginal($run->object);
			$run->object = $this->mappingService->executeMapping(mapping: $run->mapping, input: $run->object);
			$run->flowToken->setSyncOutputAmended($run->object);
		}

		if ($run->contractLog !== null) {
			$run->contractLog['target'] = $run->object;
		}

		$run->object = $this->replaceRelatedOriginIds(
			object: $run->object,
			config: $run->sourceConfig['idsToReplaceWithTargetIdsBeforeRules'] ?? [],
			replaceIdWithTargetId: true
		);
		$run->flowToken->setSyncOutputAmended($run->object);
		$run->object = $this->applyBeforeRules(run: $run, object: $run->object);
	}//end transformContractObject()

	/**
	 * Create the per-contract log entry for a synchronizeContract() run.
	 *
	 * `logs: false` in the source config turns the per-contract log off. On a steady-state
	 * run of an unchanged source this log is the dominant write (measured on 374 unchanged
	 * GitHub repos: 17.2 s to decide there was nothing to do), so the flag skips it.
	 * It defaults to on.
	 *
	 * @param ContractRun $run                     The contract run, before its object is amended.
	 * @param array       $synchronizationContract The contract being synchronized.
	 *
	 * @return array|null The created log, or null when the contract has no id or logging is off.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function openContractLog(ContractRun $run, array $synchronizationContract): ?array {
		$loggingEnabled = (($run->sourceConfig['logs'] ?? true) !== false);
		if (isset($synchronizationContract['id']) === false
			|| $this->synchronizationContractLogService === null
			|| $loggingEnabled === false
		) {
			return null;
		}

		return $this->synchronizationContractLogService->createFromArray(
			object: [
				'synchronizationId' => ($run->synchronization['id'] ?? null),
				'synchronizationContractId' => $synchronizationContract['id'],
				'source' => $run->object,
				'test' => $run->isTest,
				'force' => $run->force,
				'expires' => $this->calculateExpires(...[$this->errorContractRetention])?->format(DateTime::ATOM),
			]
		);
	}//end openContractLog()

	/**
	 * Close a per-contract log: set its success expiry and store it.
	 *
	 * @param array|null $contractLog The log to close, or null when there is none.
	 * @param array      $fields      Extra fields to set before the expiry, such as `targetResult`.
	 *
	 * @return array|null The stored log, or null when there was none.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function closeContractLog(?array $contractLog, array $fields = []): ?array {
		if ($contractLog === null) {
			return null;
		}

		$contractLog = array_merge($contractLog, $fields);
		$contractLog['expires'] = $this->calculateExpires(...[$this->successRetention])?->format(DateTime::ATOM);
		if ($this->synchronizationContractLogService !== null) {
			$contractLog = $this->synchronizationContractLogService->update(log: $contractLog);
		}

		return $contractLog;
	}//end closeContractLog()

	/**
	 * Whether the extra data of an object was already fetched before the conditions were checked.
	 *
	 * @param array $sourceConfig The dot-expanded source config.
	 *
	 * @return bool True when the source config fetches extra data before the conditions.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function extraDataFetchedBeforeConditions(array $sourceConfig): bool {
		return in_array(
			($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] ?? null),
			[true, 'true'],
			true
		);
	}//end extraDataFetchedBeforeConditions()

	/**
	 * Find the source-to-target mapping of a synchronization.
	 *
	 * @param array|null $synchronization The synchronization configuration.
	 *
	 * @return mixed The mapping, null when none is configured, or an Exception when it does not exist.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function findSourceTargetMapping(?array $synchronization): mixed {
		if (empty($synchronization['sourceTargetMapping']) === true) {
			return null;
		}

		try {
			return $this->orObjectService->find(
				id: (string)$synchronization['sourceTargetMapping'],
				register: 'integriq',
				schema: 'mapping'
			);
		} catch (DoesNotExistException $exception) {
			return new Exception($exception->getMessage());
		}
	}//end findSourceTargetMapping()

	/**
	 * Whether a contract is current, so writing it again would change nothing.
	 *
	 * It is current when the origin hash matches, neither the synchronization nor the
	 * mapping changed since the source was last checked, and the target still has an id and hash.
	 *
	 * @param ContractRun $run      The contract run, with its origin hash.
	 * @param array       $contract The synchronization contract.
	 *
	 * @return bool True when the contract can be skipped.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function contractIsCurrent(ContractRun $run, array $contract): bool {
		return $run->originHash === ($contract['originHash'] ?? null)
			&& ($run->synchronization['updated'] ?? null) < ($contract['sourceLastChecked'] ?? null)
			&& $this->mappingUnchangedSince(mapping: $run->mapping, lastChecked: ($contract['sourceLastChecked'] ?? null)) === true
			&& ($contract['targetId'] ?? null) !== null
			&& ($contract['targetHash'] ?? null) !== null;
	}//end contractIsCurrent()

	/**
	 * Run the `before` rules of a synchronization on an object.
	 *
	 * The processRules() call returns a JSONResponse instead of an array when a rule fails. That
	 * response must not reach the hash and the target writer, so it aborts the sync with
	 * a message naming the synchronization.
	 *
	 * @param ContractRun $run    The contract run.
	 * @param array       $object The mapped object.
	 *
	 * @return array The object after the rules, or the object unchanged when there are none.
	 *
	 * @throws Exception When a rule returns an error response.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function applyBeforeRules(ContractRun $run, array $object): array {
		$synchronization = $run->synchronization;
		if (($synchronization['actions'] ?? []) === []) {
			return $object;
		}

		$ruleResult = $this->processRules(synchronization: $synchronization, data: $object, timing: 'before', flowToken: $run->flowToken);
		if ($ruleResult instanceof JSONResponse) {
			throw new Exception(
				'Synchronization aborted: a "before" rule returned an error response for synchronization '
				. ($synchronization['uuid'] ?? $synchronization['id'] ?? 'unknown')
			);
		}

		$run->flowToken->setSyncOutputAmended($ruleResult);

		return $ruleResult;
	}//end applyBeforeRules()

	/**
	 * Record the target hash and the sync timestamps on a contract.
	 *
	 * @param array $contract The synchronization contract.
	 * @param array $object   The object as it will be written to the target.
	 *
	 * @return array The contract with its target hash and timestamps set.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function stampTargetSynced(array $contract, array $object): array {
		$contract['targetHash'] = md5(serialize($object));
		$contract['targetLastChanged'] = (new DateTime())->format(DateTime::ATOM);
		$contract['targetLastSynced'] = (new DateTime())->format(DateTime::ATOM);
		$contract['sourceLastSynced'] = (new DateTime())->format(DateTime::ATOM);

		return $contract;
	}//end stampTargetSynced()

	/**
	 * Persist the identity mapping of a contract before its `after` rules run (Ocon#109).
	 *
	 * A contract records that source object X maps to target object A, so a re-run writes
	 * X's changes to A instead of creating a second A. The `after` rules fetch files, and a
	 * throw there abandons the item before the end-of-run persist; without this write the
	 * next run finds no contract and creates a duplicate. The write only happens when there
	 * are `after` rules that could throw: without them it protects nothing and costs a full
	 * object save per record (measured: 114 s of a 156 s run of 374 records).
	 *
	 * @param array      $contract        The synchronization contract, after the target write.
	 * @param array|null $synchronization The synchronization configuration.
	 *
	 * @return array The contract, persisted when that was needed and possible.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-the-contract-is-persisted-before-the-after-rules-run-req-021
	 */
	private function persistContractBeforeAfterRules(array $contract, ?array $synchronization): array {
		$hasAfterRules = empty($synchronization['actions'] ?? []) === false;
		if (($contract['targetId'] ?? null) === null || $hasAfterRules === false) {
			return $contract;
		}

		$this->assignContractIdentity(contract: $contract);

		try {
			return $this->persistContract(contract: $contract, ensureUuid: true);
		} catch (\Throwable $contractException) {
			// Recording the mapping never breaks a sync; the end-of-run persist is the fallback.
			$this->logger->warning(
				'Could not persist the synchronization contract before the after-rules; '
				. 'a failure in those rules may now re-create this object on the next run.',
				[
					'synchronization' => ($synchronization['name'] ?? ($synchronization['uuid'] ?? null)),
					'originId' => ($contract['originId'] ?? null),
					'targetId' => ($contract['targetId'] ?? null),
					'exception' => $contractException->getMessage(),
				]
			);
		}

		return $contract;
	}//end persistContractBeforeAfterRules()

	/**
	 * Run the `after` rules of a synchronization that has a register/schema on either side.
	 *
	 * @param ContractRun $run      The contract run; its object and its object before mapping go to the rules.
	 * @param array       $contract The synchronization contract.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function runAfterRules(ContractRun $run, array $contract): void {
		$synchronization = $run->synchronization;
		if (($synchronization['targetType'] ?? null) === 'register/schema') {
			$registerSchema = ($synchronization['targetId'] ?? '');
			$objectId = ($contract['targetId'] ?? null);
		} elseif (($synchronization['targetType'] ?? null) === 'api' && ($synchronization['sourceType'] ?? null) === 'register/schema') {
			$registerSchema = ($synchronization['sourceId'] ?? '');
			$objectId = ($contract['sourceId'] ?? null);
		} else {
			return;
		}

		[$registerId, $schemaId] = explode(separator: '/', string: (string)$registerSchema);
		$this->processRules(
			synchronization: $synchronization,
			data: array_merge($run->object, ['_objectBeforeMapping' => $run->objectBeforeMapping]),
			timing: 'after',
			objectId: $objectId,
			registerId: (int)$registerId,
			schemaId: (int)$schemaId,
			flowToken: $run->flowToken
		);
	}//end runAfterRules()

	/**
	 * Finish a written contract: clear its absence flag, give it an identity and persist it.
	 *
	 * Reaching this point means the source carried the record on this run, so
	 * DisappearanceApplier::markSeen() clears an `absentAtSource` flag or `endedAt` a sweep
	 * wrote. When the target object is still in the write buffer, the contract joins the
	 * contract buffer so the same flush writes both, objects first; the uuid is assigned
	 * now so callers reading `contract.uuid` see the value the flush writes.
	 *
	 * @param array $contract The synchronization contract.
	 *
	 * @return array The contract, persisted or buffered.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function finalizeContract(array $contract): array {
		$seen = (new DisappearanceApplier())->markSeen(contract: $contract, runAt: gmdate('c'));
		$contract = $seen['contract'];
		if ($seen['cleared'] === true) {
			$this->logger->info(
				'synchronizeContract: the source carries this record again, so its absence flag is cleared',
				['contractId' => ($contract['uuid'] ?? ($contract['id'] ?? null))]
			);
		}

		$this->assignContractIdentity(contract: $contract);

		if ($this->lastTargetWriteBuffered === true) {
			$this->contractWriteBuffer[] = $contract;
			return $contract;
		}

		return $this->persistContract(contract: $contract, ensureUuid: true);
	}//end finalizeContract()

	/**
	 * Updates or deletes a target object in the Open Register system.
	 *
	 * This method updates a target object associated with a synchronization contract
	 * or deletes it based on the specified action. It extracts the register and schema
	 * from the target ID and performs the corresponding operation using the object service.
	 *
	 * @param array $synchronizationContract The synchronization contract being updated.
	 * @param array $synchronization The synchronization entity containing the target ID.
	 * @param array|null $targetObject An optional array containing the data for the
	 *                                 target object. Defaults to an empty array.
	 * @param string|null $action The action to perform: 'save' (default) to update or
	 *                            'delete' to remove the target object.
	 *
	 * @param-out array $targetObject Every write-back in here assigns an array
	 *        (updateIdsOnSubObjects(), replaceRelatedOriginIds(), renderEntity()),
	 *        so null never comes back out even though it may go in.
	 *
	 * @return array The updated synchronization contract payload array with the modified target ID.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface If an error occurs while interacting
	 *                                                                with the object service or data.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function updateTargetOpenRegister(
		array $synchronizationContract,
		array $synchronization,
		?array &$targetObject = [],
		?string $action = 'save',
	): array {
		// The one caller, updateTarget(), already hands in [] for null; this
		// keeps the @param-out true for a delete, which never writes it back.
		$targetObject = ($targetObject ?? []);
		$sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));

		// Every write starts unbuffered; the save branch below opts in.
		$this->lastTargetWriteBuffered = false;

		// Whether this contract already pointed at a target BEFORE this write.
		// That, not the state after it, is what distinguishes a create from an
		// update: `targetId` is assigned from the saved entity during the write.
		$hadTargetId = (($synchronizationContract['targetId'] ?? null) !== null);

		// If we already have an id, we need to get the object and update it.
		if (isset($synchronizationContract['targetId']) === true) {
			$targetObject['id'] = $synchronizationContract['targetId'];
		}

		if (isset($sourceConfig['subObjects']) === true) {
			$targetObject = $this->updateIdsOnSubObjects(
				subObjectsConfig: $sourceConfig['subObjects'],
				synchronizationId: ($synchronization['id'] ?? null),
				targetObject: $targetObject
			);
		}

		// The targetId names the target as {registerId}/{schemaId}, for example 1/1.
		$targetId = ($synchronization['targetId'] ?? null);
		if ($targetId === null || str_contains($targetId, '/') === false) {
			return $synchronizationContract;
		}

		$write = new OpenRegisterTargetWrite(contract: $synchronizationContract, synchronization: $synchronization);
		[$write->register, $write->schema] = explode('/', $targetId);
		$write->sourceConfig = $sourceConfig;
		$write->hadTargetId = $hadTargetId;

		switch ($action) {
			case 'save':
				$synchronizationContract = $this->saveTargetObject(write: $write, targetObject: $targetObject);
				break;
			case 'delete':
			case DisappearancePolicy::PURGE:
				$synchronizationContract = $this->deleteTargetObject(write: $write, action: $action);
				break;
		}

		return $synchronizationContract;
	}//end updateTargetOpenRegister()

	/**
	 * Save the target object of one write, or queue it for the run's bulk write.
	 *
	 * Bulk path: a page of objects goes to the database as one batched upsert
	 * instead of one write per object (measured on a 374-record GitHub sync:
	 * 374 target writes at ~20-25ms each was 8.8s of a 16.1s run). Three
	 * conditions have to hold, and each guards something that reads the
	 * object back before the buffer would be flushed:
	 *
	 * - `$this->bufferWrites`: only the batch loop in synchronizeExternToIntern()
	 *   sets it, and it always flushes afterwards.
	 * - no `subObjects`: that branch renders the saved entity, which needs a real write.
	 * - no `actions`: the `after` rules in synchronizeContract() run against
	 *   `targetId` and may fetch the object.
	 *
	 * @param OpenRegisterTargetWrite $write        The write.
	 * @param array|null  $targetObject The mapped object to write; rewritten in place.
	 *
	 * @param-out array $targetObject
	 *
	 * @return array The contract, with `targetId` and `targetLastAction` set.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function saveTargetObject(OpenRegisterTargetWrite $write, ?array &$targetObject): array {
		$sourceConfig = $write->sourceConfig;
		if (isset($targetObject['id']) === true && ($write->contract['targetId'] ?? null) === null) {
			$write->contract['targetId'] = $targetObject['id'];
		}

		$targetObject = $this->replaceRelatedOriginIds(object: $targetObject, config: $sourceConfig['originIdsToReplace'] ?? []);
		$write->targetObject = $targetObject;

		$canBufferWrite = ($this->bufferWrites === true
			&& isset($sourceConfig['subObjects']) === false
			&& empty($write->synchronization['actions'] ?? []) === true);
		if ($canBufferWrite === true) {
			return $this->bufferTargetSave(write: $write);
		}

		$target = $this->writeTargetObject(write: $write);
		$write->contract['targetId'] = $target->getUuid();

		// Orphan cleanup is handled by the fetch-file rule path
		// (see SynchronizationService::fetchAndRegisterFileFromEndpoint).
		if (isset($sourceConfig['subObjects']) === true) {
			$targetObject = $this->orObjectService->renderEntity($target, ['all']);
			$this->updateContractsForSubObjects(
				subObjectsConfig: $sourceConfig['subObjects'],
				synchronizationId: ($write->synchronization['id'] ?? null),
				targetObject: $targetObject
			);
		}

		$write->contract['targetLastAction'] = 'create';
		if ($write->hadTargetId === true) {
			$write->contract['targetLastAction'] = 'update';
		}

		return $write->contract;
	}//end saveTargetObject()

	/**
	 * Write the target object of one write to OpenRegister.
	 *
	 * `events: false` on the synchronization runs the write inside
	 * OpenRegister's SystemOperationContext, which is what withholds the object
	 * lifecycle event: MagicMapper checks that context, and config imports and
	 * repair steps already use it. saveObject()'s `silent` flag does not: it
	 * gates the audit row and inverse-relation work in SaveObject, while the
	 * ObjectCreated/Updated dispatch lives a layer lower in MagicMapper.
	 * `logs: false` skips the object's audit row; `validation: false` skips
	 * re-validating a row against its schema. All three default to on and are
	 * set per synchronization, because only its author knows whether a write
	 * is a bulk backfill of already validated rows or a user-facing write.
	 *
	 * @param OpenRegisterTargetWrite $write The write.
	 *
	 * @return mixed The saved object entity.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function writeTargetObject(OpenRegisterTargetWrite $write): mixed {
		$objectService = $this->orObjectService;
		$writeTarget = fn (): mixed => $objectService->saveObject(
			register: $write->register,
			schema: $write->schema,
			object: $write->targetObject,
			uuid: ($write->contract['targetId'] ?? null),
			silent: (($write->sourceConfig['logs'] ?? true) === false),
			_validation: (($write->sourceConfig['validation'] ?? true) !== false)
		);

		if (($write->sourceConfig['events'] ?? true) === false) {
			return SystemWrite::run(what: 'a synchronised target object', operation: $writeTarget);
		}

		return $writeTarget();
	}//end writeTargetObject()

	/**
	 * Delete the target object of one write, softly or (`purge`) permanently.
	 *
	 * The source removing its record is the owner acting, so the source-owned
	 * delete guard lets this delete through. A purge (REQ-SDP-001) takes the
	 * object's files with it; a refusal (another object restricts it)
	 * propagates unchanged, with no fallback to a soft delete.
	 *
	 * @param OpenRegisterTargetWrite $write  The write.
	 * @param string      $action `delete` or `purge`.
	 *
	 * @return array The contract, pointing at no target, with `targetLastAction` set to the action.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function deleteTargetObject(OpenRegisterTargetWrite $write, string $action): array {
		if (empty($write->contract['targetId'] ?? null) === false) {
			$objectService = $this->orObjectService;
			$targetId = (string)$write->contract['targetId'];
			$permanent = ($action === DisappearancePolicy::PURGE);
			SourceOwnedDeleteGuardListener::whileTheEngineDeletes(
				delete: static fn () => $objectService->deleteObject(uuid: $targetId, permanent: $permanent)
			);
		}

		$write->contract['targetId'] = null;
		$write->contract['targetLastAction'] = $action;

		return $write->contract;
	}//end deleteTargetObject()

	/**
	 * Purge the object one contract points at, and record it (REQ-SDP-001, REQ-SDP-003).
	 *
	 * The contract is kept with `targetId: null` and `targetLastAction:
	 * purge`, so a source record that comes back is recreated visibly rather
	 * than silently. A purge OpenRegister refuses leaves the object and the
	 * contract as they were, is written to the contract log and is listed in
	 * `purgeRefusals` with OpenRegister's reason.
	 *
	 * @param array       $synchronization The synchronization (register/schema target).
	 * @param array       $contract        The contract that maintains the object.
	 * @param string      $trigger         self::PURGE_TRIGGER_FULL_RUN or self::PURGE_TRIGGER_NOTICE.
	 * @param string|null $reference       The destruction notice's reference, if any.
	 * @param array       $purgeInfo       `purgedCount` and `purgeRefusals`, updated in place.
	 *
	 * @return bool Whether the object was purged.
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-synchronization-can-purge-a-vanished-record-and-its-files-req-sdp-001
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-every-purge-is-recorded-and-a-refused-purge-stays-visible-req-sdp-003
	 */
	private function purgeTarget(
		array $synchronization,
		array $contract,
		string $trigger,
		?string $reference,
		array &$purgeInfo,
	): bool {
		$targetId = (string)($contract['targetId'] ?? '');
		$originId = (string)($contract['originId'] ?? '');

		try {
			$purged = $this->updateTarget(
				synchronizationContract: $contract,
				action: DisappearancePolicy::PURGE,
				synchronization: $synchronization
			);
			$this->persistContract(contract: $purged);
		} catch (\Throwable $refusal) {
			$purgeInfo['purgeRefusals'][] = [
				'originId' => $originId,
				'targetId' => $targetId,
				'reason' => $refusal->getMessage(),
			];
			$this->logger->warning(
				'SynchronizationService: purge refused, the object is left as it was',
				['originId' => $originId, 'targetId' => $targetId, 'error' => $refusal->getMessage()]
			);
			$this->writePurgeLog(
				synchronization: $synchronization,
				contract: $contract,
				trigger: $trigger,
				reference: $reference,
				result: 'purge_refused',
				reason: $refusal->getMessage()
			);

			return false;
		}//end try

		$purgeInfo['purgedCount']++;
		$this->writePurgeLog(
			synchronization: $synchronization,
			contract: $contract,
			trigger: $trigger,
			reference: $reference,
			result: 'purged',
			reason: null
		);

		return true;
	}//end purgeTarget()

	/**
	 * Apply a source's destruction notice to the one object a contract maintains (REQ-SDP-002).
	 *
	 * Resolves the contract of this synchronization whose `originId` is one of
	 * the candidates, tried in order. With `sourceConfig.onSourceDestroyed:
	 * purge` that object is purged at once; otherwise the synchronization's
	 * disappearance policy is applied to it alone. No full run, no garbage
	 * collection, and a record without a contract leaves everything untouched.
	 *
	 * @param array|ObjectEntity $synchronization The synchronization.
	 * @param list<string>       $originIds       Candidate origin ids of the destroyed record.
	 * @param string|null        $reference       The notice's reference, written to the contract log.
	 *
	 * @return array{outcome: string, synchronizationId: string, originId: string|null, targetId: string|null, reason?: string}
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function applySourceDestruction(array|ObjectEntity $synchronization, array $originIds, ?string $reference): array {
		$synchronization = $this->toSynchronization(synchronization: $synchronization);
		$synchronizationId = (string)(($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? ''));
		$outcome = ['outcome' => self::DESTRUCTION_NO_CONTRACT, 'synchronizationId' => $synchronizationId, 'originId' => null, 'targetId' => null];

		$contract = $this->findDestroyedContract(synchronizationId: $synchronizationId, originIds: $originIds);
		if ($contract === null) {
			return $outcome;
		}

		$outcome['originId'] = (string)$contract['originId'];
		$outcome['targetId'] = $contract['targetId'] ?? null;
		if (empty($outcome['targetId']) === true || ($synchronization['targetType'] ?? null) !== 'register/schema') {
			// Already gone, or a target this path does not act on.
			return ['outcome' => 'nothing_to_remove'] + $outcome;
		}

		$sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));
		try {
			$action = DisappearancePolicy::fromSourceConfig($sourceConfig);
		} catch (\InvalidArgumentException $policyException) {
			return ['outcome' => 'unknown_disappearance_policy', 'reason' => $policyException->getMessage()] + $outcome;
		}

		if (($sourceConfig['onSourceDestroyed'] ?? null) === DisappearancePolicy::PURGE) {
			$action = DisappearancePolicy::PURGE;
		}

		try {
			return $this->applyDestructionAction(
				action: $action,
				synchronization: $synchronization,
				contract: $contract,
				sourceConfig: $sourceConfig,
				reference: $reference
			) + $outcome;
		} catch (\Throwable $throwable) {
			$this->logger->warning(
				'SynchronizationService: a destruction notice could not be applied',
				['synchronizationId' => $synchronizationId, 'originId' => $outcome['originId'], 'error' => $throwable->getMessage()]
			);
			return ['outcome' => 'failed', 'reason' => $throwable->getMessage()] + $outcome;
		}
	}//end applySourceDestruction()

	/**
	 * The contract of one synchronization whose originId is one of the candidates.
	 *
	 * Both fields are checked on the row as well: a lookup that ignored a
	 * filter must not hand back another synchronization's contract.
	 *
	 * @param string       $synchronizationId The synchronization.
	 * @param list<string> $originIds         The candidates, in order.
	 *
	 * @return array|null
	 */
	private function findDestroyedContract(string $synchronizationId, array $originIds): ?array {
		foreach ($originIds as $originId) {
			$matches = [];
			$this->findContractBySyncAndOrigin(synchronizationId: $synchronizationId, originId: (string)$originId, allMatches: $matches);
			foreach ($matches as $match) {
				if ((string)($match['synchronizationId'] ?? '') === $synchronizationId && (string)($match['originId'] ?? '') === (string)$originId) {
					return $match;
				}
			}
		}

		return null;
	}//end findDestroyedContract()

	/**
	 * Purge, delete, end or flag the one object a destruction notice names.
	 *
	 * @param string      $action          The resolved action: a disappearance policy.
	 * @param array       $synchronization The synchronization (register/schema target).
	 * @param array       $contract        The contract that maintains the object.
	 * @param array       $sourceConfig    The synchronization's sourceConfig.
	 * @param string|null $reference       The notice's reference.
	 *
	 * @return array{outcome: string, reason?: string}
	 */
	private function applyDestructionAction(string $action, array $synchronization, array $contract, array $sourceConfig, ?string $reference): array {
		if ($action === DisappearancePolicy::PURGE) {
			$purgeInfo = ['purgedCount' => 0, 'purgeRefusals' => []];
			$purged = $this->purgeTarget(
				synchronization: $synchronization,
				contract: $contract,
				trigger: self::PURGE_TRIGGER_NOTICE,
				reference: $reference,
				purgeInfo: $purgeInfo
			);
			if ($purged === true) {
				return ['outcome' => 'purged'];
			}

			return ['outcome' => 'purge_refused', 'reason' => (string)($purgeInfo['purgeRefusals'][0]['reason'] ?? '')];
		}

		if ($action === DisappearancePolicy::DELETE) {
			$this->persistContract(
				contract: $this->updateTarget(synchronizationContract: $contract, action: 'delete', synchronization: $synchronization)
			);
			return ['outcome' => 'deleted'];
		}

		[$registerId, $schemaId] = array_pad(explode(separator: '/', string: (string)($synchronization['targetId'] ?? '')), 2, '');
		$targetObject = $this->orObjectService->find(
			id: (string)$contract['targetId'],
			register: $registerId,
			schema: $schemaId,
			_rbac: false,
			_multitenancy: false
		);
		if ($targetObject === null) {
			return ['outcome' => 'nothing_to_remove'];
		}

		$applier = new DisappearanceApplier();
		$counts = ['ended' => 0, 'flagged' => 0];
		$this->applyDisappearancePolicy(
			policy: $action,
			applier: $applier,
			targetObject: $targetObject,
			contract: $contract,
			registerId: $registerId,
			schemaId: $schemaId,
			runAt: gmdate('c'),
			counts: $counts,
			values: $applier->valuesFrom(sourceConfig: $sourceConfig)
		);

		$key = $applier->countKey(policy: $action);
		if ($counts[$key] === 0) {
			return ['outcome' => 'failed', 'reason' => 'the disappearance policy could not be applied'];
		}

		return ['outcome' => $key];
	}//end applyDestructionAction()

	/**
	 * Write one purge, or one refused purge, to the contract log (REQ-SDP-003).
	 *
	 * The object is gone after a purge, so the record lives on the contract
	 * log: the source record, the purged object id, the trigger and the
	 * notice's reference.
	 *
	 * @param array       $synchronization The synchronization.
	 * @param array       $contract        The contract as it was before the purge.
	 * @param string      $trigger         What started the purge.
	 * @param string|null $reference       The destruction notice's reference, if any.
	 * @param string      $result          purged or purge_refused.
	 * @param string|null $reason          OpenRegister's reason for a refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-every-purge-is-recorded-and-a-refused-purge-stays-visible-req-sdp-003
	 */
	private function writePurgeLog(
		array $synchronization,
		array $contract,
		string $trigger,
		?string $reference,
		string $result,
		?string $reason,
	): void {
		if ($this->synchronizationContractLogService === null) {
			return;
		}

		$targetId = (string)($contract['targetId'] ?? '');
		$message = 'Purged object ' . $targetId . ' and its files (' . $trigger . ')';
		if ($reason !== null) {
			$message = 'Purge of object ' . $targetId . ' refused, the object is left as it was: ' . $reason;
		}

		$this->synchronizationContractLogService->createFromArray(
			object: [
				'synchronizationId' => (string)(($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? '')),
				'synchronizationContractId' => ($contract['id'] ?? ($contract['uuid'] ?? null)),
				'source' => [
					'originId' => ($contract['originId'] ?? null),
					'trigger' => $trigger,
					'reference' => $reference,
				],
				'target' => ['id' => $targetId],
				'targetResult' => $result,
				'message' => $message,
				'expires' => $this->calculateExpires(...[$this->successRetention, $this->successRetention]),
			]
		);
	}//end writePurgeLog()

	/**
	 * Queue a mapped target row for the run's bulk write instead of writing it now.
	 *
	 * The uuid is assigned HERE rather than read back from the write, which is
	 * what makes deferring the write possible at all: `targetId` has to be known
	 * before the row lands so the contract can be built against it. OpenRegister
	 * honours a client-supplied id on both the single and the bulk path.
	 *
	 * @param OpenRegisterTargetWrite $write The write: contract, synchronization, mapped object,
	 *                           source config, target register and schema.
	 *
	 * @return array The contract, with `targetId` and `targetLastAction` set.
	 */
	private function bufferTargetSave(OpenRegisterTargetWrite $write): array {
		$synchronizationContract = $write->contract;
		$targetObject = $write->targetObject;
		$targetUuid = ($synchronizationContract['targetId'] ?? null);
		if ($targetUuid === null || $targetUuid === '') {
			$targetUuid = $this->deriveTargetUuid(
				synchronization: $write->synchronization,
				synchronizationContract: $synchronizationContract
			);
		}

		$targetObject['id'] = (string)$targetUuid;
		$synchronizationContract['targetId'] = (string)$targetUuid;

		$this->bufferTargetWrite(
			register: $write->register,
			schema: $write->schema,
			uuid: (string)$targetUuid,
			row: $targetObject,
			sourceConfig: $write->sourceConfig
		);

		$this->lastTargetWriteBuffered = true;

		$synchronizationContract['targetLastAction'] = 'create';
		if ($write->hadTargetId === true) {
			$synchronizationContract['targetLastAction'] = 'update';
		}

		return $synchronizationContract;
	}//end bufferTargetSave()

	/**
	 * Derive a target object's uuid from the source record it maps.
	 *
	 * Same synchronization + same originId always yields the same uuid, so a
	 * re-sync of a record whose contract was lost updates the row that exists
	 * instead of creating a duplicate of it. See
	 * {@see self::TARGET_UUID_NAMESPACE} for why that matters here.
	 *
	 * Falls back to a random uuid when there is no origin id to derive from —
	 * without one there is nothing stable to key on, and a shared derived uuid
	 * would be far worse than a random one: every such record would collapse
	 * onto the same target row.
	 *
	 * @param array $synchronization The synchronization being run.
	 * @param array $synchronizationContract The contract carrying the origin id.
	 *
	 * @return string The uuid to write the target object under.
	 */
	private function deriveTargetUuid(array $synchronization, array $synchronizationContract): string {
		$originId = (string)($synchronizationContract['originId'] ?? '');
		$synchronizationId = (string)(($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? ''));

		if ($originId === '' || $synchronizationId === '') {
			return (string)Uuid::v4();
		}

		return (string)Uuid::v5(
			Uuid::fromString(self::TARGET_UUID_NAMESPACE),
			$synchronizationId . '|' . $originId
		);
	}//end deriveTargetUuid()

	/**
	 * Add a mapped target row to the pending bulk write, grouped by register/schema.
	 *
	 * Rows are keyed by target uuid so a source that yields the same object twice
	 * within one flush window collapses to a single write, with the later copy
	 * winning — the same outcome as two sequential saves, without the first one.
	 *
	 * The per-synchronization `validation` / `events` / `logs` switches are stored
	 * on the group rather than consulted at flush time, because a flush is not
	 * given the synchronization and a single run could in principle buffer rows
	 * for more than one target.
	 *
	 * @param string $register The target register id.
	 * @param string $schema The target schema id.
	 * @param string $uuid The pre-assigned uuid of the target object.
	 * @param array $row The mapped object to write.
	 * @param array $sourceConfig The synchronization's dot-expanded source config.
	 *
	 * @return void
	 */
	private function bufferTargetWrite(
		string $register,
		string $schema,
		string $uuid,
		array $row,
		array $sourceConfig,
	): void {
		$groupKey = $register . '/' . $schema;

		if (isset($this->targetWriteBuffer[$groupKey]) === false) {
			$this->targetWriteBuffer[$groupKey] = [
				'register' => $register,
				'schema' => $schema,
				'validation' => (($sourceConfig['validation'] ?? true) !== false),
				'events' => (($sourceConfig['events'] ?? true) !== false),
				'audit' => (($sourceConfig['logs'] ?? true) !== false),
				'rows' => [],
			];
		}

		$this->targetWriteBuffer[$groupKey]['rows'][$uuid] = $row;
	}//end bufferTargetWrite()

	/**
	 * Count the target rows currently held across all buffered groups.
	 *
	 * @return int The number of buffered target rows.
	 */
	private function countBufferedTargetRows(): int {
		$count = 0;
		foreach ($this->targetWriteBuffer as $group) {
			$count += count(($group['rows'] ?? []));
		}

		return $count;
	}//end countBufferedTargetRows()

	/**
	 * Write every buffered target object, then every buffered contract.
	 *
	 * THAT ORDER IS THE ENTIRE REASON both sides are buffered together, and it is
	 * the one thing in here that must not be rearranged.
	 *
	 * A synchronization contract records only that source object X maps to target
	 * object A, so that a re-run writes X's changes to A instead of creating a
	 * second A. Buffering just ONE of the two writes breaks that in one direction
	 * or the other:
	 *
	 * - buffer the targets and persist contracts inline, and a contract points at
	 * an object that has not been written yet
	 * - buffer the contracts and write targets inline, and a run that dies
	 * mid-loop leaves objects written with no mapping. That IS the duplicate bug
	 * the pre-rules persist exists to prevent: the next run finds no contract for
	 * the originId, treats the row as new, and creates a copy. Every re-run adds
	 * another one.
	 *
	 * Flushing objects first and their contracts second is strictly SAFER than
	 * writing both inline, which is what today's per-record path does: there, a
	 * crash between a single record's two writes leaves exactly the inconsistency
	 * above. Here the worst interruption leaves objects written and their
	 * contracts missing, which the next run repairs by re-syncing onto the same
	 * uuids — the objects carry the ids the contracts would have referenced.
	 *
	 * @return void
	 */
	private function flushWriteBuffers(): void {
		$flushStart = microtime(true);
		try {
			$this->writeBufferedRows();
		} finally {
			$this->writeFlushMs += ((microtime(true) - $flushStart) * 1000);
		}
	}//end flushWriteBuffers()

	/**
	 * Write the buffered target objects and then the buffered contracts.
	 *
	 * The body of {@see flushWriteBuffers()}, split out only so the timing
	 * wrapper does not have to thread an accumulator through this method's
	 * several early returns.
	 *
	 * @return void
	 */
	private function writeBufferedRows(): void {
		$groups = $this->targetWriteBuffer;
		$contracts = $this->contractWriteBuffer;

		// Cleared BEFORE the writes, not after: a throw from either loop must not
		// leave the buffer populated for a later flush to write a second time.
		$this->targetWriteBuffer = [];
		$this->contractWriteBuffer = [];

		foreach ($groups as $group) {
			$this->writeTargetGroup(group: $group);
		}

		$this->writeContractBatch(contracts: $contracts);
	}//end writeBufferedRows()

	/**
	 * Bulk-write one register+schema group of buffered target rows.
	 *
	 * @param array $group A buffer group as built by {@see bufferTargetWrite()}.
	 *
	 * @return void
	 */
	private function writeTargetGroup(array $group): void {
		$rows = array_values(($group['rows'] ?? []));
		if ($rows === []) {
			return;
		}

		// `events: false` runs the batch inside OpenRegister's
		// SystemOperationContext for the same reason the single-object path
		// does: the bulk dispatcher checks that context, and MagicMapper's own
		// dispatch — a layer below the flag — checks nothing else.
		$writeBatch = fn (): array => $this->orObjectService->saveObjects(
			objects: $rows,
			register: $group['register'],
			schema: $group['schema'],
			validation: $group['validation'],
			events: $group['events'],
			_audit: $group['audit']
		);

		if ($group['events'] === false) {
			$bulkResult = SystemWrite::run(what: 'a batch of synchronised target objects', operation: $writeBatch);
		} else {
			$bulkResult = $writeBatch();
		}

		// A bulk save reports per-row rejections in its result instead of
		// throwing, so a batch can come back "successful" having written
		// nothing. Surface that: the alternative is a run whose log says it
		// created N objects that are not in the database.
		$invalidCount = (int)(($bulkResult['statistics']['invalid'] ?? 0));
		$errorCount = (int)(($bulkResult['statistics']['errors'] ?? 0));
		if ($invalidCount === 0 && $errorCount === 0) {
			return;
		}

		$this->logger->warning(
			'Bulk target write rejected rows during a synchronization flush',
			[
				'target' => $group['register'] . '/' . $group['schema'],
				'requested' => count($rows),
				'saved' => (int)(($bulkResult['statistics']['saved'] ?? 0)),
				'updated' => (int)(($bulkResult['statistics']['updated'] ?? 0)),
				'invalid' => $invalidCount,
				'errors' => $errorCount,
				'firstError' => (($bulkResult['errors'][0] ?? null) ?? ($bulkResult['invalid'][0]['error'] ?? null)),
			]
		);
	}//end writeTargetGroup()

	/**
	 * Persist the buffered contracts, batched when possible.
	 *
	 * Called only after every buffered target has been written — see
	 * {@see writeBufferedRows()} for why that order is not negotiable.
	 *
	 * @param array<int, array> $contracts The buffered contract payloads.
	 *
	 * @return void
	 */
	private function writeContractBatch(array $contracts): void {
		if ($contracts === []) {
			return;
		}

		// One contract per source record makes this the engine's most repeated
		// write, and every row of it has the same shape, so the batch is worth
		// taking whenever the extracted service is wired.
		if ($this->synchronizationContractService !== null) {
			try {
				$bulkResult = $this->synchronizationContractService->persistBulk(contracts: $contracts);

				$invalidCount = (int)(($bulkResult['statistics']['invalid'] ?? 0));
				$errorCount = (int)(($bulkResult['statistics']['errors'] ?? 0));
				if ($invalidCount > 0 || $errorCount > 0) {
					// A contract that was not written is an object that will be
					// re-created on the next run, so this is never just noise.
					$this->logger->error(
						'Bulk contract write rejected rows during a synchronization flush; '
						. 'the objects they map may be re-created on the next run.',
						[
							'requested' => count($contracts),
							'saved' => (int)(($bulkResult['statistics']['saved'] ?? 0)),
							'updated' => (int)(($bulkResult['statistics']['updated'] ?? 0)),
							'invalid' => $invalidCount,
							'errors' => $errorCount,
							'firstError' => (($bulkResult['errors'][0] ?? null) ?? ($bulkResult['invalid'][0]['error'] ?? null)),
						]
					);
				}

				return;
			} catch (\Throwable $bulkException) {
				// Fall through to the per-contract loop: a batch that threw wrote
				// an unknown subset, and re-writing a contract that already landed
				// is harmless (it is a uuid-keyed upsert), while leaving one out is
				// not.
				$this->logger->warning(
					'Bulk contract write threw; falling back to one write per contract.',
					['count' => count($contracts), 'exception' => $bulkException->getMessage()]
				);
			}//end try
		}//end if

		foreach ($contracts as $contract) {
			try {
				$this->persistContract(contract: $contract, ensureUuid: true);
			} catch (\Throwable $contractException) {
				// One unpersistable contract must not abandon the rest of the
				// batch — the objects are already written, and every contract
				// still in this list maps a DIFFERENT object. Losing them all
				// because of one would re-create all of those objects next run.
				$this->logger->error(
					'Could not persist a buffered synchronization contract; '
					. 'the object it maps may be re-created on the next run.',
					[
						'originId' => ($contract['originId'] ?? null),
						'targetId' => ($contract['targetId'] ?? null),
						'exception' => $contractException->getMessage(),
					]
				);
			}
		}
	}//end writeContractBatch()

	/**
	 * Recursively replaces 'originId' values with corresponding target IDs in the given object,
	 * according to the provided config array. The config array defines which keys to traverse and
	 * replace with ObjectEntity uuids.
	 *
	 * The object can contain nested associative arrays (sub objects) or indexed arrays of associative
	 * arrays (array of multiple subobjects). Only keys present in the config array are processed.
	 * Beforehand the $object must be mapped so properties that are relations to other objects set as a
	 * 'originId' which are equal to existing originIds on SynchronizationContracts, so that we can take
	 * the targetId of those found contracts so objects can be linked from earlier synchronizations.
	 *
	 * @param array $object The object array to process (can be nested).
	 * @param array $config A nested config tree indicating which keys to process and replace.
	 * @param bool $replaceIdWithTargetId If we need to replace id with target id found by origin id if configured.
	 *
	 * @return array The processed data with 'originId' replaced with actual ObjectEntities their uuids
	 *               where applicable.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function replaceRelatedOriginIds(array $object, array $config, bool $replaceIdWithTargetId = false): array {
		foreach ($config as $key => $subConfig) {
			if (isset($object[$key]) === false) {
				continue;
			}

			if (is_array($object[$key]) === true
				&& $this->isAssociativeArray(array: reset($object[$key])) === true
				&& is_array($subConfig) === true
			) {
				// It's a list of associative objects.
				foreach ($object[$key] as $i => $item) {
					if (is_array($item) === true) {
						$object[$key][$i] = $this->replaceRelatedOriginIds(
							object: $item,
							config: $subConfig,
							replaceIdWithTargetId: $replaceIdWithTargetId
						);
					}
				}
			} elseif ($this->isAssociativeArray(array: $object[$key]) === true && is_array($subConfig) === true) {
				// Single nested associative object.
				$object[$key] = $this->replaceRelatedOriginIds(
					object: $object[$key],
					config: $subConfig,
					replaceIdWithTargetId: $replaceIdWithTargetId
				);
			} elseif ($subConfig === 'true' && is_string($object[$key]) === true && $replaceIdWithTargetId === false) {
				// Leaf: value is a string, marked for replacement.
				$object[$key] = $this->replaceIdInString(value: $object[$key]);
			}//end if

			// Replace 'id' at this level if requested, demands originId to be set aswel.
			if ($replaceIdWithTargetId === true && $key === 'id' && isset($object['originId']) === true && is_string($object['originId']) === true) {
				$targetId = $this->replaceIdInString(value: $object['originId']);
				if ($targetId !== $object['originId']) {
					$object['id'] = $targetId;
				}
			}
		}//end foreach

		return $object;
	}//end replaceRelatedOriginIds()

	/**
	 * Replaces a UUID within a string with a mapped target ID using the synchronization mapper.
	 *
	 * This method scans the input string for the first valid UUID (v4 or general format),
	 * validates it using Uuid::isValid(), and replaces only that UUID with the mapped target ID.
	 * If no valid UUID is found, the original string is returned unchanged.
	 *
	 * Examples:
	 * - '80c24f50-4dc9-4937-b99e-9c253b5dfe8a' → 'abc123'
	 * - 'https://example.com/entity/80c24f50-4dc9-4937-b99e-9c253b5dfe8a' → 'https://example.com/entity/abc123'
	 * - 'no-id-here' → 'no-id-here'
	 *
	 * @param string $value The string potentially containing a UUID to replace.
	 *
	 * @return string The string with the UUID replaced if found and valid, otherwise the original string.
	 */
	private function replaceIdInString(string $value): string {
		// First check if we already can find object with origin id as is.
		$targetId = $this->findTargetIdByContractOrigin(originId: $value);
		if ($targetId !== null && $targetId !== $value) {
			return $targetId;
		}

		// If not a direct match, check for embedded UUID (used for uri relations).
		if (preg_match('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', $value, $matches) === 1
			&& filter_var($value, FILTER_VALIDATE_URL) !== false
		) {
			$originId = $matches[0];

			if (Uuid::isValid($originId) === true) {
				$targetId = $this->findTargetIdByContractOrigin(originId: $originId);

				if ($targetId !== null && $targetId !== $originId) {
					return str_replace($originId, $targetId, $value);
				}
			}
		}

		return $value;
	}//end replaceIdInString()

	/**
	 * Handles the synchronization of subObjects based on source configuration.
	 *
	 * @param array $subObjectsConfig The configuration for subObjects.
	 * @param string $synchronizationId The ID of the synchronization.
	 * @param array $targetObject The target object containing subObjects to be processed.
	 *
	 * @return void
	 */
	private function updateContractsForSubObjects(array $subObjectsConfig, string $synchronizationId, array $targetObject): void {
		foreach ($subObjectsConfig as $propertyName => $subObjectConfig) {
			if (isset($targetObject[$propertyName]) === false) {
				continue;
			}

			$propertyData = $targetObject[$propertyName];

			// If property data is an array of subObjects, iterate and process.
			if (is_array($propertyData) === true && $this->isAssociativeArray(array: $propertyData) === true) {
				if (isset($propertyData['originId']) === true) {
					$this->processSyncContract(synchronizationId: $synchronizationId, subObjectData: $propertyData);
				}

				// Recursively process any nested subObjects within the associative array.
				foreach ($propertyData as $key => $value) {
					if (is_array($value) === true && isset($subObjectConfig['subObjects']) === true) {
						$this->updateContractsForSubObjects(
							subObjectsConfig: $subObjectConfig['subObjects'],
							synchronizationId: $synchronizationId,
							targetObject: [$key => $value]
						);
					}
				}
			}

			// Process if it's an indexed array (list) of associative arrays.
			if (is_array($propertyData) === true && $this->isAssociativeArray(array: $propertyData) === false) {
				foreach ($propertyData as $subObjectData) {
					if (is_array($subObjectData) === true && isset($subObjectData['originId']) === true) {
						$this->processSyncContract(synchronizationId: $synchronizationId, subObjectData: $subObjectData);
					}

					// Recursively process nested sub-objects.
					if (is_array($subObjectData) === true && isset($subObjectConfig['subObjects']) === true) {
						$this->updateContractsForSubObjects(
							subObjectsConfig: $subObjectConfig['subObjects'],
							synchronizationId: $synchronizationId,
							targetObject: $subObjectData
						);
					}
				}
			}
		}//end foreach
	}//end updateContractsForSubObjects()

	/**
	 * Processes a single synchronization contract for a subObject.
	 *
	 * @param string $synchronizationId The ID of the synchronization.
	 * @param array $subObjectData The data of the subObject to process.
	 *
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	private function processSyncContract(string $synchronizationId, array $subObjectData): void {
		$idLevel2 = ($subObjectData['id']['id'] ?? $subObjectData['id']);
		$idLevel3 = ($subObjectData['id']['id']['id'] ?? $idLevel2);
		$id = ($subObjectData['id']['id']['id']['id'] ?? $idLevel3);
		$subContract = $this->findContractByOriginId(originId: $subObjectData['originId']);

		if (empty($subContract) === true) {
			$subContract = [
				'synchronizationId' => $synchronizationId,
				'originId' => $subObjectData['originId'],
				'targetId' => $id,
				'uuid' => (string)Uuid::V4(),
				'targetHash' => md5(serialize($subObjectData)),
				'targetLastChanged' => (new DateTime())->format(DateTime::ATOM),
				'targetLastSynced' => (new DateTime())->format(DateTime::ATOM),
				'sourceLastSynced' => (new DateTime())->format(DateTime::ATOM),
			];

			$subContract = $this->persistContract(contract: $subContract, ensureUuid: true);
		} else {
			$subContract = $this->updateContractFromArray(
				id: (string)($subContract['id'] ?? ''),
				object: [
					'synchronizationId' => $synchronizationId,
					'originId' => $subObjectData['originId'],
					'targetId' => $id,
					'targetHash' => md5(serialize($subObjectData)),
					'targetLastChanged' => (new DateTime())->format(DateTime::ATOM),
					'targetLastSynced' => (new DateTime())->format(DateTime::ATOM),
					'sourceLastSynced' => (new DateTime())->format(DateTime::ATOM),
				]
			);
		}//end if

		if ($this->synchronizationContractLogService !== null) {
			$this->synchronizationContractLogService->createFromArray(
				object: [
					'synchronizationId' => ($subContract['synchronizationId'] ?? null),
					'synchronizationContractId' => ($subContract['id'] ?? null),
					'target' => $subObjectData,
					'expires' => $this->calculateExpires(...[$this->successRetention, $this->successRetention]),
				]
			);
		}
	}//end processSyncContract()

	/**
	 * Checks if an array is associative.
	 *
	 * @param mixed $array The array to check.
	 *
	 * @return bool True if the array is associative, false otherwise.
	 */
	private function isAssociativeArray(mixed $array): bool {
		// Check if the array is associative.
		return (is_array($array) === true && count(array_filter(array_keys($array), 'is_string')) > 0);
	}//end isAssociativeArray()

	/**
	 * Processes subObjects update their arrays with existing targetId's so OpenRegister can update the
	 * objects instead of duplicate them.
	 *
	 * @param array $subObjectsConfig The configuration for subObjects.
	 * @param string $synchronizationId The ID of the synchronization.
	 * @param array $targetObject The target object containing subObjects to be processed.
	 *
	 * @return array The updated target object with IDs updated on subObjects.
	 */
	private function updateIdsOnSubObjects(array $subObjectsConfig, string $synchronizationId, array $targetObject): array {
		$targetObject = $this->updateIdOnSubObject(synchronizationId: $synchronizationId, subObject: $targetObject);

		foreach ($targetObject as $propertyName => $value) {
			if (is_array($value) === false) {
				continue;
			}

			if ($this->isAssociativeArray(array: $value) === true) {
				$targetObject[$propertyName] = $this->updateIdsOnSubObjects(
					subObjectsConfig: $subObjectsConfig,
					synchronizationId: $synchronizationId,
					targetObject: $value
				);
				continue;
			}

			if (is_array(reset($value)) === true && $this->isAssociativeArray(array: reset($value)) === true) {
				foreach ($value as $key => $subValue) {
					if (is_array($subValue) === false) {
						continue;
					}

					$targetObject[$propertyName][$key] = $this->updateIdsOnSubObjects(
						subObjectsConfig: $subObjectsConfig,
						synchronizationId: $synchronizationId,
						targetObject: $subValue
					);
				}
			}

			// @todo log error.
			// Throw an exception if the specified position doesn't exist.
			return [];
		}//end foreach

		return $targetObject;
	}//end updateIdsOnSubObjects()

	/**
	 * Updates the ID of a single subObject based on its synchronization contract so OpenRegister can
	 * update the object.
	 *
	 * @param string $synchronizationId The ID of the synchronization.
	 * @param array $subObject The subObject to update.
	 *
	 * @return array The updated subObject with the ID set based on the synchronization contract.
	 *
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	private function updateIdOnSubObject(string $synchronizationId, array $subObject): array {
		if (isset($subObject['originId']) === true) {
			$subObjectContract = $this->findContractBySyncAndOrigin(
				synchronizationId: $synchronizationId,
				originId: $subObject['originId']
			);

			if ($subObjectContract !== null) {
				$subObject['id'] = $subObjectContract['targetId'] ?? null;
			}
		}

		return $subObject;
	}//end updateIdOnSubObject()

	/**
	 * Write the data to the target.
	 *
	 * @param array $synchronizationContract The contract payload array to write.
	 * @param array|null $targetObject The object data to write to the target.
	 * @param string|null $action Determines what needs to be done with the target object,
	 *                            defaults to 'save'.
	 * @param string|null $mutationType If dealing with single object synchronization, the type
	 *                                  of the mutation that will be handled, 'create',
	 *                                  'update' or 'delete'. Used for syncs to external
	 *                                  sources.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, threaded through to the
	 *                                          outbound `api`-target dispatch (execution-trace REQ-001).
	 * @param array|null $synchronization The synchronization being run. Optional: resolved from
	 *                                    the contract when omitted, so standalone callers keep
	 *                                    working. Batch callers pass the array they already hold
	 *                                    rather than making this re-read it once per record.
	 *
	 * @param-out array $targetObject Callers may pass null IN (the parameter also
	 *        defaults to []), but nothing in here ever assigns null back out. The
	 *        normalisation at the top of the body is what makes that true on the
	 *        `database` and `nextcloud-table` paths, which never write it back.
	 *
	 * @return array
	 *
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 * @throws Exception
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	public function updateTarget(
		array $synchronizationContract,
		?array &$targetObject = [],
		?string $action = 'save',
		?string $mutationType = null,
		?ExecutionTraceContext $trace = null,
		?array $synchronization = null,
	): array {
		// Normalise null to the same [] the parameter already defaults to. The
		// `database` (a @todo no-op) and `nextcloud-table` branches never write
		// $targetObject back, so without this a null passed IN came straight
		// back OUT — which is what made the @param-out below untrue for those
		// two paths.
		$targetObject = ($targetObject ?? []);

		// The function can be called standalone, so it still resolves the
		// synchronization from the contract when the caller does not supply it.
		//
		// The batch path DOES supply it, because it is holding the very same
		// array: without this parameter every record of a run re-read the whole
		// synchronization object back out of OpenRegister, once per item, for a
		// value that cannot change during the run. On 374 records that is 374
		// object reads whose only purpose was to reconstruct something the caller
		// already had on the stack.
		if ($synchronization === null) {
			$synchronization = $this->findSynchronization(id: ((string)($synchronizationContract['synchronizationId'] ?? '')));
		}

		$type = ($synchronization['targetType'] ?? null);

		// Reset here, not only in updateTargetOpenRegister(): the `api`,
		// `database` and `nextcloud-table` branches never reach that method, so
		// without this the flag would still hold the previous record's answer and
		// a contract for an inline write could be deferred behind a buffer that
		// contains no object for it.
		$this->lastTargetWriteBuffered = false;

		if ($mutationType === 'delete') {
			$action = 'delete';
		}

		switch ($type) {
			case 'register/schema':
				$synchronizationContract = $this->updateTargetOpenRegister(
					synchronizationContract: $synchronizationContract,
					synchronization: $synchronization,
					targetObject: $targetObject,
					action: $action
				);
				break;
			case 'api':
				$targetConfig = ($synchronization['targetConfig'] ?? []);
				$synchronizationContract = $this->writeObjectToTarget(
					synchronization: $synchronization,
					contract: $synchronizationContract,
					endpoint: $targetConfig['endpoint'] ?? '',
					targetObject: $targetObject,
					mutationType: $mutationType,
					trace: $trace
				);
				break;
			case 'database':
				// @todo: implement.
				break;
			case 'nextcloud-table':
				$synchronizationContract = $this->updateTargetTable(
					synchronizationContract: $synchronizationContract,
					synchronization: $synchronization,
					targetObject: $targetObject,
					action: $action
				);
				break;
			default:
				throw new Exception("Unsupported target type: $type");
		}//end switch

		return $synchronizationContract;
	}//end updateTarget()

	/**
	 * Create/update/delete a `nextcloud-table` target row for a synchronization contract.
	 *
	 * Mirrors {@see writeObjectToTarget()}'s create/update/delete branching for
	 * the `api` target type, but delegates the actual row I/O — including
	 * title-to-columnId resolution and value coercion — to
	 * {@see TablesSyncAdapter::writeRow()}/{@see TablesSyncAdapter::deleteRow()}.
	 * A per-row coercion/ambiguous-mapping failure is signalled by the adapter
	 * returning `null`; this method logs it and leaves the contract's
	 * `targetId` unchanged (so a later run retries) WITHOUT aborting — only a
	 * {@see \OCA\Integriq\Exception\TablesPermissionDeniedException}
	 * (401/403) is allowed to propagate uncaught and abort the run (REQ-006).
	 *
	 * @param array $synchronizationContract The synchronization contract being updated.
	 * @param array $synchronization The synchronization entity (carries `targetId`/`targetConfig`).
	 * @param array|null $targetObject The already-mapped object to write.
	 * @param string|null $action 'save' (default, create/update) or 'delete'.
	 *
	 * @return array The updated synchronization contract payload array.
	 *
	 * @throws TablesFeatureDisabledException When the Tables app is not enabled.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-permission-denied-writes-fail-the-run-not-a-partial-subset-of-rows-req-006
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 */
	private function updateTargetTable(
		array $synchronizationContract,
		array $synchronization,
		?array $targetObject = [],
		?string $action = 'save',
	): array {
		if ($this->tablesSyncAdapter === null) {
			throw new TablesFeatureDisabledException(message: 'The Nextcloud Tables adapter is not available.');
		}

		$this->tablesSyncAdapter->assertEnabled();

		$targetConfig = ($synchronization['targetConfig'] ?? []);
		$tableId = (int)($targetConfig['tableId'] ?? 0);
		if ($tableId <= 0) {
			throw new Exception('nextcloud-table target is missing a required targetConfig.tableId');
		}

		$target = $this->findSourceObject(id: ($synchronization['targetId'] ?? null));
		$targetId = ($synchronizationContract['targetId'] ?? null);

		if ($action === 'delete') {
			if ($targetId !== null) {
				$this->tablesSyncAdapter->deleteRow(target: $target, rowId: (string)$targetId);
			}

			$synchronizationContract['targetId'] = null;
			$synchronizationContract['targetHash'] = null;

			return $synchronizationContract;
		}

		$columnMapping = ($targetConfig['columnMapping'] ?? []);
		if (is_array($columnMapping) === false) {
			$columnMapping = [];
		}

		$mappedObject = [];
		if (is_array($targetObject) === true) {
			$mappedObject = $targetObject;
		}

		$existingRowId = null;
		if ($targetId !== null) {
			$existingRowId = (string)$targetId;
		}

		$writtenRow = $this->tablesSyncAdapter->writeRow(
			target: $target,
			tableId: $tableId,
			existingRowId: $existingRowId,
			mappedObject: $mappedObject,
			columnMapping: $columnMapping
		);

		if ($writtenRow === null) {
			// A per-row skip (ambiguous/unresolved title, coercion failure) was
			// already logged by the adapter — leave the contract untouched so
			// the next run retries this row; the overall run continues.
			return $synchronizationContract;
		}

		$synchronizationContract['targetId'] = $writtenRow['id'];
		$synchronizationContract['targetHash'] = md5(serialize($mappedObject));

		return $synchronizationContract;
	}//end updateTargetTable()

	/**
	 * Get all the object from a source.
	 *
	 * @param array $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest False by default, added for the synchronization-test endpoint.
	 * @param array|null $data The data to synchronize; if not provided the synchronization's
	 *                         data will be used.
	 * @param array|null $fetchInfo By-reference output parameter: populated with
	 *                              `['complete' => bool, 'pagesFetched' => int, 'failureReason' => ?string]`
	 *                              describing whether the fetch completed. Does not change
	 *                              this method's own (flat object list) return value.
	 * @param array|null $resolvedSource When provided, used directly as the resolved source
	 *                                   instead of re-looking it up by `sourceId` — required for
	 *                                   a transient, never-persisted ad-hoc source (REQ-012).
	 *
	 * @return array
	 *
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-form-source-dispatch-req-020
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
	 */
	public function getAllObjectsFromSource(
		array $synchronization,
		?bool $isTest = false,
		?array $data = null,
		?array &$fetchInfo = null,
		?array $resolvedSource = null,
	): array {
		$objects = [];
		$fetchInfo = ['complete' => true, 'pagesFetched' => 0, 'failureReason' => null];

		$type = ($synchronization['sourceType'] ?? null);

		switch ($type) {
			case 'register/schema':
				// @todo: implement
				break;
			case 'api':
				$objects = $this->getAllObjectsFromApi(
					synchronization: $synchronization,
					isTest: $isTest,
					data: $data,
					fetchInfo: $fetchInfo,
					resolvedSource: $resolvedSource
				);
				break;
			case 'database':
				// @todo: implement
				break;
			case 'nextcloud-table':
				$objects = $this->getAllObjectsFromTable(synchronization: $synchronization, isTest: $isTest);
				break;
			case 'nextcloud-form':
				$objects = $this->getAllObjectsFromForm(synchronization: $synchronization, isTest: $isTest);
				break;
		}//end switch

		return $objects;
	}//end getAllObjectsFromSource()

	/**
	 * Fetches all rows from a `nextcloud-table` source for a given synchronization.
	 *
	 * Delegates to {@see TablesSyncAdapter::fetchAllRows()}; the Tables row id
	 * (`Row.id`) is exposed as the top-level `id` key of each returned array so
	 * the existing `getOriginId()` default `idPosition` ('id') resolves it with
	 * no adapter-specific override, exactly like every other source type.
	 *
	 * @param array $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest If true, only a single row is returned for testing purposes.
	 *
	 * @return array An array of rows retrieved from the table.
	 *
	 * @throws TablesFeatureDisabledException When the Tables app is not enabled.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 */
	public function getAllObjectsFromTable(array $synchronization, ?bool $isTest = false): array {
		if ($this->tablesSyncAdapter === null) {
			throw new TablesFeatureDisabledException(message: 'The Nextcloud Tables adapter is not available.');
		}

		$this->tablesSyncAdapter->assertEnabled();

		$sourceConfig = ($synchronization['sourceConfig'] ?? []);
		$tableId = (int)($sourceConfig['tableId'] ?? 0);
		if ($tableId <= 0) {
			throw new Exception('nextcloud-table source is missing a required sourceConfig.tableId');
		}

		$viewId = null;
		if (empty($sourceConfig['viewId']) === false) {
			$viewId = (int)$sourceConfig['viewId'];
		}

		$source = $this->findSourceObject(id: ($synchronization['sourceId'] ?? null));

		$rows = $this->tablesSyncAdapter->fetchAllRows(source: $source, tableId: $tableId, viewId: $viewId);

		if ($isTest === true && count($rows) > 1) {
			$rows = [$rows[0]];
		}

		return $rows;
	}//end getAllObjectsFromTable()

	/**
	 * Fetches all submissions from a `nextcloud-form` source for a given synchronization.
	 *
	 * Delegates to {@see FormsSyncAdapter::fetchAllSubmissions()}; the Forms
	 * submission id (`Submission.id`) is exposed as the top-level `id` key of
	 * each returned array so the existing `getOriginId()` default
	 * `idPosition` ('id') resolves it with no adapter-specific override,
	 * exactly like the `nextcloud-table` branch above. No accompanying
	 * target/deletion branch exists for `nextcloud-form` — source-only
	 * (`nextcloud-forms-connector` REQ-002, `synchronization-engine` REQ-020).
	 *
	 * @param array $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest If true, only a single submission is returned for testing purposes.
	 *
	 * @return array An array of submissions retrieved from the form.
	 *
	 * @throws FormsFeatureDisabledException When the Forms app is not enabled.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-form-source-dispatch-req-020
	 */
	public function getAllObjectsFromForm(array $synchronization, ?bool $isTest = false): array {
		if ($this->formsSyncAdapter === null) {
			throw new FormsFeatureDisabledException(message: 'The Nextcloud Forms adapter is not available.');
		}

		$this->formsSyncAdapter->assertEnabled();

		$sourceConfig = ($synchronization['sourceConfig'] ?? []);
		$formId = (int)($sourceConfig['formId'] ?? 0);
		if ($formId <= 0) {
			throw new Exception('nextcloud-form source is missing a required sourceConfig.formId');
		}

		$source = $this->findSourceObject(id: ($synchronization['sourceId'] ?? null));

		$submissions = $this->formsSyncAdapter->fetchAllSubmissions(source: $source, formId: $formId);

		if ($isTest === true && count($submissions) > 1) {
			$submissions = [$submissions[0]];
		}

		return $submissions;
	}//end getAllObjectsFromForm()

	/**
	 * Fetches all objects from an API source for a given synchronization.
	 *
	 * @param array $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest If true, only a single object is returned for testing purposes.
	 * @param array|null $data The data to synchronize; if not provided the synchronization's
	 *                         data will be used.
	 * @param array|null $fetchInfo By-reference output parameter: populated with
	 *                              `['complete' => bool, 'pagesFetched' => int, 'failureReason' => ?string]`
	 *                              describing whether the fetch completed. Does not change
	 *                              this method's own (flat object list) return value.
	 * @param array|null $resolvedSource When provided, used directly as the resolved source
	 *                                   instead of re-looking it up by `sourceId` — required for
	 *                                   a transient, never-persisted ad-hoc source (REQ-012).
	 *
	 * @return array An array of all objects retrieved from the API.
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
	 */
	public function getAllObjectsFromApi(
		array $synchronization,
		?bool $isTest = false,
		?array $data = null,
		?array &$fetchInfo = null,
		?array $resolvedSource = null,
	): array {
		$fetchInfo = ['complete' => true, 'pagesFetched' => 0, 'failureReason' => null];

		$fetch = new ApiFetch(synchronization: $synchronization, data: $data);
		$fetch->isTest       = $isTest;
		$fetch->sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));

		// A non-null $resolvedSource (possibly a transient, ad-hoc source that
		// findOrCreateSourceByLocation() never persisted) is used directly —
		// avoiding a findSource(id: ...) lookup that would throw
		// DoesNotExistException for a source that was never saved (REQ-012).
		$fetch->source = ($resolvedSource ?? $this->findSource(id: ($synchronization['sourceId'] ?? null)));

		$this->checkRateLimit(source: $fetch->source);

		$this->renderApiFetchRequest(fetch: $fetch);
		$this->configureApiFetch(fetch: $fetch);

		$pageResult = $this->fetchApiPages(fetch: $fetch);
		$fetchInfo  = [
			'complete' => $pageResult['complete'],
			'pagesFetched' => $pageResult['pagesFetched'],
			'failureReason' => $pageResult['failureReason'],
			'resumePage' => ($pageResult['resumePage'] ?? null),
			'sourceVersion' => ($pageResult['sourceVersion'] ?? null),
		];

		$objects = $this->shapeApiFetchObjects(fetch: $fetch, objects: $pageResult['objects']);

		// Reset the current page after synchronization if not a test, or keep
		// the batch the next run resumes at.
		if ($isTest === false) {
			$this->persistApiFetchPosition(fetch: $fetch, fetchInfo: $fetchInfo);
		}

		return $objects;
	}//end getAllObjectsFromApi()

	/**
	 * Render the endpoint and, on an incremental run, the query values that are Twig templates.
	 *
	 * REQ-016: an incremental run makes its stored high-watermark cursor
	 * available as `cursor` in the Twig context of the endpoint and of each
	 * scalar sourceConfig.query value. A full-mode (or syncMode-absent) run gets
	 * no `cursor` key and keeps its query unrendered.
	 *
	 * @param ApiFetch $fetch The fetch being built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
	 */
	private function renderApiFetchRequest(ApiFetch $fetch): void {
		$incremental = ((string)($fetch->synchronization['syncMode'] ?? 'full') === 'incremental');
		if ($incremental === true) {
			$fetch->twigContext['cursor'] = (string)($fetch->synchronization['cursorWatermark'] ?? '');
		}

		$fetch->endpoint = $fetch->sourceConfig['endpoint'] ?? '';
		if ($this->isTwigTemplate(value: $fetch->endpoint) === true) {
			$fetch->endpoint = $this->renderApiFetchTemplate(fetch: $fetch, template: $fetch->endpoint);
		}

		$fetch->query = $fetch->sourceConfig['query'] ?? [];
		if ($incremental === false || is_array($fetch->query) === false || empty($fetch->query) === true) {
			return;
		}

		foreach ($fetch->query as $queryKey => $queryValue) {
			if ($this->isTwigTemplate(value: $queryValue) === true) {
				$fetch->query[$queryKey] = $this->renderApiFetchTemplate(fetch: $fetch, template: $queryValue);
			}
		}
	}//end renderApiFetchRequest()

	/**
	 * Whether a value is a string holding a Twig expression.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
	 */
	private function isTwigTemplate(mixed $value): bool {
		return is_string($value) === true
			&& str_contains($value, '{{') === true
			&& str_contains($value, '}}') === true;
	}//end isTwigTemplate()

	/**
	 * Render a template against the fetch's data (its relations merged under it) and Twig context.
	 *
	 * @param ApiFetch $fetch    The fetch being built.
	 * @param string   $template The template.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
	 */
	private function renderApiFetchTemplate(ApiFetch $fetch, string $template): string {
		$contextData = $fetch->data ?? [];
		if (isset($contextData['@self']['relations']) === true && is_array($contextData['@self']['relations']) === true) {
			$contextData = array_merge($contextData['@self']['relations'], $contextData);
		}

		return $this->mappingService->renderTemplateString(
			template: $template,
			context: array_merge(['data' => $contextData], $fetch->twigContext)
		);
	}//end renderApiFetchTemplate()

	/**
	 * Settle pagination, the call configuration (headers, query, body) and the start page.
	 *
	 * A JSONL synchronization reads one batch per run, so its page is where the
	 * previous run stopped; so is a rate-limited source's.
	 *
	 * @param ApiFetch $fetch The fetch being built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function configureApiFetch(ApiFetch $fetch): void {
		if (isset($fetch->sourceConfig['usesPagination']) === true) {
			$fetch->usesPagination = filter_var($fetch->sourceConfig['usesPagination'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		}

		if ($fetch->sourceConfig['resultsPosition'] === '_object') {
			$fetch->usesPagination = false;
		}

		$headers = $fetch->sourceConfig['headers'] ?? [];
		if (empty($headers) === false) {
			$fetch->config['headers'] = $headers;
		}

		if (empty($fetch->query) === false) {
			$fetch->config['query'] = $fetch->query;
		}

		$fetch->useDataAsRequestBody = ($fetch->sourceConfig['useDataAsRequestBody'] ?? null);
		if ($fetch->useDataAsRequestBody === '#') {
			$fetch->config['body'] = json_encode($fetch->data);
		} elseif (empty($fetch->useDataAsRequestBody) === false) {
			$fetch->config['body'] = json_encode((new Dot($fetch->data))->get($fetch->useDataAsRequestBody));
		}

		if (($fetch->source['rateLimitLimit'] ?? null) !== null || ($fetch->sourceConfig['format'] ?? null) === 'jsonl') {
			$fetch->currentPage = (int)(($fetch->synchronization['currentPage'] ?? null) ?? 1);
		}
	}//end configureApiFetch()

	/**
	 * Fetch all pages with the call logs buffered.
	 *
	 * One page is one call is one CallLog, and that save runs serially inside the
	 * page fan-out. Buffered, the logs are written once at the end. The flush is
	 * in a `finally`: a fetch that throws still made those calls, and losing
	 * their logs would make the failure harder to diagnose.
	 *
	 * @param ApiFetch $fetch The fetch being run.
	 *
	 * @return array The fetchAllPages() result.
	 *
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function fetchApiPages(ApiFetch $fetch): array {
		$this->callService->bufferCallLogs(enabled: true);

		try {
			return $this->fetchAllPages(
				source: $fetch->source,
				endpoint: $fetch->endpoint,
				config: $fetch->config,
				synchronization: $fetch->synchronization,
				currentPage: $fetch->currentPage,
				isTest: $fetch->isTest,
				usesPagination: $fetch->usesPagination
			);
		} finally {
			$this->callService->bufferCallLogs(enabled: false);
			$this->callService->flushCallLogs();
		}
	}//end fetchApiPages()

	/**
	 * Wrap a single fetched object in a list, and merge the data into each
	 * object when the data was not sent as the request body.
	 *
	 * @param ApiFetch $fetch   The fetch that ran.
	 * @param array    $objects The fetched objects.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function shapeApiFetchObjects(ApiFetch $fetch, array $objects): array {
		if ($fetch->sourceConfig['resultsPosition'] !== '_object' && array_is_list($objects) === false) {
			$objects = [$objects];
		}

		if ($fetch->data !== null
			&& empty($fetch->data) === false
			&& $fetch->useDataAsRequestBody === false
		) {
			foreach ($objects as &$object) {
				$object = array_merge($object, $fetch->data);
			}
		}

		return $objects;
	}//end shapeApiFetchObjects()

	/**
	 * Store the page (and, for JSONL, the file version) the next run starts at.
	 *
	 * @param ApiFetch $fetch     The fetch that ran.
	 * @param array    $fetchInfo The fetch info of the run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function persistApiFetchPosition(ApiFetch $fetch, array $fetchInfo): void {
		$synchronization = $fetch->synchronization;
		$synchronization['currentPage'] = ($fetchInfo['resumePage'] ?? 1);
		if (($fetch->sourceConfig['format'] ?? null) === 'jsonl') {
			$synchronization['currentPageVersion'] = ($fetchInfo['sourceVersion'] ?? '');
		}

		$this->persistSynchronization(synchronization: $synchronization);
	}//end persistApiFetchPosition()

	/**
	 * Recursively fetches all pages of data from the API.
	 *
	 * @param array $source The source object containing rate limit and configuration details.
	 * @param string $endpoint The API endpoint to fetch data from.
	 * @param array $config Configuration for the API call (e.g., headers and query parameters).
	 * @param array $synchronization The synchronization object containing state information.
	 * @param int $currentPage The current page number for pagination.
	 * @param bool $isTest If true, stops after fetching the first object from the first page.
	 * @param bool $usesNextEndpoint If true, doesnt use normal pagination but next endpoint.
	 *
	 * @return array An array of objects retrieved from the API.
	 * @throws GuzzleException
	 * @throws TooManyRequestsHttpException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 */

	/**
	 * Fetches all pages from a paginated API endpoint with optimized sequential processing.
	 *
	 * This method uses an optimized approach to fetch paginated data more efficiently
	 * than the original recursive implementation, reducing overhead and improving performance.
	 *
	 * @param array $source The data source configuration
	 * @param string $endpoint The API endpoint to fetch from
	 * @param array $config The request configuration
	 * @param array $synchronization The synchronization context
	 * @param int $currentPage The starting page number
	 * @param bool $isTest Whether this is a test run (returns only first object)
	 * @param bool|null $usesNextEndpoint Whether the API uses next endpoint URLs
	 * @param bool $usesPagination Whether pagination is enabled
	 *
	 * Returns the combined objects from all pages plus fetch-completeness
	 * metadata. Private helper — only this class calls it, so its return shape
	 * is free to change.
	 *
	 * @return array{objects: array, complete: bool, failureReason: ?string, pagesFetched: int}
	 * @throws TooManyRequestsHttpException When rate limit is exceeded
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function fetchAllPages(
		array $source,
		string $endpoint,
		array $config,
		array $synchronization,
		int $currentPage,
		bool $isTest = false,
		?bool $usesNextEndpoint = null,
		?bool $usesPagination = true,
	): array {
		if (($synchronization['sourceConfig']['format'] ?? null) === 'jsonl') {
			return $this->fetchJsonLinesBatch(
				source: $source,
				endpoint: $endpoint,
				config: $config,
				synchronization: $synchronization,
				page: $currentPage,
				isTest: $isTest
			);
		}

		// Return objects if we don't paginate.
		if ($usesPagination === false) {
			return $this->fetchSinglePage(
				source: $source,
				endpoint: $endpoint,
				config: $config,
				synchronization: $synchronization
			);
		}

		// Use optimized sequential fetching (much faster than the original recursive approach).
		return $this->fetchAllPagesOptimized(
			source: $source,
			endpoint: $endpoint,
			config: $config,
			synchronization: $synchronization,
			currentPage: $currentPage,
			isTest: $isTest,
			usesNextEndpoint: $usesNextEndpoint
		);
	}//end fetchAllPages()

	/**
	 * Fetches all pages using an optimized sequential approach.
	 *
	 * This method eliminates the recursive overhead of the original implementation
	 * and uses a simple iterative approach that's much faster and more reliable.
	 *
	 * @param array $source The data source configuration
	 * @param string $endpoint The API endpoint to fetch from
	 * @param array $config The request configuration
	 * @param array $synchronization The synchronization context
	 * @param int $currentPage The starting page number
	 * @param bool $isTest Whether this is a test run
	 * @param bool|null $usesNextEndpoint Whether the API uses next endpoint URLs
	 *
	 * @return array{objects: array, complete: bool, failureReason: ?string, pagesFetched: int} Combined objects
	 *                                                                                          from all pages plus fetch-completeness metadata.
	 * @throws TooManyRequestsHttpException When rate limit is exceeded
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function fetchAllPagesOptimized(
		array $source,
		string $endpoint,
		array $config,
		array $synchronization,
		int $currentPage,
		bool $isTest = false,
		?bool $usesNextEndpoint = null,
	): array {
		$run = new PagedFetch(source: $source, synchronization: $synchronization);
		$run->endpoint = $endpoint;
		$run->config = $config;
		$run->currentPage = $currentPage;
		$run->usesNextEndpoint = $usesNextEndpoint;
		$run->sourceConfig = ($synchronization['sourceConfig'] ?? []);
		$run->maxPages = $run->sourceConfig['maxPages'] ?? $this::DEFAULT_MAX_PAGES;

		$this->startPagedFetch(run: $run);

		for ($i = 0; $i < $run->maxPages; $i++) {
			$pageData = $this->fetchSinglePageData(
				source: $source,
				endpoint: $run->endpoint,
				config: $run->config,
				synchronization: $run->synchronization
			);
			$pageObjects = $pageData['objects'];
			$run->pageCount++;

			// A failed page (non-2xx/unclassifiable response, or a connect
			// failure) must never be conflated with a natural end of
			// pagination, so it is checked before the empty page (REQ-009).
			if (($pageData['failed'] ?? false) === true) {
				$run->markIncomplete(reason: 'page_fetch_failed');
				break;
			}

			// If test mode is enabled, return only the first object from the first page.
			if ($isTest === true && empty($pageObjects) === false) {
				return ['objects' => [$pageObjects[0]], 'complete' => true, 'failureReason' => null, 'pagesFetched' => $run->pageCount];
			}

			// If no objects found, we've reached the end.
			if (empty($pageObjects) === true) {
				break;
			}

			$run->objects = array_merge($run->objects, $pageObjects);
			if ($this->advancePagedFetch(run: $run, result: $pageData['result']) === false) {
				break;
			}

			// A next page exists, but this was the last iteration the
			// DEFAULT_MAX_PAGES safety cap allows: an unknown part of the
			// source was never seen, as dangerous as a failed page (REQ-009).
			if (($i + 1) >= $run->maxPages) {
				$run->markIncomplete(reason: 'max_pages_reached');
			}

			if ($this->pagedFetchExceedsMemory(run: $run, pages: ($i + 1)) === true) {
				break;
			}
		}//end for

		return $run->result();
	}//end fetchAllPagesOptimized()

	/**
	 * Reset the per-fetch state and, when the page count can be predicted, fetch the pages ahead.
	 *
	 * `lastPageFromLink`, `pagePrefetch` and `predictedLastPage` are per-fetch
	 * state on a SHARED service instance, which re-enters itself through
	 * `synchronization` rules and `followUps`, so they start empty for every
	 * paginated fetch. A stale `lastPageFromLink` would stop this fetch early,
	 * silently dropping pages (REQ-009), and a stale `pagePrefetch` would block
	 * this fetch's fan-out and serve it another synchronization's pages.
	 *
	 * The page count is predicted from the last run's contracts, so page one can
	 * go out together with the rest. Not for a `next`-link source, whose pages
	 * are only reachable one at a time, and not for a source counting from page
	 * 0: a prefetched page is matched by its page number, and 0 is that lookup's
	 * "no pagination info" value. Page one's request is then built the same way
	 * as the others, so the two agree on the cache key; it carries an explicit
	 * page number where it otherwise relies on the source's default.
	 *
	 * @param PagedFetch $run The fetch; its config gets the first page number when it fans out.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function startPagedFetch(PagedFetch $run): void {
		$this->lastPageFromLink = null;
		$this->pagePrefetch = [];
		$this->predictedLastPage = null;

		if ($run->usesNextEndpoint === true) {
			return;
		}

		$this->predictedLastPage = $this->predictLastPage(
			synchronization: $run->synchronization,
			sourceConfig: $this->callService->applyConfigDot($run->sourceConfig)
		);

		if ($this->predictedLastPage === null || $this->predictedLastPage <= 1 || $run->currentPage < 1) {
			return;
		}

		$run->config = $this->getNextPage(
			config: $run->config,
			sourceConfig: $run->sourceConfig,
			currentPage: $run->currentPage
		);

		$this->prefetchRemainingPages(
			source: $run->source,
			endpoint: $run->endpoint,
			config: $run->config,
			synchronization: $run->synchronization,
			fromPage: $run->currentPage
		);
	}//end startPagedFetch()

	/**
	 * Move a paged fetch to its next page, unless the page just read was the last.
	 *
	 * The `Link` header's last page stops the fetch without spending a request
	 * on an empty page N+1. When the page count is known, the remaining pages are
	 * fetched together instead of one round trip at a time; a source paginating
	 * by `next` link or "until empty" falls through to the sequential path.
	 * The page cursor is persisted only when something reads it back
	 * ({@see self::persistPageCursor()}).
	 *
	 * @param PagedFetch $run    The fetch; its endpoint, config, page and cursor move on.
	 * @param array      $result The decoded body of the page just read.
	 *
	 * @return bool True when there is a next page, false when the fetch is done.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function advancePagedFetch(PagedFetch $run, array $result): bool {
		if ($this->lastPageFromLink !== null && $run->pageCount >= $this->lastPageFromLink) {
			return false;
		}

		$this->prefetchRemainingPages(
			source: $run->source,
			endpoint: $run->endpoint,
			config: $run->config,
			synchronization: $run->synchronization,
			fromPage: ($run->pageCount + 1)
		);

		$nextInfo = $this->getNextPageInfo(
			source: $run->source,
			currentEndpoint: $run->endpoint,
			config: $run->config,
			synchronization: $run->synchronization,
			currentPage: $run->currentPage,
			result: $result,
			usesNextEndpoint: $run->usesNextEndpoint
		);
		if ($nextInfo === null) {
			return false;
		}

		$run->endpoint = $nextInfo['endpoint'];
		$run->config = $nextInfo['config'];
		$run->currentPage = $nextInfo['page'];
		$run->usesNextEndpoint = $nextInfo['usesNextEndpoint'];

		$run->synchronization['currentPage'] = $run->currentPage;
		$this->persistPageCursor(synchronization: $run->synchronization, source: $run->source);

		return true;
	}//end advancePagedFetch()

	/**
	 * Stop a paged fetch that holds too much of PHP's memory.
	 *
	 * `maxPages` bounds how many requests a run makes, not how much it holds: a
	 * crawl keeps every fetched page in memory before the item loop starts, so a
	 * source with large records reaches the memory limit inside its page budget
	 * and dies mid-run with no log line and no synchronization_log row (measured
	 * 2026-08-14: a 19,822-object crawl held 1.4 GB). Stopping deliberately marks
	 * the fetch incomplete, which blocks the deletion pass like the page cap does.
	 *
	 * @param PagedFetch $run   The fetch.
	 * @param int        $pages The number of pages read so far.
	 *
	 * @return bool True when the fetch must stop.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function pagedFetchExceedsMemory(PagedFetch $run, int $pages): bool {
		if ($this->exceededMemoryCeiling(sourceConfig: $run->sourceConfig) === false) {
			return false;
		}

		$run->markIncomplete(reason: 'memory_ceiling_reached');
		$this->logger->warning(
			'Synchronization stopped after {pages} pages: {used} MB of PHP\'s {limit} MB '
			. 'limit is in use. The pages already fetched are processed; deletion is '
			. 'skipped because the source was not fully read. Lower `maxPages`, raise the '
			. 'page size, or narrow the query.',
			[
				'pages' => $pages,
				'used' => round(memory_get_usage(true) / 1048576),
				'limit' => round($this->phpMemoryLimitBytes() / 1048576),
			]
		);

		return true;
	}//end pagedFetchExceedsMemory()

	/**
	 * Gets information for the next page in pagination.
	 *
	 * This method determines the next page URL and configuration based on the current
	 * page response and pagination pattern.
	 *
	 * @param array $source The data source configuration.
	 * @param string $currentEndpoint The current page endpoint.
	 * @param array $config The current request configuration.
	 * @param array $synchronization The synchronization context.
	 * @param int $currentPage The current page number.
	 * @param array $result The decoded result of the current page.
	 * @param bool|null $usesNextEndpoint Whether the API uses next endpoint URLs.
	 *
	 * @return array|null Next page information or null if no more pages.
	 */
	private function getNextPageInfo(
		array $source,
		string $currentEndpoint,
		array $config,
		array $synchronization,
		int $currentPage,
		array $result,
		?bool $usesNextEndpoint = null,
	): ?array {
		if (empty($result) === true) {
			return null;
		}

		// Determine pagination method if not already known.
		if ($usesNextEndpoint === null && array_key_exists('next', $result) === true) {
			$usesNextEndpoint = true;
		}

		if ($usesNextEndpoint === true) {
			// Use next endpoint URL pagination.
			$nextEndpoint = $this->getNextEndpoint(body: $result, url: (string)($source['location'] ?? ''), currentEndpoint: $currentEndpoint);
			if ($nextEndpoint === null || $nextEndpoint === $currentEndpoint) {
				// No more pages.
				return null;
			}

			return [
				'endpoint' => $nextEndpoint,
				'config' => $config,
				'page' => $currentPage + 1,
				'usesNextEndpoint' => true,
			];
		} else {
			// Use page number pagination.
			$nextPage = $currentPage + 1;
			$nextConfig = $this->getNextPage(config: $config, sourceConfig: ($synchronization['sourceConfig'] ?? []), currentPage: $nextPage);

			// Base endpoint stays the same.
			return [
				'endpoint' => $currentEndpoint,
				'config' => $nextConfig,
				'page' => $nextPage,
				'usesNextEndpoint' => false,
			];
		}//end if
	}//end getNextPageInfo()

	/**
	 * Fetches a single page synchronously.
	 *
	 * This method handles the actual HTTP request and response parsing for a single page,
	 * used both in parallel and sequential fetching scenarios.
	 *
	 * @param array $source The data source configuration
	 * @param string $endpoint The page endpoint to fetch
	 * @param array $config The request configuration
	 * @param array $synchronization The synchronization context
	 *
	 * @return array{objects: array, complete: bool, failureReason: ?string, pagesFetched: int} Objects from the
	 *                                                                                          page plus fetch-completeness metadata.
	 * @throws TooManyRequestsHttpException When rate limit is exceeded
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function fetchSinglePage(array $source, string $endpoint, array $config, array $synchronization): array {
		$pageData = $this->fetchSinglePageData(
			source: $source,
			endpoint: $endpoint,
			config: $config,
			synchronization: $synchronization
		);

		$failed = ($pageData['failed'] ?? false);

		$failureReason = null;
		if ($failed === true) {
			$failureReason = 'page_fetch_failed';
		}

		return [
			'objects' => $pageData['objects'],
			'complete' => ($failed === false),
			'failureReason' => $failureReason,
			'pagesFetched' => 1,
		];
	}//end fetchSinglePage()

	/**
	 * Issue the remaining pages concurrently once the total count is known.
	 *
	 * Page one is always fetched serially, because its `Link: …rel="last"`
	 * header is what reveals how many pages there are. With that number in
	 * hand, pages `$fromPage`..last are issued together and cached for
	 * {@see fetchSinglePageData()} to consume, turning N sequential network
	 * round trips into one.
	 *
	 * Caches CALL LOGS, not parsed pages, so every downstream step — rate-limit
	 * handling, status classification, format sniffing, results position — runs
	 * identically whether a page arrived serially or in the fan-out. Concurrency
	 * therefore cannot change how a page is interpreted, which is what keeps
	 * REQ-009 ("a failed page is never the end of pagination") true: the page
	 * count comes from the header rather than from inferring exhaustion, so a
	 * failure is unambiguously a failure.
	 *
	 * Does nothing when the count is unknown, when nothing is left to fetch, or
	 * when a fan-out already ran for this synchronization.
	 *
	 * @param array $source The data source configuration.
	 * @param string $endpoint The page endpoint to fetch.
	 * @param array $config The request configuration.
	 * @param array $synchronization The synchronization context.
	 * @param int $fromPage The first page to fetch ahead; pages below it are already in hand.
	 *
	 * @return void
	 *
	 * @see self::PREFETCH_WINDOW for why this fetches a window rather than the whole remainder.
	 * @see self::predictLastPage() for where the count comes from before any page has been fetched.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) 11 against a threshold of 10.
	 * @SuppressWarnings(PHPMD.NPathComplexity) 256 against a threshold of 200.
	 *   Both are one over, and for the same reason as `fetchAllPagesOptimized()`
	 *   above: this is its concurrent half, so it carries the same pagination-dialect
	 *   branches plus the in-flight bookkeeping. Fixing it means fixing that method,
	 *   and the two should move together.
	 */
	private function prefetchRemainingPages(
		array $source,
		string $endpoint,
		array $config,
		array $synchronization,
		int $fromPage,
	): void {
		$sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));

		// How far this RUN will read. Resolved BEFORE the guard below so its
		// existing `$fromPage > $last` test covers the bounded case too — a
		// second, later check would say the same thing twice.
		$last = $this->prefetchCeiling(sourceConfig: $sourceConfig);

		if ($last === null || $fromPage > $last || $this->pagePrefetch !== []) {
			// No known count, nothing left, or pages are still in hand — the
			// buffer emptying is what asks for the next window, so re-running
			// while it holds anything would double those requests.
			return;
		}

		// Only a WINDOW of pages, never the whole remainder — see PREFETCH_WINDOW.
		$window = (int)($sourceConfig['prefetchConcurrency'] ?? self::PREFETCH_WINDOW);
		if ($window < 1) {
			$window = 1;
		}

		// CLAMPED AT BOTH ENDS. This is a per-source number in hand-written JSON,
		// and it decides how many HTTP connections one synchronization opens at
		// once — a fat-fingered 500 is 500 sockets from one apache worker, against
		// an upstream that did nothing to deserve it.
		//
		// The ceiling is not conservatism: measured 2026-08-14, wider windows are
		// SLOWER well before this limit. data.overheid.nl over 40 pages took 14.0 s
		// at a window of 5 and 28.8 s at 40, and against a source answering in
		// 5.3 ms a window of 10 bought 1.24x rather than the ~10x it implies. There
		// is no configuration above this worth having.
		if ($window > self::MAX_PREFETCH_WINDOW) {
			$this->logger->warning(
				'prefetchConcurrency of {asked} is above the ceiling of {max} and has been '
				. 'clamped. Wider windows measured SLOWER, not faster.',
				['asked' => $window, 'max' => self::MAX_PREFETCH_WINDOW]
			);
			$window = self::MAX_PREFETCH_WINDOW;
		}

		$until = min($last, ($fromPage + $window - 1));

		$promises = [];
		for ($page = $fromPage; $page <= $until; $page++) {
			$pageConfig = $this->getNextPage(config: $config, sourceConfig: $sourceConfig, currentPage: $page);
			$promises[$page] = $this->callSourceObjectAsync(
				source: $source,
				endpoint: $endpoint,
				config: $pageConfig
			);
		}

		if ($promises === []) {
			return;
		}

		// Settle(), not all(): one page rejecting must not discard the pages that
		// succeeded. A rejected page simply is not cached, so the loop fetches it
		// serially and classifies the failure exactly as it always did — which is
		// what keeps "a failed page is never the end of pagination" true.
		$settled = Utils::settle($promises)->wait();
		foreach ($settled as $page => $outcome) {
			if (($outcome['state'] ?? '') === 'fulfilled' && isset($outcome['value']) === true) {
				$this->pagePrefetch[$page] = $outcome['value'];
			}
		}
	}//end prefetchRemainingPages()

	/**
	 * How far this RUN will read, which is not how many pages the source has.
	 *
	 * Two facts, combined once so the fan-out does not have to know about
	 * either:
	 *
	 * The COUNT is the header's when we have it, otherwise the prediction. The
	 * header is authoritative and replaces the prediction as soon as page one
	 * answers; the prediction only ever decides how far to read AHEAD, never
	 * when to stop — that stays with `rel="last"`, the empty-page rule and the
	 * failure check, so an over- or under-estimate costs requests rather than
	 * correctness.
	 *
	 * The CEILING is this run's own. A bounded crawl (`maxPages`, or the
	 * `DEFAULT_MAX_PAGES` safety cap) stops early, and fanning out to the
	 * source's total then fetches pages the loop throws away — requests spent on
	 * a source that did not need to serve them, which is precisely the cost the
	 * fan-out exists to avoid. Measured against data.overheid.nl, whose 19,822
	 * datasets are 199 pages: a ten-page probe would fan out into the whole
	 * corpus.
	 *
	 * @param array $sourceConfig The source configuration.
	 *
	 * @return int|null The last page this run will read, or null when unknown.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function prefetchCeiling(array $sourceConfig): ?int {
		$last = ($this->lastPageFromLink ?? $this->predictedLastPage);
		if ($last === null) {
			return null;
		}

		$maxPages = (int)($sourceConfig['maxPages'] ?? self::DEFAULT_MAX_PAGES);
		if ($maxPages < 1) {
			return $last;
		}

		return min($last, $maxPages);
	}//end prefetchCeiling()

	/**
	 * Predict how many pages this run will need, before fetching anything.
	 *
	 * The point is to stop paying for a serial first request. Today the engine
	 * fetches page one, reads `rel="last"` off it, and only then fans out — so
	 * the first page is always sequential, and on a four-page source that is
	 * 40% of the fetch. If the page count is known UP FRONT, page one goes out
	 * with all the others.
	 *
	 * The count is free when the synchronization has run before: it wrote one
	 * contract per source record, so the number of contracts IS last run's
	 * object count. A source does not usually change size dramatically between
	 * runs, and being wrong is cheap in both directions — too low and the
	 * existing top-up fetches the rest, too high and a few requests come back
	 * empty. Neither can truncate the run, because none of the stop conditions
	 * consult this number.
	 *
	 * Returns null when there is no prior run, when the page size cannot be
	 * determined, or when the synchronization does not paginate — all of which
	 * leave the previous "fetch page one, then fan out" behaviour untouched.
	 *
	 * @param array $synchronization The synchronization about to run.
	 * @param array $sourceConfig The dot-expanded source config.
	 *
	 * @return int|null The expected last page, or null when it cannot be predicted.
	 */
	private function predictLastPage(array $synchronization, array $sourceConfig): ?int {
		$pageSize = $this->resolvePageSize(sourceConfig: $sourceConfig);
		if ($pageSize === null || $pageSize < 1) {
			return null;
		}

		$synchronizationId = (string)(($synchronization['id'] ?? null) ?? ($synchronization['uuid'] ?? ''));
		if ($synchronizationId === '') {
			return null;
		}

		try {
			$knownObjects = $this->orObjectService->count(
				config: [
					'filters' => [
						'register' => 'integriq',
						'schema' => 'synchronization_contract',
						'synchronizationId' => $synchronizationId,
					],
				]
			);
		} catch (\Throwable $e) {
			// A prediction is an optimisation; failing to make one must never
			// fail the run that would have gone ahead without it.
			$this->logger->debug(
				'Could not predict the page count from existing contracts: ' . $e->getMessage()
			);
			return null;
		}

		if ($knownObjects < 1) {
			return null;
		}

		return (int)ceil($knownObjects / $pageSize);
	}//end predictLastPage()

	/**
	 * Work out how many records a page of this source holds.
	 *
	 * `sourceConfig.pageSize` wins when set. Otherwise the request's own query
	 * string is inspected for one of the conventional spellings — there is no
	 * standard, so this recognises the common ones and gives up cleanly rather
	 * than guessing.
	 *
	 * @param array $sourceConfig The dot-expanded source config.
	 *
	 * @return int|null The page size, or null when it cannot be determined.
	 */
	private function resolvePageSize(array $sourceConfig): ?int {
		$explicit = ($sourceConfig['pageSize'] ?? null);
		if ($explicit !== null && (int)$explicit > 0) {
			return (int)$explicit;
		}

		$query = ($sourceConfig['query'] ?? []);
		if (is_array($query) === false) {
			return null;
		}

		foreach (self::PAGE_SIZE_KEYS as $key) {
			$value = ($query[$key] ?? null);
			if ($value !== null && is_numeric($value) === true && (int)$value > 0) {
				return (int)$value;
			}
		}

		return null;
	}//end resolvePageSize()

	/**
	 * The rate-limit headers of a response that says the quota is spent, or null.
	 *
	 * A 403 or 429 counts when it carries `X-RateLimit-Remaining: 0` or a
	 * `Retry-After`. Header names are matched case-insensitively: Guzzle keeps
	 * the server's casing and HTTP/2 servers send lower case. The returned map
	 * uses the spelling {@see \OCA\Integriq\Flow\FlowRateLimit} reads, with
	 * a `Retry-After` in seconds turned into an absolute `X-RateLimit-Reset`.
	 *
	 * @param int|null             $statusCode The page's status.
	 * @param array<string, mixed> $headers    The page's response headers.
	 *
	 * @return array<string, int|null>|null The headers to raise with, or null when this is no rate limit.
	 *
	 * @spec openspec/specs/github-publiccode-source/spec.md#requirement-a-spent-quota-suspends-the-run-req-ghp-004
	 */
	private function rateLimitHeadersFromResponse(?int $statusCode, array $headers): ?array {
		if ($statusCode !== 403 && $statusCode !== 429) {
			return null;
		}

		$remaining = ResponseDecoder::headerValue(headers: $headers, name: 'X-RateLimit-Remaining');
		$retryAfter = ResponseDecoder::headerValue(headers: $headers, name: 'Retry-After');
		$spent = ($remaining !== null && trim($remaining) === '0');
		if ($spent === false && $retryAfter === null) {
			return null;
		}

		$resetAt = $this->rateLimitResetAt(
			reset: ResponseDecoder::headerValue(headers: $headers, name: 'X-RateLimit-Reset'),
			retryAfter: $retryAfter
		);

		$limit = ResponseDecoder::headerValue(headers: $headers, name: 'X-RateLimit-Limit');
		$limitValue = null;
		if ($limit !== null) {
			$limitValue = (int)$limit;
		}

		return [
			'X-RateLimit-Limit' => $limitValue,
			'X-RateLimit-Remaining' => 0,
			'X-RateLimit-Reset' => $resetAt,
		];
	}//end rateLimitHeadersFromResponse()

	/**
	 * When a spent quota lifts, as an epoch timestamp, or null when the response does not say.
	 *
	 * `X-RateLimit-Reset` is already epoch seconds. `Retry-After` is either a
	 * number of seconds or an HTTP date.
	 *
	 * @param string|null $reset      The X-RateLimit-Reset header.
	 * @param string|null $retryAfter The Retry-After header.
	 *
	 * @return int|null The reset time.
	 *
	 * @spec openspec/specs/github-publiccode-source/spec.md#requirement-a-spent-quota-suspends-the-run-req-ghp-004
	 */
	private function rateLimitResetAt(?string $reset, ?string $retryAfter): ?int {
		if ($reset !== null && ctype_digit(trim($reset)) === true) {
			return (int)trim($reset);
		}

		if ($retryAfter === null) {
			return null;
		}

		if (ctype_digit(trim($retryAfter)) === true) {
			return (time() + (int)trim($retryAfter));
		}

		$parsed = strtotime($retryAfter);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}//end rateLimitResetAt()

	/**
	 * Read the total page count from an RFC 5988 `Link` header.
	 *
	 * @param array $headers The response headers.
	 *
	 * @return int|null The last page number, or null when not advertised.
	 */
	private function parseLastPageFromLinkHeader(array $headers): ?int {
		// Header names are case-insensitive and Guzzle preserves the server's
		// casing, so match on a lowercased key rather than assuming `Link`.
		$link = null;
		foreach ($headers as $name => $value) {
			if (strtolower((string)$name) === 'link') {
				// Guzzle hands every header over as a list of values; casting
				// that list to a string first raised "Array to string
				// conversion" on every GitHub page.
				if (is_array($value) === true) {
					$link = implode(', ', $value);
					break;
				}

				$link = (string)$value;
				break;
			}
		}

		if ($link === null || $link === '') {
			return null;
		}

		// <https://api.github.com/...?page=4>; rel="last"
		if (preg_match('/<([^>]*[?&]page=(\d+)[^>]*)>\s*;\s*rel="last"/i', $link, $matches) !== 1) {
			return null;
		}

		$last = (int)$matches[2];
		if ($last > 0) {
			return $last;
		}

		return null;
	}//end parseLastPageFromLinkHeader()

	/**
	 * Fetch one page of source data.
	 *
	 * @param array $source The source to call.
	 * @param string $endpoint The endpoint to call.
	 * @param array $config The call configuration.
	 * @param array $synchronization The synchronization being run.
	 *
	 * @return array The page's objects and raw result.
	 */
	private function fetchSinglePageData(array $source, string $endpoint, array $config, array $synchronization): array {
		$page = new SourcePage(source: $source, endpoint: $endpoint);
		$page->synchronization = $synchronization;
		$callLog = $this->pageCallLog(page: $page, config: $config);
		$page->response = $this->callLogResponse(callLog: $callLog);
		$page->statusCode = $this->callLogStatusCode(callLog: $callLog);
		$this->refuseRateLimitedPage(page: $page);

		// A non-2xx/unclassifiable page response MUST NOT be conflated with a
		// genuinely empty (but successful) page — the former means the fetch
		// is incomplete and downstream cleanup must not treat it as "nothing
		// left in the source" (REQ-009). Covers both a response body carrying
		// an error status code and a null response with no recoverable status
		// (network/connect failure).
		if (($page->statusCode !== null && $page->statusCode >= 400) || $page->response === null) {
			return $this->failedPage(page: $page);
		}

		$page->body = $page->response['body'];
		$this->recordLastPageFromLinkHeader(response: $page->response);

		// #97: .tar.gz bulk archives are NOT supported — gzip decompression
		// alone unpacks to a tar byte stream, not parseable JSON/JSONL. Short
		// circuit with a clear log entry instead of silently returning zero
		// objects like the pre-existing (undetected) failure mode did.
		if ($this->isTarGzEndpoint(endpoint: $endpoint) === true) {
			$this->logger->warning(
				'SynchronizationService: .tar.gz bulk sources are not supported (gzip '
				. 'decompression alone cannot unpack a tar archive) — skipping fetch for '
				. '{endpoint}. Deferred per oc#97; use an ETL-style loader instead.',
				['endpoint' => $endpoint]
			);
			return ['objects' => [], 'result' => []];
		}

		$page->body = $this->decodePageBody(page: $page);

		// #107: markdown- and HTML-shaped sources (spectr's keyless catalog/
		// standards connectors — awesome_selfhosted, openalternative,
		// don_oss_register, wikipedia_comparisons — have no JSON/XML API at
		// all). Both are a property of the SOURCE itself (the endpoint
		// always returns this shape, regardless of which synchronization
		// reads it), so — unlike `sourceConfig.format: "jsonl"`, which
		// is per-synchronization — they are keyed off
		// `Source.configuration.format` instead. Bypasses the
		// json_decode/XML attempts below entirely.
		$sourceFormat = strtolower((string)($source['configuration']['format'] ?? ''));
		if ($sourceFormat === 'markdown') {
			return $this->pageOfObjects(page: $page, result: $this->parseMarkdownResponse(body: $page->body));
		}

		if ($sourceFormat === 'html') {
			return $this->pageOfObjects(
				page: $page,
				result: $this->parseHtmlResponse(body: $page->body, configuration: ($source['configuration'] ?? []))
			);
		}

		return ($this->readYamlPage(page: $page, sourceFormat: $sourceFormat) ?? $this->readParsedPage(page: $page));
	}//end fetchSinglePageData()

	/**
	 * The call log of a page: taken from the concurrent prefetch when it is
	 * there, called through CallService otherwise.
	 *
	 * @param SourcePage $page   The page.
	 * @param array      $config The call configuration.
	 *
	 * @return ObjectEntity The call log.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function pageCallLog(SourcePage $page, array $config): ObjectEntity {
		// A page already fetched by the concurrent prefetch is consumed from
		// there. Deliberately a cache of CALL LOGS rather than of parsed pages:
		// everything below — rate-limit handling, status classification, format
		// sniffing, results position — then runs identically whether the page
		// arrived serially or in the fan-out, so concurrency cannot change how a
		// page is interpreted. That matters for REQ-009 in particular.
		// The page NUMBER, which is what the prefetch cache is keyed by — not the
		// value substituted into the request, which for an offset-paginated
		// source is a record offset. `index` is absent only for a config built
		// before this method ran, where the two are the same thing anyway.
		$pageNumber = (int)($config['pagination']['index'] ?? ($config['pagination']['page'] ?? 0));
		if ($pageNumber > 0 && array_key_exists($pageNumber, $this->pagePrefetch) === true) {
			$callLog = $this->pagePrefetch[$pageNumber];
			unset($this->pagePrefetch[$pageNumber]);
			return $callLog;
		}

		// CallService is OpenRegister-native; callSourceObject resolves the
		// source's OpenRegister object first.
		return $this->callSourceObject(source: $page->source, endpoint: $page->endpoint, config: $config);
	}//end pageCallLog()

	/**
	 * Raises the source's rate limit when the page was refused for quota.
	 *
	 * @param SourcePage $page The page, with its response and status code read.
	 *
	 * @return void
	 *
	 * @throws TooManyRequestsHttpException On a 429 without a response, or a spent quota answered with a body.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function refuseRateLimitedPage(SourcePage $page): void {
		if ($page->response === null && $page->statusCode === 429) {
			throw new TooManyRequestsHttpException(
				message: 'Rate Limit on Source exceeded.',
				code: 429,
				headers: $this->getRateLimitHeaders(source: $page->source)
			);
		}

		// A spent quota answered WITH a body. GitHub says "you are out of
		// requests" as a 403 carrying `X-RateLimit-Remaining: 0`; others send a
		// 429 with `Retry-After`. Both used to end as a failed page, so a crawl
		// that ran out of quota on page 3 ended instead of waiting. Raising the
		// same refusal as above lets the flow node suspend the run until the
		// reset. A 403 without these headers is a real refusal (a private
		// repository, a missing scope) and stays an ordinary failed page.
		$rateLimitHeaders = $this->rateLimitHeadersFromResponse(
			statusCode: $page->statusCode,
			headers: (array)($page->response['headers'] ?? [])
		);
		if ($page->response !== null && $rateLimitHeaders !== null) {
			throw new TooManyRequestsHttpException(
				message: sprintf('Rate limit on source exceeded (status %d).', (int)$page->statusCode),
				code: 429,
				headers: $rateLimitHeaders
			);
		}
	}//end refuseRateLimitedPage()

	/**
	 * Records the page count a response's `Link` header gives, when it gives one.
	 *
	 * @param array $response The page's response.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function recordLastPageFromLinkHeader(array $response): void {
		// How many pages the source says it has, from the RFC 5988 `Link` header.
		// The engine only ever inspected the response BODY for a `next` key, so a
		// source that advertises `rel="last"` — GitHub does, and it is the
		// conventional way to express this — was still paged blindly until a page
		// came back empty. That costs one whole extra request per synchronization,
		// and it is also the fact a parallel fetch needs: you cannot fan out pages
		// without knowing how many there are.
		//
		// Recorded only when a count was actually found, NEVER overwritten with
		// null. A paginated source stops advertising `rel="last"` on the last
		// page — there is no page after it, so the link is absent — and this used
		// to assign that null straight over the answer page one had given. The
		// count therefore vanished on exactly the page the "stop, this was the
		// last one" check consults, and the loop went on to request one more page
		// and conclude from its empty body what the header had already said.
		// Measured on 374 GitHub repos: 5 requests for 4 pages of data, the whole
		// saving this header was read for.
		$parsedLastPage = $this->parseLastPageFromLinkHeader(
			headers: ($response['headers'] ?? [])
		);

		if ($parsedLastPage !== null) {
			$this->lastPageFromLink = $parsedLastPage;
		}
	}//end recordLastPageFromLinkHeader()

	/**
	 * The page body as raw bytes: base64-decoded and gunzipped where the response asks for it.
	 *
	 * @param SourcePage $page The page, with its body read.
	 *
	 * @return mixed The body.
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator) gzdecode() warns on bytes that are not gzip; such a body is read as it is.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function decodePageBody(SourcePage $page): mixed {
		$body = $page->body;

		// CallService base64-encodes any response body that fails UTF-8
		// validation (gzip-compressed bytes always will) and records that in
		// `encoding`; decode back to raw bytes before attempting gunzip.
		if (($page->response['encoding'] ?? 'UTF-8') === 'base64') {
			$decodedBody = base64_decode($body, true);
			if ($decodedBody !== false) {
				$body = $decodedBody;
			}
		}

		// #97: gzip-compressed bulk files (OpenTender/OCP `.jsonl.gz` registry
		// exports). Detected via an explicit `Source.configuration.decompress:
		// "gzip"` hint, a `.gz`-suffixed endpoint, or an `application/gzip`
		// response Content-Type — first match wins, no further guessing.
		if ($this->isGzipPayload(source: $page->source, endpoint: $page->endpoint, response: $page->response) === true) {
			$decompressed = @gzdecode($body);
			if ($decompressed !== false) {
				$body = $decompressed;
			}
		}

		return $body;
	}//end decodePageBody()

	/**
	 * Reads a YAML page: declared on the source, or announced by the response's Content-Type.
	 *
	 * @param SourcePage $page         The page.
	 * @param string     $sourceFormat The source's declared format, lower case.
	 *
	 * @return array|null The page, a failed page when the YAML does not parse, or null when the page is not YAML.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function readYamlPage(SourcePage $page, string $sourceFormat): ?array {
		// YAML: declared on the source like markdown and html, or announced by
		// the response's Content-Type. Parsed by the same decoder the
		// source-call step uses, so a file reads the same on either path. A
		// page that does not parse is FAILED, never an empty page: an empty
		// page ends pagination and lets the stale sweep delete what it did not
		// see.
		$contentType = ResponseDecoder::headerValue(headers: (array)($page->response['headers'] ?? []), name: 'Content-Type');
		$decoder = new ResponseDecoder();
		if ($sourceFormat !== 'yaml' && $decoder->isYamlContentType(contentType: $contentType) === false) {
			return null;
		}

		try {
			$result = $decoder->decode(body: $page->body, mode: ResponseDecoder::MODE_YAML);
		} catch (ResponseDecodeException $exception) {
			$this->logger->error(
				'SynchronizationService: source {endpoint} answered {status} with YAML that does not parse '
				. '({reason}). Treating the page as FAILED rather than empty.',
				[
					'endpoint' => $page->endpoint,
					'status' => ($page->statusCode ?? 200),
					'reason' => $exception->getReason(),
				]
			);

			return $this->failedPage(page: $page);
		}

		if (is_array($result) === false) {
			$result = [$result];
		}

		$this->recordLastPageFromBody(body: $result, synchronization: $page->synchronization);

		return $this->pageOfObjects(page: $page, result: $result);
	}//end readYamlPage()

	/**
	 * Reads a page as JSON, or as XML when it is not JSON, and fails it when nothing could read it.
	 *
	 * @param SourcePage $page The page.
	 *
	 * @return array The page, an empty page, or a failed page.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function readParsedPage(SourcePage $page): array {
		$body = $page->body;

		// Try parsing the response body in different formats, starting with JSON.
		//
		// `$parsed` tracks whether ANY parser understood the body, which is a
		// different question from whether the body held anything. `[]` and
		// `{}` decode cleanly to an empty array — that is a source saying
		// "no more pages", and it is how pagination ends. A login page decodes
		// to nothing at all. Collapsing the two is oc#1190.
		$result = json_decode($body, true);
		$parsed = (json_last_error() === JSON_ERROR_NONE);

		// An HTML DOCUMENT is never XML to be parsed here, even when it
		// happens to be well-formed enough to succeed. A minimal login page —
		// `<html><body>Please log in</body></html>` — is valid XML, so without
		// this guard it parses, `$result` is non-empty, and the fetch walks on
		// into object extraction to die there on "cannot determine the
		// position of objects in the return body": a confusing error about the
		// source's shape when the actual problem is that nobody was logged in.
		//
		// A source that genuinely serves HTML declares
		// `Source.configuration.format: "html"` (oc#107) and was handled well
		// above this point, so reaching here with an HTML document means
		// nothing expected one.
		$isHtmlDocument = $this->looksLikeHtmlDocument(body: $body);

		// If JSON parsing failed, try XML. `$body` is the response of an
		// arbitrary configured Source, so it is untrusted input and must go
		// through SafeXmlParser (pinned null entity loader + LIBXML_NONET).
		if ($isHtmlDocument === false && ($parsed === false || empty($result) === true)) {
			libxml_use_internal_errors(true);
			$xml = SafeXmlParser::parse($body, 'SimpleXMLElement', LIBXML_NOCDATA);

			if ($xml !== false) {
				$result = $this->xmlToArray(xml: $xml);
				$parsed = true;
			}
		}

		if (empty($result) === true) {
			// A 200 THAT IS NOT A SUCCESS. The source answered, the answer had
			// content, and no parser could read it — most often an HTML login
			// or redirect page returned to an unauthenticated fetch. Treated as
			// an empty page it becomes `found: 0` on a run that reports
			// success, which is indistinguishable from a source that genuinely
			// had nothing: no error, no alert, and nothing transferred.
			//
			// `failed` is the flag `fetchAllPages()` already checks BEFORE the
			// end-of-pagination test (REQ-009), so marking it here also stops
			// downstream cleanup treating this as "nothing left in the source".
			if ($parsed === false && trim($body) !== '') {
				return $this->unreadablePage(page: $page, isHtmlDocument: $isHtmlDocument);
			}

			return ['objects' => [], 'result' => []];
		}

		// A source that reports its TOTAL in the body tells us the page count on
		// the very first response — the same fact `rel="last"` gives, from
		// sources that do not send that header. Recorded into the same field, so
		// the prefetch window and the stop-check need no knowledge of where the
		// count came from.
		$this->recordLastPageFromBody(body: $result, synchronization: $page->synchronization);

		return $this->pageOfObjects(page: $page, result: $result);
	}//end readParsedPage()

	/**
	 * Logs a page whose body no parser could read and fails it.
	 *
	 * @param SourcePage $page           The page.
	 * @param bool       $isHtmlDocument Whether the body is an HTML document.
	 *
	 * @return array The failed page.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function unreadablePage(SourcePage $page, bool $isHtmlDocument): array {
		$shape = '';
		if ($isHtmlDocument === true) {
			$shape = ', HTML — check the source\'s credentials, this is the shape of a login'
				. ' or redirect page';
		}

		$this->logger->error(
			'SynchronizationService: source {endpoint} answered {status} with a body no parser '
			. 'could read ({length} bytes{shape}) — treating the page as FAILED rather than empty, '
			. 'so the run is not reported as a success that found nothing.',
			[
				'endpoint' => $page->endpoint,
				'status' => ($page->statusCode ?? 200),
				'length' => strlen($page->body),
				'shape' => $shape,
			]
		);

		return $this->failedPage(page: $page);
	}//end unreadablePage()

	/**
	 * A page that failed: no objects, and the status it failed with.
	 *
	 * @param SourcePage $page The page.
	 *
	 * @return array The failed page.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function failedPage(SourcePage $page): array {
		return ['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => $page->statusCode];
	}//end failedPage()

	/**
	 * A page's objects, found in its parsed result.
	 *
	 * @param SourcePage $page   The page.
	 * @param array      $result The parsed result.
	 *
	 * @return array The objects and the result.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function pageOfObjects(SourcePage $page, array $result): array {
		return [
			'objects' => $this->getAllObjectsFromArray(array: $result, synchronization: $page->synchronization),
			'result' => $result,
		];
	}//end pageOfObjects()

	/**
	 * Learn the page count from a total the source reports in its BODY.
	 *
	 * The concurrency machinery already here needs one thing to fan out: how
	 * many pages there are. It could only ever learn that from an RFC 5988
	 * `rel="last"` header, and most APIs do not send one — they put the total in
	 * the payload instead. `{"result": {"count": 19822, ...}}` is CKAN;
	 * `@odata.count`, `totalElements`, `totalResults` and `numFound` are the
	 * same fact under other names.
	 *
	 * Without it the whole crawl is serial. **Measured 2026-08-14 against
	 * data.overheid.nl: 20 pages took 13,519 ms — 676 ms each, back to back,
	 * which is exactly the source's own latency.** The engine added nothing and
	 * overlapped nothing; page two could not be requested until page one had
	 * been read, purely because nobody had counted the pages. The count was in
	 * page one's body the entire time.
	 *
	 * Deliberately narrow:
	 *
	 * - Opt-in via `totalPosition`. Guessing a total out of an arbitrary payload
	 *   would eventually find a number that means something else, and a WRONG
	 *   page count reads ahead into pages that do not exist.
	 * - Needs a page size, for the same reason offset pagination does: a total
	 *   without one cannot become a page count.
	 * - NEVER overwritten once known, and never written from a page other than
	 *   the first useful one — a source that recomputes its total per page (a
	 *   filtered search) would otherwise move the finish line mid-crawl.
	 * - The count only decides how far to read AHEAD. Stopping stays with the
	 *   empty-page rule and the failure check, so an over-estimate costs
	 *   requests rather than correctness.
	 *
	 * @param array $body The decoded response body.
	 * @param array $synchronization The synchronization being run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function recordLastPageFromBody(array $body, array $synchronization): void {
		if ($this->lastPageFromLink !== null) {
			// Already known — from a header, or from this method on an earlier
			// page. The first answer wins.
			return;
		}

		$sourceConfig = ($synchronization['sourceConfig'] ?? []);
		if (is_array($sourceConfig) === false) {
			return;
		}

		$position = trim((string)($sourceConfig['totalPosition'] ?? ''));
		if ($position === '') {
			return;
		}

		$total = (new Dot($body))->get($position);
		if (is_numeric($total) === false) {
			return;
		}

		$total = (int)$total;
		$pageSize = $this->resolvePageSize(sourceConfig: $sourceConfig);
		if ($total < 1 || $pageSize === null || $pageSize < 1) {
			return;
		}

		$this->lastPageFromLink = (int)ceil($total / $pageSize);

		$this->logger->debug(
			'SynchronizationService: page count learned from the body total at {position} '
			. '({total} records / {pageSize} per page = {pages} pages), so the remaining '
			. 'pages can be fetched concurrently.',
			[
				'position' => $position,
				'total' => $total,
				'pageSize' => $pageSize,
				'pages' => $this->lastPageFromLink,
			]
		);

	}//end recordLastPageFromBody()

	/**
	 * Whether an unparseable body looks like an HTML document.
	 *
	 * Used only to sharpen the log line, never to decide anything: a body that
	 * no parser could read is already a failure whatever its shape. The check
	 * earns its place because HTML is overwhelmingly the shape this takes — a
	 * login form or a redirect interstitial served with a 200 to a fetch that
	 * had no session — and naming that in the log is the difference between an
	 * operator checking the source's credentials and hunting a phantom empty
	 * source.
	 *
	 * Deliberately looks only at the START of the body: a JSON document that
	 * merely CONTAINS an HTML string somewhere is not an HTML document, and
	 * would not have reached here anyway.
	 *
	 * @param string $body The raw response body.
	 *
	 * @return boolean True when the body opens like an HTML document.
	 */
	private function looksLikeHtmlDocument(string $body): bool {
		$head = strtolower(ltrim(substr($body, 0, 512)));

		return str_starts_with($head, '<!doctype html') === true
			|| str_starts_with($head, '<html') === true;

	}//end looksLikeHtmlDocument()

	/**
	 * Determines whether a fetched page body is gzip-compressed.
	 *
	 * Checked in order: an explicit `Source.configuration.decompress: "gzip"`
	 * hint, a `.gz`-suffixed endpoint (path or a `name=`-style query value
	 * carrying the filename), or an `application/gzip` response Content-Type
	 * header. Any one signal is sufficient.
	 *
	 * @param array $source The data source configuration.
	 * @param string $endpoint The page endpoint that was fetched.
	 * @param array $response The decoded call-log response array.
	 *
	 * @return bool True when the body is expected to be gzip-compressed.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-bulk-gzipjsonl-source-ingestion-req-006
	 */
	private function isGzipPayload(array $source, string $endpoint, array $response): bool {
		$decompressHint = strtolower((string)($source['configuration']['decompress'] ?? ''));
		if ($decompressHint === 'gzip') {
			return true;
		}

		if ($this->endpointSuggestsSuffix(endpoint: $endpoint, suffix: '.gz') === true) {
			return true;
		}

		foreach (($response['headers'] ?? []) as $name => $value) {
			if (strtolower((string)$name) !== 'content-type') {
				continue;
			}

			$values = $value;
			if (is_array($values) === false) {
				$values = [$values];
			}

			foreach ($values as $singleValue) {
				if (str_contains(strtolower((string)$singleValue), 'gzip') === true) {
					return true;
				}
			}
		}

		return false;
	}//end isGzipPayload()

	/**
	 * Determines whether the endpoint identifies a `.tar.gz` bulk archive.
	 *
	 * @param string $endpoint The page endpoint that was fetched.
	 *
	 * @return bool True when the endpoint (path or `name=`-style query value)
	 *              ends in `.tar.gz` or `.tar`.
	 */
	private function isTarGzEndpoint(string $endpoint): bool {
		return $this->endpointSuggestsSuffix(endpoint: $endpoint, suffix: '.tar.gz')
			|| $this->endpointSuggestsSuffix(endpoint: $endpoint, suffix: '.tar');
	}//end isTarGzEndpoint()

	/**
	 * Checks whether an endpoint's path, or any of its query-string values,
	 * ends with the given suffix (case-insensitive). Handles the common bulk
	 * registry shape `/download?name=full.jsonl.gz`, where the meaningful
	 * filename lives in a query value rather than the path itself.
	 *
	 * @param string $endpoint The endpoint to inspect.
	 * @param string $suffix The suffix to check for (e.g. `.gz`).
	 *
	 * @return bool True when the path or a query value ends with the suffix.
	 */
	private function endpointSuggestsSuffix(string $endpoint, string $suffix): bool {
		$suffix = strtolower($suffix);
		$questionMark = strpos($endpoint, '?');
		$path = $endpoint;
		if ($questionMark !== false) {
			$path = substr($endpoint, 0, $questionMark);
		}

		if (str_ends_with(strtolower($path), $suffix) === true) {
			return true;
		}

		if ($questionMark === false) {
			return false;
		}

		parse_str(substr($endpoint, ($questionMark + 1)), $queryParams);
		foreach ($queryParams as $value) {
			if (is_string($value) === true && str_ends_with(strtolower($value), $suffix) === true) {
				return true;
			}
		}

		return false;
	}//end endpointSuggestsSuffix()

	/**
	 * Fetch one batch of a line-delimited JSON (JSONL) source.
	 *
	 * The response body streams to a temporary file instead of into memory,
	 * and the file is read back one line at a time ({@see readJsonLines()}),
	 * so neither the compressed nor the decompressed body is ever held whole.
	 * One run reads at most one batch of records: batch `$page` holds lines
	 * `($page - 1) * size` up to `$page * size`. The next run resumes at
	 * `resumePage`, which returns to 1 once the file is exhausted.
	 *
	 * Only a run that read the whole file from its first line is `complete`;
	 * every other batch is incomplete, so the deletion pass never mistakes
	 * one batch for the whole source (REQ-009).
	 *
	 * Each run downloads the file again, so a cycle is tied to one version of
	 * it: the validator of the download (ETag, else Last-Modified and
	 * Content-Length) is kept as `currentPageVersion` next to `currentPage`.
	 * When a later batch finds a different version, the file changed under the
	 * cursor, and the run starts the cycle again at batch 1 of the new file
	 * instead of skipping or repeating records of the old one.
	 *
	 * A `.tar` or `.tar.gz` endpoint is refused before anything is downloaded,
	 * as {@see fetchSinglePageData()} refuses it for a non-JSONL source.
	 *
	 * @param array $source The source to call.
	 * @param string $endpoint The endpoint to call.
	 * @param array $config The call configuration.
	 * @param array $synchronization The synchronization being run.
	 * @param int $page The 1-based batch to read.
	 * @param bool $isTest Whether this is a test run (reads one record).
	 *
	 * @return array{objects: array, complete: bool, failureReason: ?string, pagesFetched: int, resumePage: ?int, sourceVersion: ?string}
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-bulk-gzipjsonl-source-ingestion-req-006
	 */
	private function fetchJsonLinesBatch(
		array $source,
		string $endpoint,
		array $config,
		array $synchronization,
		int $page,
		bool $isTest,
	): array {
		$batchSize = $this->jsonLinesBatchSize(sourceConfig: ($synchronization['sourceConfig'] ?? []), isTest: $isTest);
		$page = max(1, $page);
		$storedVersion = ($synchronization['currentPageVersion'] ?? null);

		if ($this->isTarGzEndpoint(endpoint: $endpoint) === true) {
			$this->logger->warning(
				'SynchronizationService: .tar.gz bulk sources are not supported (gzip '
				. 'decompression alone cannot unpack a tar archive) — skipping fetch for '
				. '{endpoint}. Deferred per oc#97; use an ETL-style loader instead.',
				['endpoint' => $endpoint]
			);
			return $this->failedJsonLinesBatch(page: $page, reason: 'unsupported_archive', version: $storedVersion, pagesFetched: 0);
		}

		$sinkPath = tempnam(sys_get_temp_dir(), 'integriq-jsonl-');
		if ($sinkPath === false) {
			$this->logger->error('SynchronizationService: no temporary file for a JSONL download from {endpoint}.', ['endpoint' => $endpoint]);
			return $this->failedJsonLinesBatch(page: $page, version: $storedVersion);
		}

		try {
			$callLog = $this->callSourceObject(source: $source, endpoint: $endpoint, config: $config, sink: $sinkPath);
			$statusCode = $this->callLogStatusCode(callLog: $callLog);
			if ($statusCode === null || $statusCode >= 400) {
				return $this->failedJsonLinesBatch(page: $page, version: $storedVersion);
			}

			$response = ($this->callLogResponse(callLog: $callLog) ?? []);
			$version = $this->jsonLinesFileVersion(headers: (array)($response['headers'] ?? []));
			$page = $this->jsonLinesCyclePage(page: $page, version: $version, storedVersion: $storedVersion, endpoint: $endpoint);

			$this->writeBufferedBodyToSink(response: $response, sinkPath: $sinkPath);
			$batch = $this->readJsonLinesBatch(path: $sinkPath, skip: (($page - 1) * $batchSize), limit: $batchSize);
		} finally {
			if (file_exists($sinkPath) === true) {
				unlink($sinkPath);
			}
		}

		return $this->jsonLinesBatchResult(batch: $batch, page: $page, isTest: $isTest, version: $version, synchronization: $synchronization);
	}//end fetchJsonLinesBatch()

	/**
	 * The batch to read: batch 1 again when the file changed since the cycle's first batch.
	 *
	 * @param int $page The batch the cursor names.
	 * @param string|null $version The version of this download.
	 * @param mixed $storedVersion The version the cycle's first batch read.
	 * @param string $endpoint The endpoint, for the log line.
	 *
	 * @return int The batch to read.
	 */
	private function jsonLinesCyclePage(int $page, ?string $version, mixed $storedVersion, string $endpoint): int {
		if ($page === 1 || $version === null || is_string($storedVersion) === false || $storedVersion === '' || $storedVersion === $version) {
			return $page;
		}

		$this->logger->info(
			'SynchronizationService: the JSONL file at {endpoint} changed since batch 1 of this cycle; starting again at batch 1.',
			['endpoint' => $endpoint, 'batch' => $page]
		);

		return 1;
	}//end jsonLinesCyclePage()

	/**
	 * The fetch result of a JSONL batch that was read.
	 *
	 * @param array{records: array, exhausted: bool} $batch The batch.
	 * @param int $page The batch that was read.
	 * @param bool $isTest Whether this is a test run.
	 * @param string|null $version The version of the file it was read from.
	 * @param array $synchronization The synchronization, for the results extraction.
	 *
	 * @return array{objects: array, complete: bool, failureReason: ?string, pagesFetched: int, resumePage: int, sourceVersion: ?string}
	 */
	private function jsonLinesBatchResult(array $batch, int $page, bool $isTest, ?string $version, array $synchronization): array {
		$complete = ($batch['exhausted'] === true && $page === 1 && $isTest === false);
		$resumePage = ($page + 1);
		if ($batch['exhausted'] === true) {
			$resumePage = 1;
		}

		$failureReason = 'batch_limit_reached';
		if ($complete === true) {
			$failureReason = null;
		}

		return [
			'objects' => $this->getAllObjectsFromArray(array: $batch['records'], synchronization: $synchronization),
			'complete' => $complete,
			'failureReason' => $failureReason,
			'pagesFetched' => 1,
			'resumePage' => $resumePage,
			'sourceVersion' => $version,
		];
	}//end jsonLinesBatchResult()

	/**
	 * The version of a downloaded JSONL file, from its response headers.
	 *
	 * The ETag when the source sends one, else Last-Modified and Content-Length
	 * together; null when the source sends neither, so a change cannot be seen.
	 *
	 * @param array $headers The response headers, as the call log holds them.
	 *
	 * @return string|null The version, or null.
	 */
	private function jsonLinesFileVersion(array $headers): ?string {
		$byName = [];
		foreach ($headers as $name => $value) {
			$byName[strtolower((string)$name)] = trim(implode(',', (array)$value));
		}

		if (($byName['etag'] ?? '') !== '') {
			return 'etag:' . $byName['etag'];
		}

		if (($byName['last-modified'] ?? '') !== '') {
			return 'modified:' . $byName['last-modified'] . ';length:' . ($byName['content-length'] ?? '');
		}

		return null;
	}//end jsonLinesFileVersion()

	/**
	 * The number of JSONL records one run reads.
	 *
	 * @param array $sourceConfig The synchronization's source configuration.
	 * @param bool $isTest Whether this is a test run, which reads one record.
	 *
	 * @return int The batch size.
	 */
	private function jsonLinesBatchSize(array $sourceConfig, bool $isTest): int {
		if ($isTest === true) {
			return 1;
		}

		$batchSize = (int)($sourceConfig['pageSize'] ?? self::DEFAULT_JSONL_BATCH_SIZE);
		if ($batchSize < 1) {
			return self::DEFAULT_JSONL_BATCH_SIZE;
		}

		return $batchSize;
	}//end jsonLinesBatchSize()

	/**
	 * The result of a JSONL batch that read nothing: incomplete, and the next
	 * run retries the same batch of the same file version.
	 *
	 * @param int $page The batch that was asked for.
	 * @param string $reason Why nothing was read.
	 * @param mixed $version The file version the cycle holds, kept as it was.
	 * @param int $pagesFetched The downloads made, 0 when none was attempted.
	 *
	 * @return array{objects: array, complete: bool, failureReason: string, pagesFetched: int, resumePage: int, sourceVersion: ?string}
	 */
	private function failedJsonLinesBatch(int $page, string $reason='page_fetch_failed', mixed $version=null, int $pagesFetched=1): array {
		$sourceVersion = null;
		if (is_string($version) === true && $version !== '') {
			$sourceVersion = $version;
		}

		return [
			'objects' => [],
			'complete' => false,
			'failureReason' => $reason,
			'pagesFetched' => $pagesFetched,
			'resumePage' => $page,
			'sourceVersion' => $sourceVersion,
		];
	}//end failedJsonLinesBatch()

	/**
	 * Read up to `$limit` records after the first `$skip` from a JSONL file.
	 *
	 * Reads one record past the batch to learn whether the file has more.
	 *
	 * @param string $path The file to read.
	 * @param int $skip Records to pass over.
	 * @param int $limit Records to return at most.
	 *
	 * @return array{records: array<int, array>, exhausted: bool}
	 */
	private function readJsonLinesBatch(string $path, int $skip, int $limit): array {
		$records = [];
		foreach ($this->readJsonLines(path: $path) as $index => $record) {
			if ($index < $skip) {
				continue;
			}

			if (count($records) >= $limit) {
				return ['records' => $records, 'exhausted' => false];
			}

			$records[] = $record;
		}

		return ['records' => $records, 'exhausted' => true];
	}//end readJsonLinesBatch()

	/**
	 * Write a response body that arrived buffered into the sink file.
	 *
	 * A source answered in-process (SOAP, case-system) ignores the Guzzle sink
	 * and returns its body on the call log instead; this puts that body where
	 * {@see readJsonLines()} reads. A streamed response left the call log body
	 * empty, so nothing is written.
	 *
	 * @param array $response The call log's `response` sub-array.
	 * @param string $sinkPath The temporary file the body belongs in.
	 *
	 * @return void
	 */
	private function writeBufferedBodyToSink(array $response, string $sinkPath): void {
		$body = (string)($response['body'] ?? '');
		if ($body === '' || filesize($sinkPath) > 0) {
			return;
		}

		if (($response['encoding'] ?? 'UTF-8') === 'base64') {
			$decoded = base64_decode($body, true);
			if ($decoded !== false) {
				$body = $decoded;
			}
		}

		file_put_contents($sinkPath, $body);
	}//end writeBufferedBodyToSink()

	/**
	 * Yield the records of a JSONL file one line at a time.
	 *
	 * `gzopen()` reads a gzip file decompressed and a plain file as-is, so one
	 * reader serves both `.jsonl` and `.jsonl.gz`. Only the current line is in
	 * memory. Each non-empty line is decoded on its own; a line that is not a
	 * JSON object or array is skipped rather than aborting the file, and the
	 * yielded key counts records only, so batch boundaries do not shift with
	 * blank or malformed lines.
	 *
	 * @param string $path The file to read.
	 *
	 * @return \Generator<int, array> The decoded records, keyed by record index.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-bulk-gzipjsonl-source-ingestion-req-006
	 */
	private function readJsonLines(string $path): \Generator {
		$handle = gzopen($path, 'rb');
		if ($handle === false) {
			return;
		}

		try {
			$index = 0;
			while (($line = gzgets($handle)) !== false) {
				$trimmed = trim($line);
				if ($trimmed === '') {
					continue;
				}

				$decoded = json_decode($trimmed, true);
				if (is_array($decoded) === true) {
					yield $index => $decoded;
					$index++;
				}
			}
		} finally {
			gzclose($handle);
		}
	}//end readJsonLines()

	/**
	 * Parses a markdown-list-shaped response body into an array of records.
	 *
	 * Targets the "awesome list" README shape (oc#107 — awesome_selfhosted):
	 * one record per top-level markdown list item of the form
	 * `- [Name](https://url) - Some description \`Tag1\` \`Tag2\``. Only the
	 * link name/url are mandatory; the description and the (variable-count,
	 * possibly absent) trailing backtick-wrapped tags are all optional. Any
	 * line that is not a `- [Name](url) ...`/`* [Name](url) ...` list item
	 * (headings, prose, blank lines, plain-text list items with no link) is
	 * silently skipped rather than aborting the page — a markdown README is
	 * mostly non-record prose around the list this method actually cares
	 * about.
	 *
	 * The trailing backtick tags are returned verbatim, in file order, under
	 * a generic `tags` key — this method assigns no semantic meaning to
	 * their position (e.g. "first tag is a license") beyond documenting that
	 * awesome-selfhosted-shaped sources conventionally put the license first
	 * and the language second. A source needing named fields instead of a
	 * positional `tags` array should map them downstream (SynchronizationService
	 * mapping/rules), not in this parser.
	 *
	 * @param string $body The markdown response body.
	 *
	 * @return array<int, array{name: string, url: string, description: string, tags: array<int, string>}>
	 *                                                                                                     The extracted records, in file order.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-markdown-and-html-source-extraction-req-007
	 */
	private function parseMarkdownResponse(string $body): array {
		$records = [];

		foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
			$trimmed = trim($line);
			if ($trimmed === '') {
				continue;
			}

			// A top-level markdown list item carrying a `[Name](url)` link.
			// Everything after the link (an optional ` - description` plus
			// any number of `` `tag` `` fragments) is captured as `$rest` and
			// decomposed separately below.
			$matched = preg_match(
				'/^[-*+]\s*\[(?P<name>[^\]]+)\]\((?P<url>[^)\s]+)[^)]*\)(?:\s*[-:—]?\s*(?P<rest>.*))?$/u',
				$trimmed,
				$matches
			);

			if ($matched !== 1 || trim($matches['name']) === '' || trim($matches['url']) === '') {
				// Not a matching list item (heading, prose, non-link list
				// item, malformed line) — skip gracefully, do not throw.
				continue;
			}

			// Skip in-document anchors ("#section") and scheme-less/relative
			// targets. Awesome-list style entries always carry an absolute URL
			// ("https://..."); a table-of-contents or "back to top" link points
			// at a fragment. Only absolute-URI links (carrying a scheme)
			// describe an external-resource record — the rest is navigation
			// noise, not data. Skip gracefully, do not throw.
			if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', trim($matches['url'])) !== 1) {
				continue;
			}

			$rest = ($matches['rest'] ?? '');
			$firstTagPos = strpos($rest, '`');
			if ($firstTagPos === false) {
				$description = trim($rest);
			} else {
				$description = trim(substr($rest, 0, $firstTagPos));
			}

			$tags = [];
			if (preg_match_all('/`([^`]*)`/', $rest, $tagMatches) > 0) {
				foreach ($tagMatches[1] as $tag) {
					$tag = trim($tag);
					if ($tag !== '') {
						$tags[] = $tag;
					}
				}
			}

			$records[] = [
				'name' => trim($matches['name']),
				'url' => trim($matches['url']),
				'description' => $description,
				'tags' => $tags,
			];
		}//end foreach

		return $records;
	}//end parseMarkdownResponse()

	/**
	 * Parses an HTML response body into an array of records using CSS
	 * selectors (oc#107 — openalternative, don_oss_register,
	 * wikipedia_comparisons: plain web pages with no API, whose data sits in
	 * an HTML table or a repeating list of cards).
	 *
	 * `configuration.htmlSelector` (required) is a CSS selector matching the
	 * repeating record container — e.g. `table tbody tr` for an HTML table,
	 * or `.card`/`li.item` for a card/list layout. Each matched container
	 * becomes one record. `configuration.htmlFields` (a `fieldName =>
	 * selector` map) then extracts one value per field, relative to that
	 * container: `selector@attr` extracts the named attribute (e.g.
	 * `a@href`); a selector with no `@attr` extracts trimmed text content
	 * instead. An empty selector (just `@attr`) reads the attribute off the
	 * container element itself, for the common case where the container IS
	 * the link (e.g. `li.item` containing `<a href="...">Name</a>` where
	 * `htmlSelector: "li.item"` and a field selector of `@href` would need
	 * `a@href` instead — an empty selector targets the container, not the
	 * first descendant).
	 *
	 * Uses `Symfony\Component\DomCrawler\Crawler` (with `symfony/
	 * css-selector` translating the CSS selectors to XPath) — the standard,
	 * well-maintained PHP library for exactly this task, already MIT/EUPL
	 * compatible and added specifically for this change (see design.md). A
	 * missing `htmlSelector`, or a field selector that fails to match, MUST
	 * NOT abort the page: a container with an unmatched field simply omits
	 * (null) that field, and a wholly-missing `htmlSelector` returns zero
	 * records.
	 *
	 * @param string $body The HTML response body.
	 * @param array $configuration The source's `Source.configuration`
	 *                             (reads `htmlSelector` and `htmlFields`).
	 *
	 * @return array<int, array<string, string|null>> The extracted records,
	 *                                                in document order.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-markdown-and-html-source-extraction-req-007
	 */
	private function parseHtmlResponse(string $body, array $configuration): array {
		$selector = (string)($configuration['htmlSelector'] ?? '');
		if ($selector === '') {
			return [];
		}

		$fields = ($configuration['htmlFields'] ?? []);
		if (is_array($fields) === false) {
			$fields = [];
		}

		$crawler = new Crawler($body);

		try {
			$containers = $crawler->filter($selector);
		} catch (\Exception $exception) {
			$this->logger->warning(
				'SynchronizationService: invalid htmlSelector {selector} for HTML source: {message}',
				['selector' => $selector, 'message' => $exception->getMessage()]
			);
			return [];
		}

		$records = [];
		foreach ($containers as $containerNode) {
			$containerCrawler = new Crawler($containerNode);
			$record = [];
			foreach ($fields as $fieldName => $fieldSelector) {
				$record[(string)$fieldName] = $this->extractHtmlField(
					container: $containerCrawler,
					fieldSelector: (string)$fieldSelector
				);
			}

			$records[] = $record;
		}

		return $records;
	}//end parseHtmlResponse()

	/**
	 * Extracts a single field's value from an HTML record container.
	 *
	 * Supports the `selector@attr` syntax: when `@attr` is present, the
	 * value is the named attribute of the (optionally sub-selected) node
	 * instead of its trimmed text content. An empty selector (`@attr` alone,
	 * or a wholly empty string) targets the container itself rather than a
	 * descendant — the container-IS-the-link case.
	 *
	 * @param Crawler $container The record container to extract from.
	 * @param string $fieldSelector A CSS selector, optionally suffixed with
	 *                              `@attributeName`.
	 *
	 * @return string|null The extracted (trimmed) text or attribute value,
	 *                     or null when the selector matches nothing.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-markdown-and-html-source-extraction-req-007
	 */
	private function extractHtmlField(Crawler $container, string $fieldSelector): ?string {
		$attribute = null;
		$selector = $fieldSelector;

		$atPosition = strrpos($fieldSelector, '@');
		if ($atPosition !== false) {
			$selector = substr($fieldSelector, 0, $atPosition);
			$attribute = substr($fieldSelector, ($atPosition + 1));
		}

		$target = $container;
		if ($selector !== '') {
			try {
				$target = $container->filter($selector);
			} catch (\Exception $exception) {
				return null;
			}
		}

		if ($target->count() === 0) {
			return null;
		}

		if ($attribute !== null && $attribute !== '') {
			return $target->attr($attribute);
		}

		return trim($target->text(''));
	}//end extractHtmlField()

	/**
	 * Checks if the source has exceeded its rate limit and throws an exception if true.
	 *
	 * @param array $source The source object containing rate limit details.
	 *
	 * @return void
	 *
	 * @throws TooManyRequestsHttpException
	 */
	private function checkRateLimit(array $source): void {
		if (isset($source['rateLimitRemaining'], $source['rateLimitReset']) === true
			&& (int)$source['rateLimitRemaining'] <= 0
			&& (int)$source['rateLimitReset'] > time()
		) {
			throw new TooManyRequestsHttpException(
				message: 'Rate Limit on Source has been exceeded. Canceling synchronization...',
				code: 429,
				headers: $this->getRateLimitHeaders(source: $source)
			);
		}
	}//end checkRateLimit()

	/**
	 * Retrieves rate limit information from a given source and formats it as HTTP headers.
	 *
	 * This function extracts rate limit details from the provided source object and returns them
	 * as an associative array of headers. The headers can be used for communicating rate limit status
	 * in API responses or logging purposes.
	 *
	 * @param array $source The source object containing rate limit details, such as limits, remaining requests, and reset times.
	 *
	 * @return array An associative array of rate limit headers:
	 *               - 'X-RateLimit-Limit' (int|null): The maximum number of allowed requests.
	 *               - 'X-RateLimit-Remaining' (int|null): The number of requests remaining in the current window.
	 *               - 'X-RateLimit-Reset' (int|null): The Unix timestamp when the rate limit resets.
	 *               - 'X-RateLimit-Used' (int|null): The number of requests used so far.
	 *               - 'X-RateLimit-Window' (int|null): The duration of the rate limit window in seconds.
	 */

	/**
	 * Invoke CallService::call() for a Source value object.
	 *
	 * The migrated CallService is OpenRegister-native: it consumes the raw source
	 * `ObjectEntity` (reading the source body via ->getObject()). The engine,
	 * however, threads a typed Source value object around for its rate-limit and
	 * location logic. This helper bridges the two by resolving the source's
	 * OpenRegister object and delegating the call.
	 *
	 * @param array $source The source value object to call with.
	 * @param string $endpoint The endpoint to call.
	 * @param string $method The HTTP method.
	 * @param array $config The call configuration.
	 * @param bool $read Whether this is a single-object read call.
	 * @param mixed $sink Optional stream resource passed through to CallService::call() as its Guzzle
	 *                    `sink` option so the response body streams into it (stream-file-content #110);
	 *                    null = unchanged buffered behaviour.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, forwarded to
	 *                                          `CallService::call()` so the call is stamped
	 *                                          with `traceId` and captured as a `call` step
	 *                                          (execution-trace REQ-001/REQ-002,
	 *                                          http-call-engine REQ-011).
	 *
	 * @return ObjectEntity The resulting call log (an OpenRegister object).
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call_logsessionid-req-011
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering
	 */
	private function callSourceObject(
		array $source,
		string $endpoint = '',
		string $method = 'GET',
		array $config = [],
		bool $read = false,
		mixed $sink = null,
		?ExecutionTraceContext $trace = null,
	): ObjectEntity {
		return $this->callService->call(
			source: $this->resolveSourceObjectForCall(source: $source),
			endpoint: $endpoint,
			method: $method,
			config: $config,
			read: $read,
			sink: $sink,
			trace: $trace
		);
	}//end callSourceObject()

	/**
	 * Asynchronous sibling of {@see callSourceObject()}: dispatches one source
	 * call and returns a Guzzle promise resolving to the same call-log
	 * `ObjectEntity` the synchronous helper returns (ocon#111 Task 0).
	 *
	 * Source resolution is NOT duplicated here — it goes through the same
	 * {@see resolveSourceObjectForCall()} the synchronous helper uses, so the
	 * transient ad-hoc bridge (REQ-012) and the uuid-then-legacy-id addressing
	 * cannot drift between the two paths.
	 *
	 * The `$sink` MUST be a temp-file PATH. Passing a stream resource is the
	 * defect `stream-file-content` fixed, and asynchronous dispatch makes it
	 * strictly worse: Guzzle destructs the PSR-7 wrapper around a resource sink —
	 * closing the caller's handle — at a moment this caller does not control.
	 * {@see CallService::callAsync()} rejects a resource at the boundary.
	 *
	 * @param array $source The source value object to call with.
	 * @param string $endpoint The endpoint to call.
	 * @param string $method The HTTP method.
	 * @param array $config The call configuration.
	 * @param bool $read Whether this is a single-object read call.
	 * @param mixed $sink Optional temp-file PATH the response body streams into; null =
	 *                    buffered. Never a stream resource.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, forwarded so the call is
	 *                                          stamped with `traceId` and captured as a `call` step.
	 * @param callable|null $onHeaders Optional callback invoked with the PSR-7 response once its headers
	 *                                 have arrived and before the body downloads, used to read
	 *                                 `Content-Length` for the in-flight byte budget.
	 *
	 * @return PromiseInterface A promise resolving to the call-log ObjectEntity.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
	 */
	private function callSourceObjectAsync(
		array $source,
		string $endpoint = '',
		string $method = 'GET',
		array $config = [],
		bool $read = false,
		mixed $sink = null,
		?ExecutionTraceContext $trace = null,
		?callable $onHeaders = null,
	): PromiseInterface {
		return $this->callService->callAsync(
			source: $this->resolveSourceObjectForCall(source: $source),
			endpoint: $endpoint,
			method: $method,
			config: $config,
			read: $read,
			sink: $sink,
			trace: $trace,
			onHeaders: $onHeaders
		);
	}//end callSourceObjectAsync()

	/**
	 * Resolve a Source value object to the `ObjectEntity` CallService consumes.
	 *
	 * Extracted from {@see callSourceObject()} so the synchronous and asynchronous
	 * paths resolve the source identically — ocon#111 Task 0 requires that the
	 * async path cannot fork this behaviour.
	 *
	 * @param array $source The source value object.
	 *
	 * @return ObjectEntity The source as CallService expects it.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
	 */
	private function resolveSourceObjectForCall(array $source): ObjectEntity {
		// A transient, never-persisted ad-hoc source (REQ-012 — see
		// findOrCreateSourceByLocation()) has no OpenRegister object to
		// resolve; bridge it into the in-memory ObjectEntity shape CallService
		// consumes (it only reads the source body via ->getObject()).
		if (($source['_transient'] ?? false) === true) {
			$sourceObject = new ObjectEntity();
			$sourceObject->setUuid((string)($source['uuid'] ?? ''));
			$sourceObject->setObject($source);

			return $sourceObject;
		}

		// Address the source by its OpenRegister uuid (the canonical identifier);
		// the legacy int `id` is not the OpenRegister object key.
		$sourceIdentifier = ($source['uuid'] ?? null);
		if ($sourceIdentifier === null || $sourceIdentifier === '') {
			$sourceIdentifier = (string)($source['id'] ?? '');
		}

		$key = (string)$sourceIdentifier;
		if (isset($this->resolvedSourceObjects[$key]) === true) {
			return $this->resolvedSourceObjects[$key];
		}

		$this->resolvedSourceObjects[$key] = $this->findSourceObject(id: $key);

		return $this->resolvedSourceObjects[$key];
	}//end resolveSourceObjectForCall()

	/**
	 * Read the response payload from a CallService call-log object.
	 *
	 * The migrated CallService returns the call-log as an OpenRegister ObjectEntity
	 * whose body carries the `response` array (CallService sets it on the returned
	 * entity). The legacy `CallLog::getResponse()` accessor no longer exists, so
	 * read it from the object body instead.
	 *
	 * @param ObjectEntity $callLog The call-log object returned by CallService::call().
	 *
	 * @return array|null The response array, or null when absent.
	 */
	private function callLogResponse(ObjectEntity $callLog): ?array {
		$body = $callLog->getObject();

		return ($body['response'] ?? null);
	}//end callLogResponse()

	/**
	 * Read the HTTP status code from a CallService call-log object.
	 *
	 * @param ObjectEntity $callLog The call-log object returned by CallService::call().
	 *
	 * @return int|null The status code, or null when absent.
	 */
	private function callLogStatusCode(ObjectEntity $callLog): ?int {
		$body = $callLog->getObject();
		if (isset($body['statusCode']) === true) {
			return (int)$body['statusCode'];
		}

		if (isset($body['response']['statusCode']) === true) {
			return (int)$body['response']['statusCode'];
		}

		return null;
	}//end callLogStatusCode()

	/**
	 * Returns the rate limit headers for a given source object.
	 *
	 * @param array $source The source object containing rate limit details, such as limits, remaining requests, and reset times.
	 *
	 * @return array An associative array of rate limit headers.
	 */
	private function getRateLimitHeaders(array $source): array {
		return [
			'X-RateLimit-Limit' => ($source['rateLimitLimit'] ?? null),
			'X-RateLimit-Remaining' => ($source['rateLimitRemaining'] ?? null),
			'X-RateLimit-Reset' => ($source['rateLimitReset'] ?? null),
			'X-RateLimit-Used' => 0,
			'X-RateLimit-Window' => ($source['rateLimitWindow'] ?? null),
		];
	}//end getRateLimitHeaders()

	/**
	 * Updates the API request configuration with pagination details for the next page.
	 *
	 * Defaults to query-string pagination (unchanged, pre-existing behaviour).
	 * When the synchronization's `sourceConfig.paginationIn` is `"body"` (oc#94
	 * — sources like TED's v3 search that require a static POST body and can
	 * only advance by rewriting a field inside that body, not a query
	 * parameter), `CallService::normaliseRequestConfig()` substitutes the page
	 * value into the JSON body at the `paginationQuery` dot-path instead of
	 * the query string. A source that omits `paginationIn` keeps today's
	 * query-string substitution byte-for-byte.
	 *
	 * @param array $config The current request configuration.
	 * @param array $sourceConfig The source configuration containing pagination settings.
	 * @param int $currentPage The current page number for pagination.
	 *
	 * @return array Updated configuration with pagination settings.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function getNextPage(array $config, array $sourceConfig, int $currentPage): array {
		$config['pagination'] = [
			'paginationQuery' => $sourceConfig['paginationQuery'] ?? 'page',
			'paginationIn' => $sourceConfig['paginationIn'] ?? 'query',
			// What the SOURCE is sent: a page index, or an offset.
			'page' => $this->paginationValueFor(sourceConfig: $sourceConfig, currentPage: $currentPage),
			// What WE call this page. Identical to `page` for an index-paginated
			// source, and deliberately separate for an offset one: the prefetch
			// cache is keyed by page NUMBER, and once `page` started carrying an
			// offset the producer stored key 2 while the consumer looked up key
			// 100. Nothing matched, so every prefetched page was fetched a second
			// time serially — the fan-out did not merely fail to help, it added a
			// wasted request per page, which is why enabling it measured SLOWER.
			'index' => $currentPage,
		];

		return $config;
	}//end getNextPage()

	/**
	 * The value this source's cursor parameter actually takes for a given page.
	 *
	 * Two families of API count pages differently, and until now only one of
	 * them worked. A page-INDEXED source (GitHub, most REST APIs) wants
	 * `page=1,2,3`. An OFFSET source wants the number of records to skip:
	 * CKAN's `start=0,100,200`, OData's `$skip`, Solr's `start`.
	 *
	 * Handing an offset source a page index does not fail — which is the
	 * dangerous part. `start=1,2,3` against a 100-row page size returns three
	 * windows overlapping by 99 rows, so the crawl reads almost the same
	 * records over and over, terminates when the source runs out, and reports a
	 * healthy-looking run that fetched a fraction of the corpus. Nothing errors.
	 * Measured against data.overheid.nl's 19,822 datasets before this existed.
	 *
	 * Falls back to the page index whenever the offset cannot be computed —
	 * an offset needs a page SIZE, and a source that declares `paginationMode:
	 * offset` without one has told us how to count but not what to count in.
	 * Guessing a size would be worse than the pre-existing behaviour.
	 *
	 * @param array $sourceConfig The source configuration.
	 * @param int $currentPage The 1-based page the crawl is on.
	 *
	 * @return int The value to substitute for the cursor parameter.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function paginationValueFor(array $sourceConfig, int $currentPage): int {
		$mode = strtolower(trim((string)($sourceConfig['paginationMode'] ?? 'page')));
		if ($mode !== 'offset') {
			return $currentPage;
		}

		$pageSize = $this->resolvePageSize(sourceConfig: $sourceConfig);
		if ($pageSize === null || $pageSize < 1) {
			return $currentPage;
		}

		// Which index the source calls its first page. Almost always 1, but an
		// offset source that starts at 0 would otherwise skip a whole page's
		// worth of records before it read anything.
		$firstPage = (int)($sourceConfig['paginationFirstPage'] ?? 1);

		return (max($currentPage, $firstPage) - $firstPage) * $pageSize;
	}//end paginationValueFor()

	/**
	 * Extracts the next API endpoint for pagination from the response body.
	 *
	 * @param array $body The decoded JSON response body from the API.
	 * @param string $url The base URL of the API source.
	 * @param string|null $currentEndpoint The current endpoint whose query params should be preserved.
	 *
	 * @return string|null The next endpoint URL if available, or null if there is no next page.
	 */
	private function getNextEndpoint(array $body, string $url, ?string $currentEndpoint = null): ?string {
		$nextLink = $this->getNextlinkFromCall(body: $body);
		if ($nextLink === null || $nextLink === '') {
			return null;
		}

		if (str_starts_with($nextLink, $url) === true) {
			$nextLink = substr($nextLink, strlen($url));
		}

		// Preserve missing query params from current endpoint (e.g. expand=...).
		// when the API next link only contains paging information.
		if ($currentEndpoint !== null) {
			$nextParts = parse_url($nextLink);
			$currentParts = parse_url($currentEndpoint);

			$nextQuery = [];
			parse_str($nextParts['query'] ?? '', $nextQuery);
			$currentQuery = [];
			parse_str($currentParts['query'] ?? '', $currentQuery);

			foreach ($currentQuery as $key => $value) {
				if (array_key_exists($key, $nextQuery) === false) {
					$nextQuery[$key] = $value;
				}
			}

			$path = $nextParts['path'] ?? $nextLink;
			$query = http_build_query($nextQuery);

			if ($query !== '') {
				return $path . '?' . $query;
			}

			return $path;
		}//end if

		return $nextLink;
	}//end getNextEndpoint()

	/**
	 * Retrieves the next link for pagination from the API response body.
	 *
	 * @param array $body The decoded JSON body of the API response.
	 *
	 * @return string|null The URL for the next page of results, or null if there is no next page.
	 */
	public function getNextlinkFromCall(array $body): ?string {
		return $body['next'] ?? null;
	}//end getNextlinkFromCall()

	/**
	 * Extracts all objects from the API response body.
	 *
	 * @param array $array The decoded JSON body of the API response.
	 * @param array $synchronization The synchronization object containing source configuration.
	 *
	 * @return array An array of items extracted from the response body.
	 * @throws Exception If the position of objects in the return body cannot be determined.
	 */
	public function getAllObjectsFromArray(array $array, array $synchronization): array {
		// Get the source configuration from the synchronization object.
		$sourceConfig = ($synchronization['sourceConfig'] ?? []);

		// Check if a specific objects position is defined in the source configuration.
		if (empty($sourceConfig['resultsPosition']) === false) {
			$position = $sourceConfig['resultsPosition'];
			// If position is root, return the array.
			if ($position === '_root' || $position === '_object') {
				return $array;
			}

			// Use Dot notation to access nested array elements.
			$dot = new Dot($array);
			if ($dot->has($position) === true) {
				// Return the objects at the specified position.
				return $dot->get($position);
			} else {
				// Throw an exception if the specified position doesn't exist.
				return [];
				// @todo log error
				// throw new Exception("Cannot find the specified position of objects in the return body.").
			}
		}

		// Define common keys to check for objects.
		$commonKeys = ['items', 'result', 'results'];

		// Loop through common keys and return first match found.
		foreach ($commonKeys as $key) {
			if (isset($array[$key]) === true) {
				return $array[$key];
			}
		}

		// If no objects can be found, throw an exception.
		throw new Exception('Cannot determine the position of objects in the return body.');
	}//end getAllObjectsFromArray()

	/**
	 * Check one message against the message schema its config block declares.
	 *
	 * A declared validation is never skipped: without the gate, mode refuse
	 * refuses and mode record records that the message could not be checked.
	 *
	 * @param array       $config   The sourceConfig or targetConfig (dots applied).
	 * @param string      $side     SynchronizationMessageGate::SOURCE or ::TARGET.
	 * @param mixed       $message  The source object or the target body.
	 * @param string|null $originId The source object's origin id, for the finding.
	 *
	 * @return array|null The record-mode finding, or null when the message matches or nothing is declared.
	 *
	 * @throws MessageValidationRefusedException In mode refuse, when the message does not match.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
	 */
	private function inspectMessage(array $config, string $side, mixed $message, ?string $originId): ?array {
		if (SynchronizationMessageGate::declares(config: $config) === false) {
			return null;
		}

		$messageGate = $this->messageGate();
		if ($messageGate !== null) {
			return $messageGate->inspect(config: $config, side: $side, message: $message, originId: $originId);
		}

		$refusal = SynchronizationMessageGate::unavailable(config: $config, side: $side);
		if (SynchronizationMessageGate::refuses(config: $config) === true) {
			throw $refusal;
		}

		$this->logger->warning('[integriq] ' . $refusal->getMessage());

		return [
			'side' => $side,
			'originId' => $originId,
			'messageSchema' => (string)($config['validation']['messageSchema'] ?? ''),
			'errors' => [['path' => '/', 'message' => $refusal->getMessage()]],
		];
	}//end inspectMessage()

	/**
	 * The message gate, looked up in the container once; null when it is not there.
	 *
	 * @return SynchronizationMessageGate|null
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
	 */
	private function messageGate(): ?SynchronizationMessageGate {
		if ($this->messageGateResolved === false) {
			$this->messageGateResolved = true;
			$messageGate = $this->containerInterface->get(SynchronizationMessageGate::class);
			if ($messageGate instanceof SynchronizationMessageGate) {
				$this->messageGate = $messageGate;
			}
		}

		return $this->messageGate;
	}//end messageGate()

	/**
	 * Check the body about to be sent to an `api` target (REQ-MSV-003).
	 *
	 * Refuse throws before the call, so nothing is sent and the item loop
	 * dead-letters the item. Record keeps the finding for the item loop.
	 *
	 * @param array $targetConfig The target config (dots applied), its `json` the body.
	 * @param array $contract     The contract, for the origin id.
	 *
	 * @return void
	 *
	 * @throws MessageValidationRefusedException In mode refuse, when the body does not match.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
	 */
	private function inspectTargetBody(array $targetConfig, array $contract): void {
		$originId = null;
		if (isset($contract['originId']) === true) {
			$originId = (string)$contract['originId'];
		}

		$finding = $this->inspectMessage(
			config: $targetConfig,
			side: SynchronizationMessageGate::TARGET,
			message: ($targetConfig['json'] ?? null),
			originId: $originId
		);
		if ($finding !== null) {
			$this->pendingValidationFindings[] = $finding;
		}
	}//end inspectTargetBody()

	/**
	 * Move the target writes' record-mode findings into the run's result.
	 *
	 * @param array $result The run's result.
	 *
	 * @return array The result, with the findings under `validation`.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
	 */
	private function takeValidationFindings(array $result): array {
		foreach ($this->pendingValidationFindings as $finding) {
			$result['validation'][] = $finding;
		}

		$this->pendingValidationFindings = [];

		return $result;
	}//end takeValidationFindings()

	/**
	 * Write an created, updated or deleted object to an external target.
	 *
	 * @param array $synchronization The synchronization to run.
	 * @param array $contract The contract to enforce.
	 * @param string $endpoint The endpoint to write the object to.
	 * @param array|null $targetObject Update referenced targetObject so we can return response here.
	 * @param string|null $mutationType If dealing with single object synchronization, the type of the
	 *                                  mutation that will be handled, 'create', 'update' or 'delete'.
	 *                                  Used for syncs to extern sources.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, threaded through to
	 *                                          `CallService::call()` so the outbound dispatch is
	 *                                          captured as a `call` step (execution-trace
	 *                                          REQ-001/REQ-002).
	 *
	 * @param-out array $targetObject The single write-back assigns array_merge(...),
	 *        so null never comes back out even though it may go in.
	 *
	 * @return array The updated contract payload array.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function writeObjectToTarget(
		array $synchronization,
		array $contract,
		string $endpoint,
		?array &$targetObject = null,
		?string $mutationType = null,
		?ExecutionTraceContext $trace = null,
	): array {
		$write = new TargetWrite();
		$write->synchronization = $synchronization;
		$write->contract = $contract;
		$write->endpoint = $endpoint;
		$write->object = ($targetObject ?? []);
		$write->trace = $trace;
		$write->targetId = ($contract['targetId'] ?? null);

		$this->prepareTargetWrite(write: $write, readOrigin: ($targetObject === null));

		if ($mutationType === 'delete') {
			return $this->deleteFromTarget(write: $write);
		}

		// @TODO For now only JSON APIs are supported
		$write->targetConfig['json'] = $write->object;

		// A pulled object already exists in the store and carries its remote id
		// (zgw-connectors-for-dossiq D4). Without this the first local edit of a
		// pulled zaak POSTed a second zaak.
		if ($write->targetId === null) {
			$write->targetId = $this->targetIdFromObject(targetConfig: $write->targetConfig, object: $write->object);
			if ($write->targetId !== null) {
				$write->contract['targetId'] = $write->targetId;
			}
		}

		$delivered = $this->deliverTargetDocument(write: $write);
		if ($delivered !== null) {
			return $delivered;
		}

		if ($write->targetId === null) {
			return $this->createOnTarget(write: $write);
		}

		$targetObject = $this->updateOnTarget(write: $write);

		return $write->contract;
	}//end writeObjectToTarget()

	/**
	 * Resolve the target source, the object to write and the target config.
	 *
	 * For a register/schema source the OpenRegister context is set to the
	 * origin's register and schema; without a given object the origin object
	 * is read from it. An endpoint given as a full URL under the target's
	 * location is made relative to it.
	 *
	 * @param TargetWrite $write      The write.
	 * @param boolean     $readOrigin Whether to read the origin object (no object was given).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function prepareTargetWrite(TargetWrite $write, bool $readOrigin): void {
		$synchronization = $write->synchronization;
		$write->target = $this->findSource(id: ($synchronization['targetId'] ?? null));

		if (($synchronization['sourceType'] ?? null) === 'register/schema' && ($write->contract['originId'] ?? null) !== null) {
			$sourceIds = explode(separator: '/', string: ($synchronization['sourceId'] ?? null));

			$this->objectService->getOpenRegisters()->setRegister($sourceIds[0]);
			$this->objectService->getOpenRegisters()->setSchema($sourceIds[1]);

			if ($readOrigin === true) {
				$write->object = $this->objectService->getOpenRegisters()->find(
					id: $write->contract['originId'],
				)->jsonSerialize();
			}
		}

		$write->targetConfig = $this->callService->applyConfigDot(($synchronization['targetConfig'] ?? []));

		$targetLocation = ($write->target['location'] ?? '');
		if ($targetLocation !== '' && str_starts_with($write->endpoint, $targetLocation) === true) {
			$write->endpoint = str_replace(search: $targetLocation, replace: '', subject: $write->endpoint);
		}
	}//end prepareTargetWrite()

	/**
	 * Delete the object on the target and clear the contract's target id.
	 *
	 * @param TargetWrite $write The write.
	 *
	 * @return array The contract, its targetHash the hash of the answer body.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function deleteFromTarget(TargetWrite $write): array {
		$targetConfig = $write->targetConfig;
		$method = ($targetConfig['deleteMethod'] ?? 'DELETE');

		if (isset($targetConfig['deleteMapping']) === true) {
			$deleteMapping = $this->mappingService->getMapping($targetConfig['deleteMapping']);
			$targetConfig['json'] = $this->mappingService->executeMapping(mapping: $deleteMapping, input: $write->object);
		}

		$response = $this->callLogResponse(
			callLog: $this->callSourceObject(
				source: $write->target,
				endpoint: $this->deleteEndpoint(write: $write),
				method: $method,
				config: $targetConfig,
				trace: $write->trace
			)
		);

		$contract = $write->contract;
		$contract['targetHash'] = md5(serialize($response['body']));
		$contract['targetId'] = null;

		return $contract;
	}//end deleteFromTarget()

	/**
	 * The endpoint a delete goes to: the configured deleteEndpoint, or the target id under the endpoint.
	 *
	 * @param TargetWrite $write The write.
	 *
	 * @return string The endpoint.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function deleteEndpoint(TargetWrite $write): string {
		// @todo check for {{targetId}} in endpoint and replace
		if (isset($write->targetConfig['deleteEndpoint']) === true) {
			return $this->fillOriginId(
				template: $write->targetConfig['deleteEndpoint'],
				value: (string)($write->synchronization['sourceId'] ?? null)
			);
		}

		return $write->endpoint . '/' . $write->targetId;
	}//end deleteEndpoint()

	/**
	 * Replace the `{{ originId }}` placeholder (with or without spaces) in an endpoint template.
	 *
	 * @param string $template The endpoint template.
	 * @param string $value    The value to put in.
	 *
	 * @return string The endpoint.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function fillOriginId(string $template, string $value): string {
		$endpoint = str_replace(search: '{{ originId }}', replace: $value, subject: $template);

		return str_replace(search: '{{originId}}', replace: $value, subject: $endpoint);
	}//end fillOriginId()

	/**
	 * Hand a document delivery to its ZGW or StUF-ZDS leg.
	 *
	 * REQ-CSD-002: a document target creates once, in parts when the
	 * Documenten API asks for them, and never updates what it created; over
	 * StUF-ZDS by genereerDocumentIdentificatie, then voegZaakdocumentToe.
	 *
	 * @param TargetWrite $write The write.
	 *
	 * @return array|null The contract when the target delivers documents, null otherwise.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function deliverTargetDocument(TargetWrite $write): ?array {
		if (is_array($write->targetConfig['zgwDocument'] ?? null) === true) {
			return $this->pushZgwDocument(
				synchronization: $write->synchronization,
				contract: $write->contract,
				targetConfig: $write->targetConfig,
				targetId: $write->targetId
			);
		}

		if (is_array($write->targetConfig['stufDocument'] ?? null) === true) {
			return $this->pushStufDocument(
				synchronization: $write->synchronization,
				contract: $write->contract,
				targetConfig: $write->targetConfig,
				targetId: $write->targetId
			);
		}

		return null;
	}//end deliverTargetDocument()

	/**
	 * Create the object on the target and take the remote id from the answer.
	 *
	 * @param TargetWrite $write The write.
	 *
	 * @return array The contract, its targetId the created object's id.
	 *
	 * @throws Exception When the answer names no id.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function createOnTarget(TargetWrite $write): array {
		if (isset($write->targetConfig['idInRequestBody']) === true) {
			$write->targetId = $write->targetConfig['json'][$write->targetConfig['idInRequestBody']];
		}

		$callLog = $this->sendToTarget(write: $write, method: 'POST');
		$pushFailed = $this->writeBackOnFailedAnswer(synchronization: $write->synchronization, contract: $write->contract, callLog: $callLog);
		$this->refuseOnTargetConflict(callLog: $callLog, targetConfig: $write->targetConfig);
		$response = $this->callLogResponse(callLog: $callLog);

		$body = json_decode($response['body'], true);
		$targetId = $this->createdTargetId(targetConfig: $write->targetConfig, body: $body);

		$contract = $write->contract;
		$contract['targetId'] = $targetId;
		if ($pushFailed === false) {
			$this->writeOutcomeBack(
				synchronization: $write->synchronization,
				contract: $contract,
				outcome: OutcomeWriteBack::SUCCESS,
				context: ['response' => $body, 'status' => $this->callLogStatusCode(callLog: $callLog), 'targetId' => $targetId]
			);
		}

		return $contract;
	}//end createOnTarget()

	/**
	 * The id of a created object, read from the answer at the configured position or its `id`.
	 *
	 * @param array $targetConfig The target config.
	 * @param mixed $body         The decoded answer body.
	 *
	 * @return mixed The id.
	 *
	 * @throws Exception When the answer names no id.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function createdTargetId(array $targetConfig, mixed $body): mixed {
		$bodyDot = new Dot($body);

		if (isset($targetConfig['idPosition']) === true) {
			return $bodyDot->get($targetConfig['idPosition']);
		}

		// Backwards compatible if older sync still use idposition (lowercase).
		if (isset($targetConfig['idposition']) === true) {
			return $bodyDot->get($targetConfig['idposition']);
		}

		if (isset($body['id']) === true) {
			return $body['id'];
		}

		throw new Exception('Could not determine an id from target synchronization');
	}//end createdTargetId()

	/**
	 * Update the object on the target.
	 *
	 * @param TargetWrite $write The write.
	 *
	 * @return array The decoded answer body with the target id, for the caller's target object.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function updateOnTarget(TargetWrite $write): array {
		$write->endpoint = $this->updateEndpoint(write: $write);
		$method = ($write->targetConfig['updateMethod'] ?? 'PUT');

		if (isset($write->targetConfig['updateMapping']) === true) {
			$mapping = $this->mappingService->getMapping($write->targetConfig['updateMapping']);
			$write->targetConfig['json'] = $this->processMapping(mapping: $mapping, data: $write->targetConfig['json']);
		}

		$callLog = $this->sendToTarget(write: $write, method: $method);
		$pushFailed = $this->writeBackOnFailedAnswer(synchronization: $write->synchronization, contract: $write->contract, callLog: $callLog);
		$this->refuseOnTargetConflict(callLog: $callLog, targetConfig: $write->targetConfig);
		$response = $this->callLogResponse(callLog: $callLog);

		$decodedResponseBody = json_decode($response['body'] ?? '', true);
		if (is_array($decodedResponseBody) === false) {
			$decodedResponseBody = [];
		}

		if ($pushFailed === false) {
			$this->writeOutcomeBack(
				synchronization: $write->synchronization,
				contract: $write->contract,
				outcome: OutcomeWriteBack::SUCCESS,
				context: ['response' => $decodedResponseBody, 'status' => $this->callLogStatusCode(callLog: $callLog), 'targetId' => $write->targetId]
			);
		}

		return array_merge($decodedResponseBody, ['targetId' => $write->targetId]);
	}//end updateOnTarget()

	/**
	 * The endpoint an update goes to: the configured updateEndpoint, the remote id when it
	 * is a URL under the target, or the target id under the endpoint.
	 *
	 * @param TargetWrite $write The write.
	 *
	 * @return string The endpoint.
	 *
	 * @throws Exception When the remote id is a URL outside the target.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function updateEndpoint(TargetWrite $write): string {
		if (isset($write->targetConfig['updateEndpoint']) === true) {
			return $this->fillOriginId(template: $write->targetConfig['updateEndpoint'], value: (string)$write->targetId);
		}

		if (preg_match('#^https?://#i', (string)$write->targetId) === 1) {
			return $this->endpointUnderTarget(url: (string)$write->targetId, targetLocation: (string)($write->target['location'] ?? ''));
		}

		return $write->endpoint . '/' . $write->targetId;
	}//end updateEndpoint()

	/**
	 * Check the body, attach a file upload when configured, and send the write.
	 *
	 * A transport failure is written back onto the origin and rethrown.
	 *
	 * @param TargetWrite $write  The write.
	 * @param string      $method The HTTP method.
	 *
	 * @return ObjectEntity The call log.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	private function sendToTarget(TargetWrite $write, string $method): ObjectEntity {
		$this->inspectTargetBody(targetConfig: $write->targetConfig, contract: $write->contract);
		$this->applyFileUploadToTargetConfig(targetConfig: $write->targetConfig, contract: $write->contract);

		return $this->callForPush(
			synchronization: $write->synchronization,
			contract: $write->contract,
			call: fn (): ObjectEntity => $this->callSourceObject(
				source: $write->target,
				endpoint: $write->endpoint,
				method: $method,
				config: $write->targetConfig,
				trace: $write->trace
			)
		);
	}//end sendToTarget()

	/**
	 * Push one delivery as a new document to a ZGW Documenten API.
	 *
	 * `targetConfig.zgwDocument` holds `zakenSource` (the Zaken API source for
	 * the case relation), `zaakUrlField` (the object field naming the case,
	 * default `zaakUrl`), `inline` (true for a Documenten API 1.0) and where the
	 * file is: `fileName`, `fileId` or `objectId`, read like `fileUpload`
	 * (default: the source object's first file). The mapped object is the
	 * document's metadata. A contract that already holds a target id has its
	 * document: nothing is sent, because a delivery never updates or replaces
	 * the document it created.
	 *
	 * @param array       $synchronization The push synchronization.
	 * @param array       $contract        The contract.
	 * @param array       $targetConfig    The target config (json = the mapped object).
	 * @param string|null $targetId        The document url the contract holds, if any.
	 *
	 * @return array The contract, its targetId the document's url.
	 *
	 * @throws CaseSystemRefusal When an API refuses; the failure is written back first.
	 * @throws Exception         When the source object has no file to deliver.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function pushZgwDocument(array $synchronization, array $contract, array $targetConfig, ?string $targetId): array {
		if ($targetId !== null) {
			return $contract;
		}

		$zgw      = $targetConfig['zgwDocument'];
		$document = (array)($targetConfig['json'] ?? []);

		try {
			$file = $this->zgwDocumentFile(zgw: $zgw, contract: $contract, document: $document);
			$document['bestandsnaam'] = (string)($document['bestandsnaam'] ?? $file['filename']);
			$document['formaat']      = (string)($document['formaat'] ?? $file['mimeType']);

			$result = $this->containerInterface->get(ZgwDocumentDelivery::class)->deliver(
				document: $document,
				content: $file['content'],
				settings: [
					'documentenSource' => (string)($synchronization['targetId'] ?? ''),
					'zakenSource' => (string)($zgw['zakenSource'] ?? ''),
					'zaakUrl' => (string)($document[(string)($zgw['zaakUrlField'] ?? 'zaakUrl')] ?? ''),
					'inline' => (($zgw['inline'] ?? false) === true),
				]
			);
		} catch (\Throwable $e) {
			$status = null;
			if ($e instanceof CaseSystemRefusal) {
				$status = $e->getStatus();
			}

			$this->writeOutcomeBack(
				synchronization: $synchronization,
				contract: $contract,
				outcome: OutcomeWriteBack::FAILURE,
				context: ['status' => $status, 'error' => ['message' => $this->outcomeWriteBack()->truncate(message: $e->getMessage()), 'status' => $status]]
			);
			throw $e;
		}//end try

		$contract['targetId'] = $result['url'];
		$this->writeOutcomeBack(
			synchronization: $synchronization,
			contract: $contract,
			outcome: OutcomeWriteBack::SUCCESS,
			context: [
				'response' => array_merge($result['document'], ['url' => $result['url'], 'zaakinformatieobject' => $result['zaakinformatieobject']]),
				'status' => 201,
				'targetId' => $result['url'],
			]
		);

		return $contract;
	}//end pushZgwDocument()

	/**
	 * Push one delivery as a new document to a StUF-ZDS case system.
	 *
	 * The synchronization's target is a `stuf-zkn` source, read raw so its
	 * token or certificate survives the render. `targetConfig.stufDocument`
	 * holds `documenttype` (the `dct.omschrijving`; else the mapped object's
	 * `documenttype`), `zaakIdentificatieField` (default `zaakIdentificatie`)
	 * and where the file is, as for `zgwDocument`. The case system's document
	 * identificatie becomes the contract's target id and is written back as
	 * `{{ response.identificatie }}`; a contract that holds one sends nothing.
	 *
	 * @param array       $synchronization The push synchronization.
	 * @param array       $contract        The contract.
	 * @param array       $targetConfig    The target config (json = the mapped object).
	 * @param string|null $targetId        The identificatie the contract holds, if any.
	 *
	 * @return array The contract, its targetId the document identificatie.
	 *
	 * @throws \Throwable When the delivery fails; the failure is written back first.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function pushStufDocument(array $synchronization, array $contract, array $targetConfig, ?string $targetId): array {
		if ($targetId !== null) {
			return $contract;
		}

		$stuf     = $targetConfig['stufDocument'];
		$document = (array)($targetConfig['json'] ?? []);

		try {
			$caseField = (string)($stuf['zaakIdentificatieField'] ?? 'zaakIdentificatie');
			$case      = trim((string)($document[$caseField] ?? ''));
			if ($case === '') {
				throw new Exception('The object ' . (string)($contract['originId'] ?? '') . ' names no case in ' . $caseField . ' to add the document to.');
			}

			$file   = $this->zgwDocumentFile(zgw: $stuf, contract: $contract, document: $document, destination: 'the StUF-ZDS case system');
			$result = $this->containerInterface->get(StufZdsDocumentDelivery::class)->deliver(
				source: $this->rawSourceObject(id: (string)($synchronization['targetId'] ?? '')),
				document: $document,
				file: $file,
				zaakIdentificatie: $case,
				documenttype: (string)($stuf['documenttype'] ?? ($document['documenttype'] ?? ''))
			);
		} catch (\Throwable $e) {
			$this->writeOutcomeBack(
				synchronization: $synchronization,
				contract: $contract,
				outcome: OutcomeWriteBack::FAILURE,
				context: ['status' => null, 'error' => ['message' => $this->outcomeWriteBack()->truncate(message: $e->getMessage()), 'status' => null]]
			);
			throw $e;
		}//end try

		$contract['targetId'] = $result['identificatie'];
		$this->writeOutcomeBack(
			synchronization: $synchronization,
			contract: $contract,
			outcome: OutcomeWriteBack::SUCCESS,
			context: ['response' => $result, 'status' => 200, 'targetId' => $result['identificatie']]
		);

		return $contract;
	}//end pushStufDocument()

	/**
	 * A source's object read raw, so its write-only credentials survive.
	 *
	 * Same read as CallService::resolveSourceForDispatch(): the engine, not the
	 * user, needs the source, and a rendered read strips the token. Falls back
	 * to the rendered source when the raw read fails, whose missing credential
	 * the client then refuses.
	 *
	 * @param string $id The source id.
	 *
	 * @return array The source object.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function rawSourceObject(string $id): array {
		try {
			$raw = $this->orObjectService->find(
				id: $id,
				register: 'integriq',
				schema: 'source',
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
			if ($raw !== null) {
				return $raw->getObject();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Raw source read failed; using the rendered source.', ['sourceId' => $id, 'exception' => $e->getMessage()]);
		}

		return $this->findSourceObject(id: $id)->getObject();
	}//end rawSourceObject()

	/**
	 * The file a ZGW document push delivers, found the way `fileUpload` finds one.
	 *
	 * `fileIdField` names a field of the mapped object that holds a Nextcloud
	 * file id (filinq's `resultFileRef` on a redacted copy); its value is used
	 * as `fileId`, and an empty value is refused before anything is sent.
	 *
	 * @param array $zgw      The zgwDocument config (fileName, fileId, fileIdField, objectId).
	 * @param array $contract The contract (originId = the source object).
	 * @param array  $document    The mapped object.
	 * @param string $destination Where the file goes, for the refusal message.
	 *
	 * @return array{content:string,filename:string,mimeType:string}
	 *
	 * @throws Exception When no file is found.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	private function zgwDocumentFile(array $zgw, array $contract, array $document, string $destination='the Documenten API'): array {
		$field = (string)($zgw['fileIdField'] ?? '');
		if ($field !== '') {
			$fileId = (string)($document[$field] ?? '');
			if ($fileId === '') {
				$origin = (string)($contract['originId'] ?? '');
				throw new Exception('The object ' . $origin . ' has no file id in ' . $field . ' to deliver to ' . $destination . '.');
			}

			$zgw['fileId'] = $fileId;
		}

		$probe = [
			'json' => [],
			'fileUpload' => array_intersect_key($zgw, array_flip(['fileName', 'fileId', 'objectId'])) + ['fieldName' => 'inhoud'],
		];
		$this->applyFileUploadToTargetConfig(targetConfig: $probe, contract: $contract);

		foreach ((array)($probe['multipart'] ?? []) as $part) {
			if (isset($part['filename']) === true) {
				return [
					'content' => (string)$part['contents'],
					'filename' => (string)$part['filename'],
					'mimeType' => (string)($part['headers']['Content-Type'] ?? 'application/octet-stream'),
				];
			}
		}

		throw new Exception('The object ' . (string)($contract['originId'] ?? '') . ' has no file to deliver to ' . $destination . '.');
	}//end zgwDocumentFile()

	/**
	 * Make a push's call, and write the failure back when the transport gives up.
	 *
	 * CallService::call() spends the source's retry budget before it returns
	 * or throws, so what reaches this method is the attempt's final outcome.
	 *
	 * @param array    $synchronization The push synchronization.
	 * @param array    $contract        The contract (its originId names the source object).
	 * @param callable $call            Makes the call and returns its call log.
	 *
	 * @return ObjectEntity The call log.
	 *
	 * @throws \Throwable Whatever the call threw, after the failure is written back.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
	 */
	private function callForPush(array $synchronization, array $contract, callable $call): ObjectEntity {
		try {
			return $call();
		} catch (\Throwable $e) {
			$this->writeOutcomeBack(
				synchronization: $synchronization,
				contract: $contract,
				outcome: OutcomeWriteBack::FAILURE,
				context: ['error' => ['message' => $this->outcomeWriteBack()->truncate(message: $e->getMessage())]]
			);
			throw $e;
		}
	}//end callForPush()

	/**
	 * Write the failure back when the target answered 4xx or 5xx.
	 *
	 * @param array        $synchronization The push synchronization.
	 * @param array        $contract        The contract.
	 * @param ObjectEntity $callLog         The call log of the attempt.
	 *
	 * @return bool True when the answer was a failure.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
	 */
	private function writeBackOnFailedAnswer(array $synchronization, array $contract, ObjectEntity $callLog): bool {
		$status = $this->callLogStatusCode(callLog: $callLog);
		if ($status === null || $status < 400) {
			return false;
		}

		$response = $this->callLogResponse(callLog: $callLog);
		$body     = ($response['body'] ?? null);
		if (is_string($body) === false) {
			$body = null;
		}

		$decoded = json_decode((string)$body, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		$this->writeOutcomeBack(
			synchronization: $synchronization,
			contract: $contract,
			outcome: OutcomeWriteBack::FAILURE,
			context: [
				'response' => $decoded,
				'status' => $status,
				'error' => [
					'message' => $this->outcomeWriteBack()->messageFromAnswer(body: $body, status: $status),
					'status' => $status,
				],
			]
		);

		return true;
	}//end writeBackOnFailedAnswer()

	/**
	 * Write a push's outcome onto the OpenRegister object that started it, silently.
	 *
	 * Silent, like markWriteBack(): a normal save fires the object event and
	 * the push would run again. Only a `register/schema` source has an object
	 * to write onto. A write-back that fails is logged and never fails the push,
	 * which already happened.
	 *
	 * @param array                $synchronization The push synchronization.
	 * @param array                $contract        The contract (originId = the source object's uuid).
	 * @param string               $outcome         OutcomeWriteBack::SUCCESS or ::FAILURE.
	 * @param array<string, mixed> $context         What the templates read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
	 */
	private function writeOutcomeBack(array $synchronization, array $contract, string $outcome, array $context): void {
		$fields   = $this->outcomeWriteBack()->fields(writeBack: ($synchronization['writeBack'] ?? null), outcome: $outcome, context: $context);
		$originId = (string)($contract['originId'] ?? '');
		$sourceId = explode(separator: '/', string: (string)($synchronization['sourceId'] ?? ''));
		if ($fields === [] || $originId === '' || ($synchronization['sourceType'] ?? null) !== 'register/schema' || count($sourceId) !== 2) {
			return;
		}

		try {
			$data = $this->orObjectService->find(id: $originId, register: $sourceId[0], schema: $sourceId[1])->getObject();
			$this->orObjectService->saveObject(
				object: array_merge($data, $fields),
				register: $sourceId[0],
				schema: $sourceId[1],
				uuid: $originId,
				silent: true
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Could not write the push outcome back onto object ' . $originId . ' for synchronization '
				. (string)($synchronization['id'] ?? '') . ': ' . $e->getMessage()
			);
		}
	}//end writeOutcomeBack()

	/**
	 * The write-back template filler, made once.
	 *
	 * @return OutcomeWriteBack The filler.
	 *
	 * @spec exclude lazy holder for a stateless helper
	 */
	private function outcomeWriteBack(): OutcomeWriteBack {
		if ($this->outcomeWriteBack === null) {
			$this->outcomeWriteBack = new OutcomeWriteBack();
		}

		return $this->outcomeWriteBack;
	}//end outcomeWriteBack()

	/**
	 * Synchronize data to a target.
	 *
	 * The synchronizationContract should be given if the normal procedure to find the contract
	 * (on originId) is not available to the contract that should be updated.
	 *
	 * @param ObjectEntity $object The object to synchronize.
	 * @param array|null $synchronizationContract If given: the contract payload array to update.
	 * @param bool|null $force If true, the object is updated regardless of changes.
	 * @param bool|null $test Whether this is a test run.
	 * @param SynchronizationRunLog|null $log The synchronization run log.
	 *
	 * @return array The updated synchronizationContracts.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws LoaderError
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 * @throws GuzzleException
	 */
	public function synchronizeToTarget(
		ObjectEntity $object,
		?array $synchronizationContract = null,
		?bool $force = false,
		?bool $test = false,
		?SynchronizationRunLog $log = null,
	): array {
		$objectId = $object->getUuid();

		if ($synchronizationContract === null) {
			$synchronizationContract = $this->findContractByOriginId(originId: $objectId);
		}

		$synchronizations = $this->findAllSynchronizations(
			filters: [
				'sourceType' => 'register/schema',
				'sourceId' => "{$object->getRegister()}/{$object->getSchema()}",
			]
		);
		if (count($synchronizations) === 0) {
			return [];
		}

		$synchronization = $synchronizations[0];

		if (is_array($synchronizationContract) === false) {
			$synchronizationContract = $this->createContractFromArray(
				object: [
					'synchronizationId' => ($synchronization['id'] ?? null),
					'originId' => $objectId,
				]
			);
		}

		$serializedObject = $object->jsonSerialize();

		$flowToken = new FlowToken(syncInputOriginal: $serializedObject);

		$synchronizationContract = $this->synchronizeContract(
			synchronizationContract: $synchronizationContract,
			synchronization: $synchronization,
			flowToken: $flowToken,
			object: $serializedObject,
			isTest: $test,
			force: $force,
			log: $log
		);

		if ($synchronizationContract instanceof Exception) {
			return [];
		}

		// The synchronizeContract call returns the
		// `['log' => ..., 'contract' => ..., 'resultAction' => ...]` result
		// shape; extract the contract payload before persisting.
		$contract = ($synchronizationContract['contract'] ?? null);
		if (is_array($contract) === true) {
			$contract = $this->persistContract(contract: $contract);
		}

		return [$contract];
	}//end synchronizeToTarget()

	/**
	 * Saves object to OpenRegister.
	 *
	 * @param array $rule The OpenRegister rule payload array.
	 * @param array $data The data to be saved.
	 *
	 * @return array The serialized saved object payload.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function processSaveObjectRule(array $rule, array $data): array {
		$configuration = ($rule['configuration'] ?? []);
		$register = $configuration['save_object']['register'];
		$schema = $configuration['save_object']['schema'];
		$mapping = $configuration['save_object']['mapping'] ?? null;
		$patch = $configuration['save_object']['patch'] ?? false;
		$id = null;

		if (empty($mapping) === false) {
			if (isset($data['_objectBeforeMapping']['id']) === true) {
				$id = $data['_objectBeforeMapping']['id'];
				unset($data['_objectBeforeMapping']);
			}

			$mapping = $this->mappingService->getMapping($mapping);
			$data = $this->processMapping(mapping: $mapping, data: $data);
		}

		if ($patch === true || $patch === 'true') {
			$object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($id);
			$data = array_merge($object->getObject(), ['id' => $object->getId()], $data);
		}

		$object = $this->orObjectService->saveObject(register: $register, schema: $schema, object: $data)->jsonSerialize();

		return $object;
	}//end processSaveObjectRule()

	/**
	 * Extends input for performing business logic.
	 *
	 * @param array $config The rule configuration which parameters could be extended.
	 * @param array $data The data array containing the input parameters.
	 *
	 * @return array The data array with the extended parameters merged in.
	 *
	 * @throws ContainerExceptionInterface When the container fails to resolve a service.
	 * @throws NotFoundExceptionInterface When a required service is not found.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function processExtendInputRule(array $config, array $data): array {
		$parameters = new Dot($data);
		$extendedParameters = new Dot();

		// If the extends are given as a comma separated string, separate them into an array.
		if (is_string($config['extend_input']['properties']) === true) {
			$config['extend_input']['properties'] = explode(
				separator: ',',
				string: $config['extend_input']['properties']
			);
		}

		if (isset($data['id']) === true || isset($data['@self']['id']) === true || ($data['uuid'] ?? null) === true) {
			$id = ($data['@self']['id'] ?? $data['id'] ?? $data['uuid']);
		}

		// If we can fetch the object to extend again, use OpenRegister to fetch the extended object.
		$fetchObject = ($config['extend_input']['fetchObject'] ?? null);
		if (isset($id) === true && isset($config['extend_input']['fetchObject']) === true
			&& ($fetchObject === true || $fetchObject === 'true')
		) {
			$object = $this->objectService->getOpenRegisters()->find(
				id: $id,
				_extend: $config['extend_input']['properties']
			);
			return $object->jsonSerialize();
		}

		foreach ($config['extend_input']['properties'] as $property) {
			$value = $parameters->get($property);

			if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
				$exploded = explode(separator: '/', string: $value);
				$value = end($exploded);
			}

			try {
				$object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find(identifier: $value);
			} catch (DoesNotExistException $exception) {
				continue;
			}

			$extendedParameters->add($property, $object->jsonSerialize());
		}

		return array_merge($data, $extendedParameters->all());
	}//end processExtendInputRule()

	/**
	 * Processes rules for an endpoint request.
	 *
	 * @param array $synchronization The endpoint being processed.
	 * @param array $data Current request data.
	 * @param string $timing The timing phase the rules should run in.
	 * @param string|null $objectId The UUID of the object being processed.
	 * @param int|null $registerId The register the object belongs to.
	 * @param int|null $schemaId The schema the object belongs to.
	 * @param FlowToken|null $flowToken The flow token shared across the rules.
	 *
	 * @return array|JSONResponse Returns modified data or error response if rule fails.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) 110 lines.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) 11 against a threshold of 10.
	 *   One `switch` over the rule ACTION types the engine supports, with each arm
	 *   handling one action. The complexity is the size of that vocabulary, and a
	 *   reader needs the arms side by side to see which actions exist — dispatching
	 *   to one handler class per action is the right shape once the list grows, and
	 *   is a design change rather than something to do inside a lint pass.
	 */
	private function processRules(
		array $synchronization,
		array $data,
		string $timing,
		?string $objectId = null,
		?int $registerId = null,
		?int $schemaId = null,
		?FlowToken $flowToken = null,
	): array|JSONResponse {
		$rules = ($synchronization['actions'] ?? []);
		if (empty($rules) === true) {
			return $data;
		}

		try {
			// Get all rules at once and sort by order.
			$ruleEntities = array_filter(
				array_map(
					fn ($ruleId) => $this->getRuleById(id: $ruleId),
					$rules
				)
			);

			// Sort rules by order.
			usort($ruleEntities, fn ($a, $b) => ((int)($a['order'] ?? 0)) - ((int)($b['order'] ?? 0)));

			// Process each rule in order.
			foreach ($ruleEntities as $rule) {
				if ($flowToken !== null) {
					$data['flowToken'] = $flowToken->__serialize();
				}

				// Check rule conditions.
				if ($this->checkRuleConditions(rule: $rule, data: $data) === false || ($rule['timing'] ?? null) !== $timing) {
					$this->logger->info(
						'Rule condition check failed for synchronization ' . ($synchronization['name'] ?? '')
						. ' and rule ' . ($rule['name'] ?? '') . ' of type: ' . ($rule['type'] ?? '')
					);
					unset($data['flowToken']);
					continue;
				}

				unset($data['flowToken']);

				$this->logger->info(
					'Applying rule for synchronization ' . ($synchronization['name'] ?? '')
					. ' with rule ' . ($rule['name'] ?? '') . ' of type ' . ($rule['type'] ?? '')
				);

				// Process rule based on type.
				$result = match ($rule['type'] ?? null) {
					'error' => $this->processErrorRule(rule: $rule),
					'mapping' => $this->processMappingRule(rule: $rule, data: $data),
					'synchronization' => $this->processSyncRule(rule: $rule, data: $data),
					'save_object' => $this->processSaveObjectRule(rule: $rule, data: $data),
					'fetch_file' => $this->processFetchFileRule(rule: $rule, data: $data, objectId: $objectId),
					'write_file' => $this->processWriteFileRule(
						rule: $rule,
						data: $data,
						objectId: $objectId,
						registerId: $registerId,
						schemaId: $schemaId
					),
					'extend_input' => $this->processExtendInputRule(config: ($rule['configuration'] ?? []), data: $data),
					default => throw new Exception('Unsupported rule type: ' . ($rule['type'] ?? '')),
				};

				// If result is JSONResponse, return error immediately.
				if ($result instanceof JSONResponse) {
					return $result;
				}

				// Update data with rule result.
				$data = $result;

				// A fetch_file rule reports WHAT IT DID, not merely that it ran.
				// `processFetchFileRule()` catches its own per-file failures so
				// one bad attachment cannot abort the object, which meant this
				// line said "Successfully applied" for a rule that wrote ZERO
				// files — and nothing downstream could tell that from 40. The
				// tally is the only thing that discriminates.
				$outcome = '';
				if (($rule['type'] ?? null) === 'fetch_file') {
					$tally = $this->takeFileFetchTally();
					$outcome = ' — files saved: ' . $tally['succeeded'] . ', failed: ' . $tally['failed'];

					if ($tally['succeeded'] === 0 && $tally['failed'] > 0) {
						$this->logger->warning(
							'Rule ' . ($rule['name'] ?? '') . ' of type fetch_file saved NO files ('
							. $tally['failed'] . ' failed) for synchronization '
							. ($synchronization['name'] ?? '') . '. The rule ran; its files did not arrive.'
						);
					}
				}

				$this->logger->info(
					'Successfully applied rule for synchronization ' . ($synchronization['name'] ?? '')
					. ' with rule ' . ($rule['name'] ?? '') . ' of type ' . ($rule['type'] ?? '')
					. $outcome
				);
			}//end foreach

			return $data;
		} catch (Exception $e) {
			$this->logger->error(
				'Error processing rules for synchronization ' . ($synchronization['name'] ?? '') . ': ' . $e->getMessage()
			);
			return new JSONResponse(['error' => 'Rule processing failed: ' . $e->getMessage()], 500);
		}//end try
	}//end processRules()

	/**
	 * Resolve one rule by id, for callers outside this service.
	 *
	 * `SynchronizationFlowGenerator` has to know what a synchronization's
	 * `actions` ARE before it can decide whether they are expressible as flow
	 * steps — refusing every synchronization that declares any is what kept 74
	 * of them unmigratable. It needs the rule's `type` and `timing`, nothing
	 * more, and re-implementing the lookup against register `openconnector` /
	 * schema `rule` in a second place is how the two drift.
	 *
	 * ⚠️ THE READ IS DELIBERATELY UNSCOPED, and delegating to `getRuleById()`
	 * was wrong for exactly that reason. That method reads WITH rbac and
	 * multitenancy, which is right for the legacy engine's in-request rule
	 * pipeline and wrong here: the generator runs under `occ`, where there is
	 * no user session, so a scoped read filters every rule away and returns
	 * null. The generator then cannot establish what the rule does and refuses
	 * the synchronization — which is not "this rule is unsupported", it is "I
	 * could not look".
	 *
	 * MEASURED, and this is why the flag matters rather than being defensive:
	 * after `openconnector.fetch-file` shipped, a full sweep of all 240
	 * synchronizations still refused the same 74, and ALL 74 gave
	 * `actions: rule "…" could not be resolved`. Not one of them was refused
	 * for its rule's TYPE. The feature was complete and unreachable.
	 *
	 * `MigrationEntityReader` already reads this way and says why in its own
	 * docblock; this is the same rule applied to the one entity that did not go
	 * through it.
	 *
	 * @param string $id The rule's OpenRegister uuid or slug.
	 *
	 * @return array|null The rule payload, or null when there is no such rule.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function findRule(string $id): ?array {
		try {
			$object = $this->orObjectService->find(
				id: $id,
				register: 'integriq',
				schema: 'rule',
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			// FULLY QUALIFIED on purpose: this file declares
			// `namespace OCA\Integriq\Service` and imports `Exception` but not
			// `Throwable`, so a bare `Throwable` here would resolve to
			// `OCA\Integriq\Service\Throwable`, match nothing, and let the
			// failure escape as a fatal instead of the null this contract
			// promises. `php -l` does not catch that.
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end findRule()

	/**
	 * Run ONE `fetch_file` rule, by id, outside the legacy rule pipeline.
	 *
	 * WHY THIS SEAM EXISTS. `openconnector.fetch-file` is a flow step over the
	 * same behaviour, and the decomposed pipeline has no `processRules()` pass
	 * to hang it off. The alternative was to lift the 75 lines of
	 * `processFetchFileRule()` into something both callers share, and that is a
	 * refactor of the legacy write path for the benefit of a new one — the kind
	 * of change that looks behaviour-preserving and is not. A public entry
	 * point that RESOLVES a rule and delegates keeps exactly one
	 * implementation, and the flow step and the legacy engine cannot drift
	 * apart because there is nothing to drift.
	 *
	 * It also means the generated flow references the SAME Rule entity the
	 * synchronization referenced. An operator who edits that rule keeps
	 * affecting both engines, which is what makes a half-migrated instance
	 * survivable.
	 *
	 * ⚠️ TYPE IS CHECKED, NOT ASSUMED. Another rule type reaching here would be
	 * silently handled as a file fetch — reading `configuration.fetch_file` off
	 * a rule that has none, finding nothing, and returning the data untouched
	 * while reporting success. A step that does nothing and says it worked is
	 * the failure this whole change keeps running into, so it refuses instead.
	 *
	 * @param string $ruleId The rule's OpenRegister uuid or slug.
	 * @param array $data The item data the rule acts on.
	 * @param string|null $objectId The written object, when there is one.
	 *
	 * @return array The data, with placeholders applied when configured.
	 *
	 * @throws Exception When the rule is missing or is not a `fetch_file` rule.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function runFetchFileRule(string $ruleId, array $data, ?string $objectId = null): array {
		$rule = $this->getRuleById(id: $ruleId);
		if ($rule === null) {
			throw new Exception('fetch_file rule "' . $ruleId . '" was not found.');
		}

		$type = trim((string)($rule['type'] ?? ''));
		if ($type !== 'fetch_file') {
			throw new Exception(
				'Rule "' . $ruleId . '" is of type "' . $type . '", not fetch_file.'
			);
		}

		return $this->processFetchFileRule(rule: $rule, data: $data, objectId: $objectId);
	}//end runFetchFileRule()

	/**
	 * Get a rule by its ID directly from OpenRegister.
	 *
	 * Post W6 the typed Rule value object is dropped: the rule is fetched
	 * straight from the OpenRegister ObjectService (register `openconnector`,
	 * schema `rule`) and returned as the OR jsonSerialize() array payload.
	 * Downstream rule processors read fields via array access
	 * (`$rule['type']`, `$rule['configuration']`, ...).
	 *
	 * @param string $id The unique identifier of the rule (OpenRegister UUID or slug).
	 *
	 * @return array|null The rule payload array if found, or null if not found
	 */
	private function getRuleById(string $id): ?array {
		try {
			$object = $this->orObjectService->find(
				id: $id,
				register: 'integriq',
				schema: 'rule'
			);
		} catch (Exception $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end getRuleById()

	/**
	 * Fetch a file from a source.
	 *
	 * @param array $source The source to fetch the file from.
	 * @param string $endpoint The endpoint for the file.
	 * @param array $config The configuration of the action.
	 * @param string $objectId The id of the object the file belongs to.
	 * @param array|null $tags Tags to assign to the file.
	 * @param string|null $filename Filename to assign to the file.
	 * @param string|null $published The published status to determine if the file should be published.
	 * @param int|string|null $registerId The id of the register the object belongs to.
	 *
	 * @return string If write is enabled: the url of the file, if write is disabled: the base64 encoded file.
	 * @throws ContainerExceptionInterface
	 * @throws GenericFileException
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws LockedException
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 */
	private function fetchFile(
		array $source,
		string $endpoint,
		array $config,
		string $objectId,
		?array $tags = [],
		?string &$filename = null,
		?string $published = null,
		int|string|null $registerId = null,
	): string {
		$prepared = $this->prepareFileFetch(source: $source, endpoint: $endpoint, config: $config);

		try {
			$callLog = $this->callSourceObject(
				source: $source,
				endpoint: $prepared['endpoint'],
				method: $prepared['config']['method'] ?? 'GET',
				config: $prepared['config']['sourceConfiguration'] ?? [],
				sink: $prepared['sinkPath']
			);

			return $this->saveFetchedFile(
				prepared: $prepared,
				callLog: $callLog,
				objectId: $objectId,
				tags: $tags,
				filename: $filename,
				published: $published,
				registerId: $registerId
			);
		} finally {
			$this->releaseFileFetch(prepared: $prepared);
		}//end try
	}//end fetchFile()

	/**
	 * Fetch phase of {@see fetchFile()}: everything that must happen BEFORE the
	 * HTTP request, producing the descriptor the save phase consumes.
	 *
	 * Split out for ocon#111 so a download can be dispatched asynchronously and
	 * saved later, from a promise callback, without re-deriving the endpoint,
	 * re-rendering the source configuration, or re-deciding the transport. The
	 * returned array is the unit of per-file state the concurrent path carries
	 * across the promise boundary — including the temp path whose release
	 * {@see releaseFileFetch()} owns.
	 *
	 * @param array $source The source to fetch the file from.
	 * @param string $endpoint The endpoint for the file.
	 * @param array $config The configuration of the action.
	 *
	 * @return array{originalEndpoint: string, endpoint: string, config: array, useSink: boolean, sinkPath: string|null}
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function prepareFileFetch(array $source, string $endpoint, array $config): array {
		$originalEndpoint = $endpoint;
		$sourceLocation = (string)($source['location'] ?? '');
		if (str_contains(haystack: $endpoint, needle: $sourceLocation) === true) {
			$endpoint = substr(string: $endpoint, offset: strlen(string: $sourceLocation));
		}

		$sourceConfig = json_encode($config['sourceConfiguration']);
		if (isset($config['originId']) === true) {
			$sourceConfig = str_replace(search: '{{ originId }}', replace: $config['originId'], subject: $sourceConfig);
		}

		$sourceConfig = json_decode($sourceConfig, true);

		if (isset($sourceConfig['body']) === true
			|| (isset($config['method']) === true && $config['method'] !== 'GET')
		) {
			$sourceConfig['body'] = json_encode($sourceConfig['body'] ?? []);
		}

		$config['sourceConfiguration'] = $sourceConfig;

		// Choose the transport up front (stream-file-content #110): a raw binary
		// download — no contentPath/filenamePath addressing a JSON envelope —
		// streams straight to a disk-backed temp FILE via CallService's sink
		// option, so the file is never buffered in a PHP string. A JSON envelope
		// response stays on the existing in-memory string path (its body must be
		// parsed to extract contentPath/filenamePath).
		//
		// A PATH is handed to Guzzle, never our own handle. Guzzle wraps a
		// resource-typed `sink` in a PSR-7 Stream and CLOSES that resource when the
		// stream is destructed — so a shared handle came back to us already closed.
		// `is_resource()` then reported false, the write side took its string
		// branch, and `str_starts_with()` threw "must be of type string, resource
		// given"; the per-item catch tallied every object as `invalid`, no file was
		// written and no contract was persisted (which is why re-runs re-created
		// objects instead of updating them). Passing a path keeps ownership clean:
		// Guzzle opens and closes its own handle, we open ours for the write.
		//
		// Concurrency makes this a prerequisite rather than a detail: under
		// requestAsync() the response body stream is destructed at a moment the
		// caller does not control, so N shared handles would be a strictly worse
		// version of the same defect.
		$useSink = (empty($config['contentPath']) === true && empty($config['filenamePath']) === true);

		$sinkPath = null;
		if ($useSink === true) {
			$sinkPath = tempnam(sys_get_temp_dir(), 'oc-stream-');
			if ($sinkPath === false) {
				// The temp file could not be created; fall back to the buffered path.
				$sinkPath = null;
				$useSink = false;
			}
		}

		return [
			'originalEndpoint' => $originalEndpoint,
			'endpoint' => $endpoint,
			'config' => $config,
			'useSink' => $useSink,
			'sinkPath' => $sinkPath,
		];
	}//end prepareFileFetch()

	/**
	 * Release the temp file a {@see prepareFileFetch()} descriptor owns.
	 *
	 * The temp file is ours to remove: Guzzle only ever wrote to the path, and
	 * closed its own handle. Idempotent, so it is safe to call from a `finally`,
	 * from a promise's `then()` AND from its `otherwise()` — which is exactly what
	 * the concurrent path needs, since splitting fetch from save moved the release
	 * out of one `finally` per call and N partial failures otherwise leak a temp
	 * file each.
	 *
	 * @param array $prepared The prepareFileFetch() descriptor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	private function releaseFileFetch(array $prepared): void {
		$sinkPath = ($prepared['sinkPath'] ?? null);
		if ($sinkPath !== null && file_exists($sinkPath) === true) {
			unlink($sinkPath);
		}
	}//end releaseFileFetch()

	/**
	 * Save phase of {@see fetchFile()}: turns an already-resolved download into a
	 * stored OpenRegister file.
	 *
	 * Split out for ocon#111 so this can run from a fetch promise's `then()` while
	 * sibling downloads are still in flight. It re-fetches nothing — the download
	 * is already on disk at `$prepared['sinkPath']` (streamed path) or already in
	 * the call log's body (JSON-envelope path).
	 *
	 * This phase owns the READ handle only: it opens its own handle on the temp
	 * file and closes it, while the temp file itself is released by
	 * {@see releaseFileFetch()}. Saves are invoked one at a time even when
	 * fetches overlap — PHP is single-threaded and Nextcloud uses one shared
	 * database connection, so concurrent OpenRegister writes are not safe.
	 *
	 * @param array $prepared The prepareFileFetch() descriptor for this file.
	 * @param ObjectEntity $callLog The call log returned by the (synchronous or asynchronous) dispatch.
	 * @param string $objectId The id of the object the file belongs to.
	 * @param array|null $tags Tags to assign to the file.
	 * @param string|null $filename Filename to assign to the file; resolved here when null.
	 * @param string|null $published The published status to determine if the file should be published.
	 * @param int|string|null $registerId The id of the register the object belongs to.
	 *
	 * @return string If write is enabled: the url of the file, if write is disabled: the base64 encoded file.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws GenericFileException
	 * @throws LockedException
	 * @throws NotFoundExceptionInterface
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function saveFetchedFile(
		array $prepared,
		ObjectEntity $callLog,
		string $objectId,
		?array $tags = [],
		?string &$filename = null,
		?string $published = null,
		int|string|null $registerId = null,
	): string {
		$file = new FetchedFile(prepared: $prepared, callLog: $callLog);
		$file->response   = $this->callLogResponse(callLog: $callLog);
		$file->objectId   = $objectId;
		$file->tags       = $tags;
		$file->filename   = $filename;
		$file->published  = $published;
		$file->registerId = $registerId;

		try {
			$this->readFetchedFile(file: $file);

			if (isset($file->config['write']) === true && $file->config['write'] === false) {
				return $this->encodeFetchedFile(file: $file);
			}

			$this->resolveFetchedFileTarget(file: $file);
			$this->storeFetchedFile(file: $file);

			return $file->originalEndpoint;
		} finally {
			$filename = $file->filename;

			// Guard on is_resource(), NOT on `!== null`: once the handle has been
			// handed to FileService::saveFile()/addFile() the write side streams
			// it via OCP\Files\File::putContent(), which CLOSES the source stream
			// itself. A second fclose() on that closed handle throws a TypeError
			// that the per-item handler in synchronizeExternToIntern() would tally
			// as `invalid`.
			//
			// The temp FILE is released by releaseFileFetch() rather than here:
			// the concurrent path calls this save phase from a promise callback
			// and must unlink on the rejected leg too, where this method never
			// runs at all.
			if (is_resource($file->sink) === true) {
				fclose($file->sink);
			}
		}//end try
	}//end saveFetchedFile()

	/**
	 * Open the read handle on a streamed download, check the response, and decode
	 * the call log's body and the configured file extension.
	 *
	 * Opens OUR OWN read handle on the downloaded temp file: Guzzle has by now
	 * closed the handle it opened for the path, so nothing is shared.
	 *
	 * @param FetchedFile $file The file being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When the streamed download cannot be reopened or there is no response.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function readFetchedFile(FetchedFile $file): void {
		if ($file->useSink === true) {
			$file->sink = fopen($file->prepared['sinkPath'], 'r');
			if ($file->sink === false) {
				throw new Exception("Could not reopen streamed download for {$file->originalEndpoint}");
			}
		}

		// Status and headers are recorded even when the body streamed to the sink.
		if ($file->response === null) {
			throw new Exception("Failed to fetch file from endpoint: {$file->originalEndpoint}. No response received.");
		}

		if ($file->useSink === false) {
			$this->decodeFetchedBody(file: $file);
		}

		if (isset($file->config['fileExtension']) === true && empty($file->config['fileExtension']) === false) {
			$file->filename = $file->filename . $file->config['fileExtension'];
		}
	}//end readFetchedFile()

	/**
	 * Decode the call log's body (JSON, else strict base64) and read the content
	 * and file name from the configured paths into it.
	 *
	 * @param FetchedFile $file The file being saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function decodeFetchedBody(FetchedFile $file): void {
		$file->body = $file->response['body'];

		$decodedJson = json_decode(json: $file->body, associative: true);
		if ($decodedJson !== null
			&& $this->getHeaderValue(headers: ($file->response['headers'] ?? []), name: 'Content-Disposition') === null
		) {
			$file->body = $decodedJson;
		} else {
			$decodedBase64 = base64_decode(string: $file->body, strict: true);
			if ($decodedBase64 !== false) {
				$file->body = $decodedBase64;
			}
		}

		if (isset($file->config['contentPath']) === true && empty($file->config['contentPath']) === false) {
			$file->content = base64_decode((new Dot($file->body))->get($file->config['contentPath']));
		}

		if (isset($file->config['filenamePath']) === true && empty($file->config['filenamePath']) === false) {
			$file->filename = (new Dot($file->body))->get($file->config['filenamePath']);
		}
	}//end decodeFetchedBody()

	/**
	 * The download base64 encoded, for an action that does not write the file.
	 *
	 * @param FetchedFile $file The file being saved.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function encodeFetchedFile(FetchedFile $file): string {
		if ($file->useSink === true) {
			rewind($file->sink);
			return base64_encode((string)stream_get_contents($file->sink));
		}

		return base64_encode($file->body);
	}//end encodeFetchedFile()

	/**
	 * Settle the file name (from the response headers when none is set yet) and
	 * check the object id is a UUID.
	 *
	 * @param FetchedFile $file The file being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When no file name can be determined or the object id is not a UUID.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function resolveFetchedFileTarget(FetchedFile $file): void {
		if ($file->filename === null) {
			$file->filename = $this->getFilenameFromHeaders(response: $file->response, result: $file->callLog);
		}

		if ($file->filename === null) {
			throw new Exception("Could not write file from endpoint {$file->originalEndpoint}: no filename could be determined");
		}

		if (empty($file->objectId) === true
			|| preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $file->objectId) !== 1
		) {
			throw new Exception("Invalid object ID format: {$file->objectId}. Expected a valid UUID.");
		}
	}//end resolveFetchedFileTarget()

	/**
	 * Store the file on its object through OpenRegister's FileService, or add it
	 * by object id when the object cannot be found, and publish it when asked.
	 *
	 * @param FetchedFile $file The file being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When the file cannot be saved.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function storeFetchedFile(FetchedFile $file): void {
		$file->fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

		// The streamed resource on the binary path (rewound first), otherwise the decoded string body.
		if ($file->useSink === true) {
			rewind($file->sink);
		}

		try {
			$this->saveFetchedFileOnObject(file: $file);
		} catch (DoesNotExistException $exception) {
			$this->addFetchedFileById(file: $file);
		} catch (Exception $e) {
			throw new Exception("Failed to save file {$file->filename} for object {$file->objectId}: " . $e->getMessage());
		}
	}//end storeFetchedFile()

	/**
	 * Save the file on the object found by its id.
	 *
	 * @param FetchedFile $file The file being saved.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the object does not exist.
	 * @throws Exception When OpenRegister cannot find the object or store the file.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function saveFetchedFileOnObject(FetchedFile $file): void {
		$shouldShare = false;
		if (empty($file->tags) === false && isset($file->config['autoShare']) === true) {
			$shouldShare = $file->config['autoShare'];
		}

		$saveContent = $file->sink;
		if ($file->useSink === false) {
			$saveContent = ($file->content ?? $file->body);
		}

		$objectService      = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
		$file->objectEntity = $objectService->find(id: $file->objectId);
		$stored = $file->fileService->saveFile(
			objectEntity: $file->objectEntity,
			fileName: $file->filename,
			content: $saveContent,
			share: $shouldShare,
			tags: $file->tags
		);

		if ($stored !== null) {
			$this->publishFetchedFile(file: $file);
		}
	}//end saveFetchedFileOnObject()

	/**
	 * Add the file by object id, register and schema when the object was not found.
	 *
	 * @param FetchedFile $file The file being saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function addFetchedFileById(FetchedFile $file): void {
		$file->objectEntity = null;
		$addContent = $file->response['body'];
		if ($file->useSink === true) {
			// Rewind the sink so addFile reads the full streamed body from the start.
			rewind($file->sink);
			$addContent = $file->sink;
		}

		$stored = $file->fileService->addFile(
			objectEntity: $file->objectId,
			fileName: $file->filename,
			content: $addContent,
			share: ($file->config['autoShare'] ?? false),
			tags: $file->tags,
			_schema: ($file->config['schema'] ?? null),
			_register: ($file->config['register'] ?? null),
			registerId: $file->registerId
		);

		if ($stored !== null) {
			$this->publishFetchedFile(file: $file);
		}
	}//end addFetchedFileById()

	/**
	 * Publish a stored file when the object's published status asks for it.
	 *
	 * A failure is logged, not thrown: the file itself is stored.
	 *
	 * @param FetchedFile $file The file being saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	private function publishFetchedFile(FetchedFile $file): void {
		if ($this->shouldPublishFile(published: $file->published) === false) {
			return;
		}

		try {
			if ($file->objectEntity === null) {
				$objectService      = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
				$file->objectEntity = $objectService->find(id: $file->objectId);
			}

			$file->fileService->publishFile(object: $file->objectEntity, file: $file->filename);
		} catch (Exception $e) {
			$this->logger->warning("Failed to publish file {$file->filename} for object {$file->objectId}: " . $e->getMessage(), ['exception' => $e]);
		}
	}//end publishFetchedFile()

	/**
	 * Convert a JSON target payload to multipart/form-data when fileUpload is configured.
	 *
	 * Reads targetConfig['fileUpload'] and, if present, fetches the referenced file(s) from
	 * OpenRegister's FileService and replaces targetConfig['json'] with a Guzzle-compatible
	 * 'multipart' array so the request is sent as multipart/form-data to the target source.
	 *
	 * Use case example — zaaksysteem /case/prepare_file:
	 *   "fileUpload": { "fieldName": "upload" }
	 *   The object's originId (@self.id) is used to look up its files in OpenRegister; each
	 *   file is posted as a separate "upload" part, mirroring the HTML form / curl -F style.
	 *
	 * Supported keys under targetConfig['fileUpload']:
	 *   fieldName     (string) – Multipart field name for each file part; default "upload".
	 *   objectId      (string) – UUID of the OpenRegister object whose files to fetch;
	 *                            supports {{originId}} placeholder; defaults to contract originId.
	 *   fileName      (string) – Fetch only this specific file by name from the object folder.
	 *   allFiles      (bool)   – Send ALL files attached to the object; default false (first only).
	 *   fileId        (int)    – Nextcloud node ID; takes priority over objectId-based lookup.
	 *   includeObject (bool)   – Also send each object field as an individual multipart part
	 *                            before the file parts; default false.
	 *
	 * When no file can be resolved the method logs a warning and leaves targetConfig unchanged
	 * so the caller falls back to a normal JSON request.
	 *
	 * @param array $targetConfig The target request configuration, mutated in place.
	 * @param array $contract The synchronization contract providing the originId fallback.
	 *
	 * @return void
	 */
	private function applyFileUploadToTargetConfig(array &$targetConfig, array $contract): void {
		if (isset($targetConfig['fileUpload']) === false) {
			return;
		}

		$fileUploadConfig = $targetConfig['fileUpload'];
		$fieldName = $fileUploadConfig['fieldName'] ?? 'upload';

		/*
		 * @var \OCA\OpenRegister\Service\FileService $fileService
		 */

		$fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
		$filesToUpload = [];

		if (isset($fileUploadConfig['fileId']) === true) {
			// Direct lookup by Nextcloud node ID.
			$file = $fileService->getFileById((int)$fileUploadConfig['fileId']);
			if ($file !== null) {
				$filesToUpload[] = $file;
			}
		} else {
			// Resolve the OpenRegister object whose files we want.
			$contractOriginId = $contract['originId'] ?? null;
			$objectId = $fileUploadConfig['objectId'] ?? $contractOriginId;
			if ($objectId !== null) {
				$objectId = str_replace(
					['{{ originId }}', '{{originId}}'],
					(string)($contractOriginId ?? ''),
					(string)$objectId
				);
			}

			if (empty($objectId) === false) {
				$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
				$objectEntity = $objectService->find(id: $objectId);

				if (isset($fileUploadConfig['fileName']) === true) {
					// Single specific file by name.
					$file = $fileService->getFile($objectEntity, $fileUploadConfig['fileName']);
					if ($file !== null) {
						$filesToUpload[] = $file;
					}
				} elseif (empty($fileUploadConfig['allFiles']) === false && (bool)$fileUploadConfig['allFiles'] === true) {
					// All files attached to the object.
					$filesToUpload = $fileService->getFiles($objectEntity);
				} else {
					// Default: first file only.
					$files = $fileService->getFiles($objectEntity);
					if (empty($files) === false) {
						$filesToUpload[] = $files[0];
					}
				}
			}//end if
		}//end if

		if (empty($filesToUpload) === true) {
			$this->logger->warning(
				'fileUpload configured but no file resolved; falling back to JSON payload',
				[
					'synchronizationId' => $contract['synchronizationId'] ?? null,
					'originId' => $contract['originId'] ?? null,
				]
			);
			return;
		}

		$multipart = [];

		// Optionally include the object's own fields as individual parts before the file(s).
		if (empty($fileUploadConfig['includeObject']) === false && (bool)$fileUploadConfig['includeObject'] === true) {
			foreach ($targetConfig['json'] ?? [] as $key => $value) {
				if (is_array($value) === true) {
					$contents = json_encode($value);
				} else {
					$contents = (string)$value;
				}

				$multipart[] = [
					'name' => $key,
					'contents' => $contents,
				];
			}
		}

		foreach ($filesToUpload as $file) {
			$multipart[] = [
				'name' => $fieldName,
				'contents' => $file->getContent(),
				'filename' => $file->getName(),
				'headers' => ['Content-Type' => $file->getMimeType()],
			];
		}

		unset($targetConfig['json']);
		$targetConfig['multipart'] = $multipart;
		// Guzzle sets Content-Type (including the boundary) automatically for multipart; remove
		// any explicit override that would otherwise clobber the generated header.
		unset($targetConfig['headers']['Content-Type'], $targetConfig['headers']['content-type']);

	}//end applyFileUploadToTargetConfig()

	/**
	 * Determines a filename from the response headers.
	 *
	 * @param array $response The response array containing headers.
	 * @param ObjectEntity $result The CallLog ObjectEntity holding the request data.
	 *
	 * @return string|null The resolved filename, or null when none could be determined.
	 */
	private function getFilenameFromHeaders(array $response, ObjectEntity $result): ?string {
		$filename = null;
		// Get a filename from the response. First try to do this using the Content-Disposition header.
		// Header names are case-insensitive and HTTP/2 sends them lowercase, so
		// `Content-Disposition` and `content-disposition` must both reach the parser.
		$contentDisposition = $this->getHeaderValue(headers: ($response['headers'] ?? []), name: 'Content-Disposition');
		if ($contentDisposition !== null && str_contains($contentDisposition, 'filename') === true) {
			$filename = $this->parseContentDispositionFilename(headerValue: $contentDisposition);
		}

		if ($filename === null) {
			// Otherwise, parse the url and content type header. The CallLog is now
			// an OpenRegister ObjectEntity; the `request` body lives under
			// `getObject()['request']` instead of the legacy `getRequest()` getter.
			$resultRequest = ($result->getObject()['request'] ?? []);
			$parsedUrl = parse_url(($resultRequest['url'] ?? ''));
			$path = explode(separator:'/', string: ($parsedUrl['path'] ?? ''));
			$filename = end($path);

			// Same case-insensitive lookup as for Content-Disposition above.
			$contentType = $this->getHeaderValue(headers: ($response['headers'] ?? []), name: 'Content-Type');
			if (count(explode(separator: '.', string: $filename)) === 1 && $contentType !== null) {
				$explodedMimeType = explode(separator: '/', string: explode(separator: ';', string: $contentType)[0]);
				$filename = $filename . '.' . end($explodedMimeType);
			}
		}//end if

		return $filename;
	}//end getFilenameFromHeaders()

	/**
	 * Parse a Content-Disposition header value and extract the filename per RFC 6266.
	 *
	 * Supports both the traditional `filename="…"` parameter and the RFC 5987
	 * extended `filename*=charset''pct-encoded-value` form. When both are
	 * present the extended form wins per RFC 6266 §4.3, with the plain
	 * `filename` used as fallback when `filename*` is absent, empty, malformed,
	 * undecodable or carries an unsupported charset. An empty or whitespace-only
	 * plain `filename` counts as absent.
	 *
	 * WOO-552: replaces the naive `explode('=', $header)` that corrupted
	 * the filename as soon as xxllnc's Zaken API started emitting both
	 * parameters (release 2026-08-19, temporarily rolled back, feature-
	 * toggled re-rollout expected). Parameter-name matching is case-
	 * insensitive; the plain `filename` parameter's surrounding quotes are
	 * stripped.
	 *
	 * @param string $headerValue The raw Content-Disposition header value.
	 * @return string|null        The extracted filename, or null when
	 *                            neither `filename*` nor `filename` yielded
	 *                            a value.
	 */
	private function parseContentDispositionFilename(string $headerValue): ?string {
		// Split the header into parameter segments on `;`, respecting quoted
		// strings so a `;` inside `filename="..."` is treated as part of the
		// value (RFC 6266 §4 quoted-string grammar). Splitting on `;`
		// (instead of `=`) is what the naive pre-WOO-552 code got wrong:
		// any `=` inside a value (bv. the charset''value shape of
		// filename*) fooled the extractor.
		$segments = $this->splitHeaderParameters(headerValue: $headerValue);

		$filenameStar = null;
		$filenamePlain = null;

		foreach ($segments as $segment) {
			// Split into name/value on the FIRST `=` only — the value side
			// may legitimately contain further `=` characters (RFC 5987
			// extended values, base64-ish payloads).
			$eqPos = strpos($segment, '=');
			if ($eqPos === false) {
				continue;
			}
			$name = strtolower(trim(substr($segment, 0, $eqPos)));
			$value = trim(substr($segment, $eqPos + 1));

			if ($name === 'filename*') {
				// Only accept a decodable, non-empty value: an empty or undecodable
				// (duplicate) `filename*` must neither discard a value already found
				// nor win over a usable plain `filename`.
				$decoded = $this->decodeRfc5987ExtendedValue(value: $value);
				if ($decoded !== null && trim($decoded) !== '') {
					$filenameStar = $decoded;
				}
			} elseif ($name === 'filename') {
				// RFC 6266 allows quoted or unquoted `filename`. For a
				// quoted-string, strip the surrounding quotes and unescape
				// RFC 9110 §5.6.4 quoted-pairs (`\"` → `"`, `\\` → `\`).
				// An empty or whitespace-only value (`filename=""`) counts as
				// absent, so the caller's URL/MIME fallback runs instead.
				$unquoted = $this->unquoteHeaderValue(value: $value);
				if (trim($unquoted) !== '') {
					$filenamePlain = $unquoted;
				}
			}
		}

		// RFC 6266 §4.3: `filename*` wins when present and decodable.
		return $filenameStar ?? $filenamePlain;
	}//end parseContentDispositionFilename()

	/**
	 * Split a Content-Disposition header on `;` while preserving semicolons
	 * that appear INSIDE a quoted-string parameter value.
	 *
	 * RFC 6266 §4 uses the HTTP quoted-string grammar for `filename="..."`,
	 * so a `;` between quotes is part of the value, not a parameter
	 * separator. A naive `explode(';', $header)` corrupts values like
	 * `filename="foo;bar.pdf"` to just `foo`.
	 * Inside quotes, RFC 9110 §5.6.4 quoted-pairs (`\"`, `\\`) are
	 * skipped as a unit so an escaped quote never toggles the state.
	 *
	 * @param string $headerValue The raw Content-Disposition header value.
	 * @return array<int, string> Trimmed parameter segments, starting with
	 *                            the disposition-type (attachment/inline).
	 */
	private function splitHeaderParameters(string $headerValue): array {
		$segments = [];
		$current = '';
		$inQuotes = false;
		$length = strlen($headerValue);
		for ($i = 0; $i < $length; $i++) {
			$char = $headerValue[$i];
			if ($inQuotes === true && $char === '\\' && ($i + 1) < $length) {
				// RFC 9110 §5.6.4 quoted-pair: a backslash escapes the next
				// character, so an escaped `\"` is literal and must not
				// toggle the quote state. Keep both bytes, skip the escaped one.
				$current .= $char . $headerValue[$i + 1];
				$i++;
				continue;
			}
			if ($char === '"') {
				$inQuotes = ($inQuotes === false);
				$current .= $char;
				continue;
			}
			if ($char === ';' && $inQuotes === false) {
				$segments[] = trim($current);
				$current = '';
				continue;
			}
			$current .= $char;
		}
		if ($current !== '') {
			$segments[] = trim($current);
		}
		return $segments;
	}//end splitHeaderParameters()

	/**
	 * Unquote a Content-Disposition parameter value.
	 *
	 * A quoted-string (RFC 9110 §5.6.4) loses its surrounding double
	 * quotes and has its quoted-pairs unescaped: `\"` becomes `"` and
	 * `\\` becomes `\`. So `"rapport \"final\"; versie 2.pdf"` yields
	 * `rapport "final"; versie 2.pdf`. An unquoted token is returned
	 * unchanged. A value with an opening but no closing quote is
	 * handled leniently (opening quote dropped, rest unescaped) so a
	 * sloppy upstream header still produces a usable filename.
	 *
	 * @param string $value The raw (already trimmed) parameter value.
	 * @return string The unquoted, unescaped value.
	 */
	private function unquoteHeaderValue(string $value): string {
		if ($value === '' || $value[0] !== '"') {
			return $value;
		}
		$inner = substr($value, 1);
		if ($inner !== '' && substr($inner, -1) === '"') {
			$inner = substr($inner, 0, -1);
		}
		// Quoted-pair: backslash + any char collapses to that char.
		return preg_replace('/\\\\(.)/s', '$1', $inner) ?? $inner;
	}//end unquoteHeaderValue()

	/**
	 * Case-insensitive lookup of the first value of a response header.
	 *
	 * PSR-7 `getHeaders()` keeps the casing received on the wire and HTTP/2
	 * mandates lowercase field names, so `Content-Disposition` and
	 * `content-disposition` are the same header (RFC 9110 §5.1).
	 *
	 * @param mixed  $headers Header map as returned by the call service; anything
	 *                        that is not an array (persisted call logs) yields null.
	 * @param string $name    Header name, any casing.
	 * @return string|null The first header value, or null when the header is absent
	 *                     or carries no usable (scalar, non-false) value.
	 */
	private function getHeaderValue(mixed $headers, string $name): ?string {
		if (is_array($headers) === false) {
			return null;
		}
		foreach ($headers as $key => $values) {
			if (strcasecmp((string) $key, $name) !== 0) {
				continue;
			}
			if (is_array($values) === true) {
				$values = reset($values);
			}
			if ($values === false || is_scalar($values) === false) {
				// Empty list, null or nested array: no usable value under this key;
				// keep scanning for a differently-cased duplicate.
				continue;
			}
			return (string) $values;
		}
		return null;
	}//end getHeaderValue()

	/**
	 * Decode an RFC 5987 extended parameter value of shape
	 * `charset''pct-encoded`.
	 *
	 * Only UTF-8 is supported — any other charset (bv. ISO-8859-1)
	 * triggers a fallback by returning null, causing
	 * {@see parseContentDispositionFilename()} to use the plain `filename`
	 * parameter instead. Malformed values also return null.
	 *
	 * @param string $value The raw extended value,
	 *                      e.g. `UTF-8''na%C3%AFef.pdf`.
	 * @return string|null  The decoded UTF-8 string, or null when
	 *                      unsupported.
	 */
	private function decodeRfc5987ExtendedValue(string $value): ?string {
		// RFC 5987 ext-values are never quoted, but sloppy servers do emit
		// `filename*="UTF-8''x.pdf"`; unquoting is a no-op on a bare token.
		$value = $this->unquoteHeaderValue(value: $value);

		// RFC 5987 shape: charset ' language ' value-chars.
		$parts = explode("'", $value, 3);
		if (count($parts) !== 3) {
			$this->logger->info(
				'Ignoring malformed Content-Disposition filename* (expected charset\'\'value); falling back to plain filename',
				['extValueLength' => strlen($value)]
			);
			return null;
		}
		[$charset, $language, $encoded] = $parts;
		unset($language); // Language tag is accepted but not used.

		if (strcasecmp($charset, 'UTF-8') !== 0) {
			$this->logger->info(
				'Ignoring Content-Disposition filename* with unsupported charset; falling back to plain filename',
				['charset' => $charset]
			);
			return null;
		}
		// PHP's rawurldecode() implements the RFC 3986 §2.1 pct-decode but never fails:
		// an invalid pct-escape or truncated multibyte sequence yields invalid
		// UTF-8 and `%00` yields a NUL byte. Neither is a usable filename, so
		// enforce "decodable" here and fall back to the plain `filename`.
		$decoded = rawurldecode($encoded);
		if (mb_check_encoding(value: $decoded, encoding: 'UTF-8') === false || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
			$this->logger->info(
				'Ignoring Content-Disposition filename* that decoded to invalid UTF-8 or control characters; falling back to plain filename',
				['encodedLength' => strlen($encoded)]
			);
			return null;
		}

		return $decoded;
	}//end decodeRfc5987ExtendedValue()

	/**
	 * Extracts an endpoint from the given data and optionally retrieves a filename and tags.
	 *
	 * This function checks if a sub-object file path exists in the configuration and retrieves
	 * the relevant endpoint using dot notation. It also extracts filename and tag information
	 * if available.
	 *
	 * @param array $config The configuration array, which may include 'subObjectFilepath',
	 *                      'tags', 'useLabelsAsTags', and 'allowedLabels'.
	 * @param mixed $endpoint The data containing the endpoint, which can be a string or an array.
	 * @param string|null $filename A reference to the filename (if available) that will be updated.
	 * @param array|null $tags A reference to an array of tags (if available) that will be updated.
	 * @param string|null $objectId A reference to the object id (if available) the file is attached to.
	 * @param string|null $published A reference to the published status (if available) that will be updated.
	 * @param int|string|null $registerId A reference to the registerId (if available) that will be updated.
	 *
	 * @return mixed The extracted endpoint from the data, or null if not found.
	 */
	private function getFileContext(
		array $config,
		mixed $endpoint,
		?string &$filename = null,
		?array &$tags = [],
		?string &$objectId = null,
		?string &$published = null,
		int|string|null &$registerId = null,
	): mixed {
		$dataDot = new Dot($endpoint);
		if (empty($config['objectIdPath']) === false) {
			$objectId = $dataDot->get($config['objectIdPath']);
		}

		if (empty($config['subObjectFilepath']) === false) {
			$endpoint = $dataDot->get($config['subObjectFilepath']);
		}

		if (is_array($endpoint) === false) {
			return $endpoint;
		}

		$labels = $this->fileLabels(file: $endpoint);
		foreach ($this->labelsKeptAsTags(config: $config, labels: $labels) as $tagValue) {
			$tags[] = $tagValue;
		}

		if (empty($endpoint['filename']) === false) {
			$filename = $endpoint['filename'];
		}

		$published  = ($endpoint['published'] ?? $published);
		$registerId = ($endpoint['registerId'] ?? $registerId);

		return ($endpoint['endpoint'] ?? null);
	}//end getFileContext()

	/**
	 * The distinct non-empty string labels of a file, read from its `label`, `labels`, `tag` and `tags`.
	 *
	 * Each property may hold one label or a list of them.
	 *
	 * @param array $file The file's data.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/synchronization-files/spec.md
	 */
	private function fileLabels(array $file): array {
		$labels = [];
		foreach (['label', 'labels', 'tag', 'tags'] as $property) {
			if (isset($file[$property]) === false || empty($file[$property]) === true) {
				continue;
			}

			$value = $file[$property];
			if (is_array($value) === true) {
				$labels = array_merge(
					$labels,
					array_filter(
						$value,
						function ($item) {
							return empty($item) === false && is_string($item) === true;
						}
					)
				);
			} elseif (is_string($value) === true) {
				$labels[] = $value;
			}
		}

		return array_unique($labels);
	}//end fileLabels()

	/**
	 * The labels kept as tags.
	 *
	 * With `useLabelsAsTags: true` every label is kept; otherwise those in
	 * `allowedLabels` or the legacy `tags` list are. Without any of the three
	 * configured, every label is kept.
	 *
	 * @param array $config The file action configuration.
	 * @param array $labels The file's labels.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/synchronization-files/spec.md
	 */
	private function labelsKeptAsTags(array $config, array $labels): array {
		$allowed = $this->listOrNothing(value: ($config['allowedLabels'] ?? null));
		$legacy  = $this->listOrNothing(value: ($config['tags'] ?? null));

		if ((isset($config['useLabelsAsTags']) === true && $config['useLabelsAsTags'] === true)
			|| (empty($allowed) === true && empty($legacy) === true)
		) {
			return $labels;
		}

		return array_filter(
			$labels,
			fn ($label) => in_array($label, $allowed, true) === true || in_array($label, $legacy, true) === true
		);
	}//end labelsKeptAsTags()

	/**
	 * The value when it is an array, else an empty one.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/synchronization-files/spec.md
	 */
	private function listOrNothing(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end listOrNothing()

	/**
	 * Determines the type of a given array.
	 *
	 * This function identifies whether the given input is:
	 * - Not an array
	 * - An associative array (keys are not sequential numeric values)
	 * - A multidimensional array (contains nested arrays)
	 * - A simple indexed array (sequential numeric keys)
	 *
	 * @param mixed $array The input to be checked.
	 *
	 * @return string A string indicating the type of the array:
	 *                "Not array", "Associative array", "Multidimensional array", or "Indexed array".
	 */
	private function getArrayType(mixed $array): string {
		// Check if not array.
		if (is_array($array) === false) {
			return 'Not array';
		}

		if ($array === []) {
			return 'Empty array';
		}

		// Check for an associative array.
		if (array_keys($array) !== range(0, count($array) - 1)) {
			return 'Associative array';
		}

		// Check for a multidimensional array.
		if (count($array) !== count($array, COUNT_RECURSIVE)) {
			return 'Multidimensional array';
		}

		// Otherwise, it's an indexed array.
		return 'Indexed array';
	}//end getArrayType()

	/**
	 * Process a rule to fetch a file from an external source using fire-and-forget ReactPHP execution.
	 *
	 * This method initiates file fetching operations asynchronously without blocking the main execution flow.
	 * The actual file fetching happens in the background, allowing the synchronization to continue immediately.
	 *
	 * @param array $rule The OpenRegister rule payload array containing fetch_file configuration.
	 * @param array $data The data written to the object.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return array The resulting object data with placeholder values for file paths.
	 * @throws Exception If OpenRegister app is not available or configuration is missing.
	 *
	 * @psalm-return   array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 */
	private function processFetchFileRule(array $rule, array $data, ?string $objectId = null): array {
		// Check if OpenRegister app is available.
		$appManager = $this->containerInterface->get(IAppManager::class);
		if (($appManager instanceof IAppManager) === false
			|| $appManager->isEnabledForUser('openregister') === false
		) {
			throw new Exception('OpenRegister app is required for the fetch file rule and not installed');
		}

		// Validate rule configuration.
		$ruleConfiguration = ($rule['configuration'] ?? []);
		if (isset($ruleConfiguration['fetch_file']) === false) {
			throw new Exception('No configuration found for fetch_file');
		}

		$config = $ruleConfiguration['fetch_file'];

		$dataDot = new Dot($data);
		if (isset($config['filePath']) === true && $config['filePath'] !== '') {
			$endpoint = $dataDot->get($config['filePath']);
		} else {
			$endpoint = $config['endpoint'];
		}

		if ($objectId === null && isset($config['objectIdPath']) === true) {
			$objectId = $dataDot->get($config['objectIdPath']);
		}

		if (isset($config['originIdPath']) === true) {
			$config['originId'] = $dataDot->get($config['originIdPath']);
		}

		// If no endpoint is found, return data unchanged.
		if ($endpoint === null) {
			return $dataDot->jsonSerialize();
		}

		// Get source for file fetching.
		try {
			$source = $this->findSource(id: $config['source']);
		} catch (Exception $e) {
			// Log error but don't block synchronization.
			$this->logger->error('Failed to find source for fetch file rule: ' . $e->getMessage(), ['exception' => $e]);
			return $dataDot->jsonSerialize();
		}

		// SYNC OR TRULY ASYNC, per source. `sync` (the default) is exactly the
		// existing behaviour: fetch and save inline, inside the caller's
		// request. `async` defers the whole thing to a QueuedJob so the
		// synchronization returns as soon as its objects are written —
		// measured, files more than DOUBLE a sync's wall clock even with the
		// fetch window fully parallel (7,507 ms → 17,256 ms for 40 files),
		// because the saves are serial by design.
		//
		// ⚠️ In `async` the files are NOT present when the sync finishes.
		// Anything reasoning about a missing file — a deletion sweep above all
		// — must treat "not fetched yet" and "gone from the source" as
		// different facts.
		$fetchMode = (string)(($source['configuration']['fileFetchMode'] ?? 'sync'));
		if ($fetchMode === 'async') {
			$this->enqueueFileFetch(
				config: $config,
				endpoint: $endpoint,
				objectId: (string)$objectId,
				ruleId: (int)($rule['id'] ?? 0)
			);
		} else {
			// Start fire-and-forget file fetching based on endpoint type.
			$this->startAsyncFileFetching(source: $source, config: $config, endpoint: $endpoint, ruleId: (int)($rule['id'] ?? 0), objectId: $objectId);
		}

		// Return data immediately with placeholder values.
		if (isset($config['setPlaceholder']) === false || $config['setPlaceholder'] !== false) {
			$dataDot[$config['filePath']] = $this->generatePlaceholderValues(endpoint: $endpoint);
		}

		return $dataDot->jsonSerialize();
	}//end processFetchFileRule()

	/**
	 * Starts asynchronous file fetching operations using ReactPHP promises.
	 *
	 * This method creates fire-and-forget promises that handle file fetching in the background
	 * without blocking the main synchronization process.
	 *
	 * @param array $source The source to fetch files from.
	 * @param array $config The fetch_file rule configuration.
	 * @param mixed $endpoint The endpoint(s) to fetch files from.
	 * @param int $ruleId The ID of the rule for error logging.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return void
	 *
	 * @psalm-param array<string, mixed> $config
	 */
	/**
	 * Queue one object's file fetching for the cron worker.
	 *
	 * The SOURCE IS NOT PASSED, only `$config['source']` — a job argument is
	 * serialised into `oc_jobs`, and a source carries credentials (even as
	 * placeholders) plus enough configuration to make the row large. The job
	 * re-resolves it through the same {@see findSource()} the inline path uses,
	 * so a rotated credential is picked up at run time rather than frozen at
	 * queue time.
	 *
	 * @param array $config The fetch_file rule configuration.
	 * @param mixed $endpoint The endpoint(s) to fetch.
	 * @param string $objectId The object the files belong to.
	 * @param int $ruleId The rule id, for error attribution.
	 *
	 * @return void
	 */
	private function enqueueFileFetch(array $config, mixed $endpoint, string $objectId, int $ruleId): void {
		try {
			$this->containerInterface->get(\OCP\BackgroundJob\IJobList::class)->add(
				\OCA\Integriq\BackgroundJob\FetchFilesJob::class,
				[
					'config' => $config,
					'endpoint' => $endpoint,
					'objectId' => $objectId,
					'ruleId' => $ruleId,
				]
			);
		} catch (\Throwable $exception) {
			// Failing to QUEUE is not the same as a failed fetch and must be
			// loud: the files will never arrive, and unlike the inline path
			// there is no later error to notice.
			$this->logger->error(
				'[SynchronizationService] could not queue deferred file fetch for object '
				. $objectId . '; its files will NOT be fetched: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}//end try
	}//end enqueueFileFetch()

	/**
	 * Public entry point for {@see \OCA\Integriq\BackgroundJob\FetchFilesJob}.
	 *
	 * Re-resolves the source and runs the SAME fetch path the inline mode uses,
	 * so `sync` and `async` cannot drift into fetching differently — the only
	 * intended difference is when the work happens, never what it does.
	 *
	 * @param array $config The fetch_file rule configuration.
	 * @param mixed $endpoint The endpoint(s) to fetch.
	 * @param string $objectId The object the files belong to.
	 * @param int $ruleId The rule id, for error attribution.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-objects-multiple-files-shall-be-fetched-concurrently
	 */
	public function fetchFilesForObject(array $config, mixed $endpoint, string $objectId, int $ruleId = 0): void {
		$source = $this->findSource(id: $config['source']);

		$this->executeAsyncFileFetching(
			source: $source,
			config: $config,
			endpoint: $endpoint,
			ruleId: $ruleId,
			objectId: $objectId
		);
	}//end fetchFilesForObject()

	/**
	 * Kick off file fetching for an object without blocking the caller.
	 *
	 * "Async" here means FIRE-AND-FORGET WITH ERROR ISOLATION, not a real event
	 * loop — the work runs immediately and its failures are contained so one
	 * object's files cannot abort the synchronization. The body's own comment
	 * says so; this docblock exists partly so the name does not promise more
	 * than the implementation delivers.
	 *
	 * @param array $source The source to fetch from.
	 * @param array $config The fetch configuration.
	 * @param mixed $endpoint The endpoint to fetch.
	 * @param int $ruleId The rule that triggered the fetch.
	 * @param string|null $objectId The object the files belong to, when known.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	private function startAsyncFileFetching(array $source, array $config, mixed $endpoint, int $ruleId, ?string $objectId = null): void {
		// Execute file fetching immediately but with error isolation.
		// This provides "fire-and-forget" behavior without complex ReactPHP setup.
		$this->executeAsyncFileFetching(source: $source, config: $config, endpoint: $endpoint, ruleId: $ruleId, objectId: $objectId);
	}//end startAsyncFileFetching()

	/**
	 * Executes the actual file fetching operations asynchronously.
	 *
	 * This method handles different types of endpoints (single, associative array, multidimensional array, indexed array)
	 * and fetches files accordingly. All operations are wrapped in try-catch blocks to prevent errors from
	 * affecting the main synchronization process.
	 *
	 * @param array $source The source to fetch files from.
	 * @param array $config The fetch_file rule configuration.
	 * @param mixed $endpoint The endpoint(s) to fetch files from.
	 * @param int $ruleId The ID of the rule for error logging.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return void
	 *
	 * @psalm-param array<string, mixed> $config
	 */
	private function executeAsyncFileFetching(array $source, array $config, mixed $endpoint, int $ruleId, ?string $objectId = null): void {
		try {
			$filename = null;
			$tags = [];
			$published = null;
			$registerId = null;

			switch ($this->getArrayType(array: $endpoint)) {
				// Single file endpoint.
				case 'Not array':
					$this->fetchFileSafely(
						source: $source,
						endpoint: $endpoint,
						config: $config,
						objectId: $objectId
					);
					break;

					// Array of object that has file(s).
				case 'Associative array':
					$contextObjectId = null;
					// Separate variable to avoid overwriting the original.
					$actualEndpoint = $this->getFileContext(
						config: $config,
						endpoint: $endpoint,
						filename: $filename,
						tags: $tags,
						objectId: $contextObjectId,
						published: $published,
						registerId: $registerId
					);
					// Use context object ID if specified, otherwise fall back to the original object ID.
					$targetObjectId = $contextObjectId ?? $objectId;
					if ($actualEndpoint !== null) {
						$this->fetchFileSafely(
							source: $source,
							endpoint: $actualEndpoint,
							config: $config,
							objectId: $targetObjectId,
							filename: $filename,
							tags: $tags,
							published: $published,
							registerId: $registerId
						);
					}
					break;

					// Array is empty.
				case 'Empty array':
					// Array of object(s) that has file(s) - use cleanup logic.
				case 'Multidimensional array':
					// Array of just endpoints - use cleanup logic.
				case 'Indexed array':
					$this->processMultipleFilesWithCleanup(
						source: $source,
						config: $config,
						endpoints: $endpoint,
						objectId: $objectId
					);
					break;
			}//end switch
		} catch (Exception $e) {
			// Log error but don't throw - this is fire-and-forget.
			$this->logger->error("Async file fetching failed for rule {$ruleId}: " . $e->getMessage(), ['exception' => $e]);
		}//end try
	}//end executeAsyncFileFetching()

	/**
	 * Fetches a single file with comprehensive error handling.
	 *
	 * This method wraps the existing fetchFile method with error isolation to enable
	 * fire-and-forget execution. Errors are caught and logged without affecting the main process.
	 *
	 * @param array $source The source to fetch the file from.
	 * @param string $endpoint The endpoint for the file.
	 * @param array $config The configuration of the action.
	 * @param string $objectId The UUID of the object the file belongs to.
	 * @param string|null $filename Optional filename to assign to the file.
	 * @param array $tags Optional tags to assign to the file.
	 * @param string|null $published Optional published status to determine if file should be published.
	 * @param int|string|null $registerId Optional published status to determine if file should be published.
	 *
	 * @return void
	 *
	 * @psalm-param array<string, mixed> $config
	 * @psalm-param array<string> $tags
	 */
	private function fetchFileSafely(
		array $source,
		string $endpoint,
		array $config,
		string $objectId,
		?string $filename = null,
		array $tags = [],
		int|string|null $published = null,
		int|string|null $registerId = null,
	): void {
		try {
			// Execute the file fetching operation.
			$this->fetchFile(
				source: $source,
				endpoint: $endpoint,
				config: $config,
				objectId: $objectId,
				tags: $tags,
				filename: $filename,
				published: $published,
				registerId: $registerId
			);
			$this->fileFetchSucceeded++;
		} catch (Exception $e) {
			// COUNTED, not just logged. Swallowing here is deliberate — one
			// file's failure must not abort its siblings or the object — but it
			// is why the rule could report "Successfully applied" having written
			// ZERO files: nothing downstream could tell 40 files from none.
			$this->fileFetchFailed++;

			// Log error with detailed information but don't throw.
			$this->logger->error("File fetch failed for endpoint {$endpoint}, objectId {$objectId}: " . $e->getMessage(), ['exception' => $e]);
		}
	}//end fetchFileSafely()

	/**
	 * Reset and read the per-rule file tally.
	 *
	 * @return array{succeeded: int, failed: int} The tally since the last reset.
	 */
	private function takeFileFetchTally(): array {
		$tally = [
			'succeeded' => $this->fileFetchSucceeded,
			'failed' => $this->fileFetchFailed,
		];

		$this->fileFetchSucceeded = 0;
		$this->fileFetchFailed = 0;

		return $tally;
	}//end takeFileFetchTally()

	/**
	 * Generates placeholder values for file paths based on endpoint type.
	 *
	 * This method creates appropriate placeholder values that match the expected structure
	 * of the file paths, allowing the synchronization to continue with meaningful placeholders
	 * while files are being fetched asynchronously.
	 *
	 * @param mixed $endpoint The endpoint(s) to generate placeholders for.
	 *
	 * @return mixed Placeholder values matching the endpoint structure.
	 */
	private function generatePlaceholderValues(mixed $endpoint): mixed {
		switch ($this->getArrayType(array: $endpoint)) {
			case 'Not array':
				return 'file://fetching-async';
			case 'Associative array':
				return 'file://fetching-async';
			case 'Multidimensional array':
				return array_fill(0, count($endpoint), 'file://fetching-async');
			case 'Indexed array':
				return array_fill(0, count($endpoint), 'file://fetching-async');
			default:
				return 'file://fetching-async';
		}
	}//end generatePlaceholderValues()

	/**
	 * Process a rule to write files.
	 *
	 * @param array $rule The OpenRegister rule payload array.
	 * @param array $data The data to write.
	 * @param string $objectId The object to write the data to.
	 * @param int $registerId The register the object is in.
	 * @param int $schemaId The schema the object is in.
	 *
	 * @return array
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	private function processWriteFileRule(array $rule, array $data, string $objectId, int $registerId, int $schemaId): array {
		$ruleConfiguration = ($rule['configuration'] ?? []);
		if (isset($ruleConfiguration['write_file']) === false) {
			throw new Exception('No configuration found for write_file');
		}

		$config = $ruleConfiguration['write_file'];
		$dataDot = new Dot($data);
		$files = $dataDot[$config['filePath']];
		if (isset($files) === false || empty($files) === true) {
			return $dataDot->jsonSerialize();
		}

		// Get the object entity and file service.
		$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
		$objectEntity = $objectService->find(id: $objectId);
		$fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

		// Check if associative array (multiple files with metadata).
		if (is_array($files) === true && isset($files[0]) === true && array_keys($files[0]) !== range(0, count($files[0]) - 1)) {
			$result = [];
			foreach ($files as $key => $value) {
				$content = '';
				$fileName = '';
				$tags = [];

				// Extract file data.
				if (is_array($value) === true) {
					$content = $value['content'];
					$fileName = $value['filename'] ?? "file_$key";

					// Handle tags from config and value labels.
					if (isset($value['label']) === true && isset($config['tags']) === true
						&& in_array(needle: $value['label'], haystack: $config['tags']) === true
					) {
						$tags = [$value['label']];
					}
				} else {
					$content = $value;
					$fileName = "file_$key";
				}

				// Merge with configured tags.
				$allTags = array_unique(array_merge(($config['tags'] ?? []), $tags));

				// Determine if we should share the file - only if there are user-defined tags.
				$shouldShare = (empty($allTags) === false);

				try {
					// Use the new saveFile method.
					$file = $fileService->saveFile(
						objectEntity: $objectEntity,
						fileName: $fileName,
						content: $content,
						share: $shouldShare,
						tags: $allTags
					);

					$result[$key] = $file->getPath();
				} catch (Exception $exception) {
					$this->logger->error("Failed to save file $fileName: " . $exception->getMessage(), ['exception' => $exception]);
					$result[$key] = null;
				}
			}//end foreach

			$dataDot[$config['filePath']] = $result;
		} else {
			// Single file case.
			$content = $files;
			$fileName = $dataDot[$config['fileNamePath']] ?? 'default_file';

			// Get configured tags.
			$tags = ($config['tags'] ?? []);

			// Determine if we should share the file - only if there are user-defined tags.
			$shouldShare = (empty($tags) === false);

			try {
				// Use the new saveFile method.
				$file = $fileService->saveFile(
					objectEntity: $objectEntity,
					fileName: $fileName,
					content: $content,
					share: $shouldShare,
					tags: $tags
				);

				$dataDot[$config['filePath']] = $file->getPath();
			} catch (Exception $exception) {
				$this->logger->error("Failed to save file $fileName: " . $exception->getMessage(), ['exception' => $exception]);
				$dataDot[$config['filePath']] = null;
			}
		}//end if

		return $dataDot->jsonSerialize();
	}//end processWriteFileRule()

	/**
	 * Processes an error rule.
	 *
	 * @param array $rule The OpenRegister rule payload array containing error details.
	 *
	 * @return JSONResponse Response containing error details and HTTP status code.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function processErrorRule(array $rule): JSONResponse {
		$config = ($rule['configuration'] ?? []);
		return new JSONResponse(
			[
				'error' => $config['error']['name'],
				'message' => $config['error']['message'],
			],
			$config['error']['code']
		);
	}//end processErrorRule()

	/**
	 * Processes a mapping rule.
	 *
	 * @param array $rule The OpenRegister rule payload array containing mapping details.
	 * @param array $data The data to be processed through the mapping rule.
	 *
	 * @return array The processed data after applying the mapping rule.
	 *
	 * @throws DoesNotExistException When the mapping configuration does not exist.
	 * @throws MultipleObjectsReturnedException When multiple mapping objects are returned unexpectedly.
	 * @throws LoaderError When there is an error loading the mapping.
	 * @throws SyntaxError When there is a syntax error in the mapping configuration.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function processMappingRule(array $rule, array $data): array {
		$config = ($rule['configuration'] ?? []);
		$mapping = $this->mappingService->getMapping($config['mapping']);

		return $this->processMapping(mapping: $mapping, data: $data);
	}//end processMappingRule()

	/**
	 * Executes mapping on data from endpoint flow.
	 *
	 * @param OrMapping|ObjectEntity|array $mapping The mapping entity.
	 * @param array $data The data to be mapped.
	 *
	 * @return array The mapped data.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function processMapping(OrMapping|ObjectEntity|array $mapping, array $data): array {
		return $this->mappingService->executeMapping($mapping, $data);
	}//end processMapping()

	/**
	 * Processes a synchronization rule.
	 *
	 * @param array $rule The OpenRegister rule payload array (reserved for future synchronization logic).
	 * @param array $data The data to be synchronized.
	 *
	 * @return array The synchronized data.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function processSyncRule(array $rule, array $data): array {
		$target = trim(
			(string)(($rule['configuration']['synchronization']['synchronization'] ?? null) ?? ($rule['configuration']['synchronization'] ?? ''))
		);

		// A rule type that is offered in the editor and silently does nothing is
		// worse than one that is absent: the author sees their rule listed, in
		// order, apparently applied. Say so instead.
		if ($target === '' || is_array($target) === true) {
			throw new Exception(
				'A "synchronization" rule must name the synchronization to run in '
				. 'configuration.synchronization.synchronization.'
			);
		}

		// Guard the obvious loop. A synchronization whose own rule runs itself
		// recurses until the process dies, and the same holds for any chain that
		// comes back round — so every synchronization already entered on this
		// call stack is off limits, not merely the immediate one.
		if (in_array($target, self::$syncChainStack, true) === true) {
			throw new Exception(
				'A "synchronization" rule would re-enter synchronization "' . $target
				. '", which is already running. Chain synchronizations with a flow '
				. 'instead: an OpenRegister flow expresses ordering explicitly and '
				. 'the engine bounds the recursion.'
			);
		}

		self::$syncChainStack[] = $target;

		try {
			$this->synchronize(
				synchronization: $this->getSynchronization(id: $target),
				isTest: false,
				force: filter_var((($rule['configuration']['synchronization']['force'] ?? false)), FILTER_VALIDATE_BOOLEAN)
			);
		} finally {
			array_pop(self::$syncChainStack);
		}

		// A sync rule is a side effect, not a transform: the record travelling
		// through the pipeline is unchanged by having triggered another sync.
		return $data;
	}//end processSyncRule()

	/**
	 * Checks if rule conditions are met.
	 *
	 * @param array $rule The OpenRegister rule payload array containing conditions to be checked.
	 * @param array $data The input data against which the conditions are evaluated.
	 *
	 * @return bool True when the conditions are met, false otherwise.
	 *
	 * @throws Exception When the JsonLogic evaluator throws.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function checkRuleConditions(array $rule, array $data): bool {
		$conditions = ($rule['conditions'] ?? []);
		if (empty($conditions) === true) {
			return true;
		}

		return JsonLogic::apply($conditions, $data) === true;
	}//end checkRuleConditions()

	/**
	 * Replaces strings in array keys, helpful for characters like . in array keys.
	 *
	 * @param array $array The array to encode the array keys for.
	 * @param string $toReplace The character to encode.
	 * @param string $replacement The encoded character.
	 *
	 * @return array The array with encoded array keys
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array {
		$result = [];
		foreach ($array as $key => $value) {
			$newKey = str_replace($toReplace, $replacement, $key);

			if (is_array($value) === true && $value !== []) {
				$result[$newKey] = $this->encodeArrayKeys(
					array: $value,
					toReplace: $toReplace,
					replacement: $replacement
				);
				continue;
			}

			$result[$newKey] = $value;
		}

		return $result;
	}//end encodeArrayKeys()

	/**
	 * Convert SimpleXMLElement to array while preserving namespaced attributes.
	 *
	 * @param \SimpleXMLElement $xml The XML element to convert.
	 *
	 * @return array The array representation with preserved namespaced attributes.
	 */
	private function xmlToArray(\SimpleXMLElement $xml): array {
		$result = [];

		// Handle attributes - this preserves namespaced attributes with colons.
		$attributes = $xml->attributes();
		if (count($attributes) > 0) {
			$result['@attributes'] = [];
			foreach ($attributes as $attrName => $attrValue) {
				$result['@attributes'][(string)$attrName] = (string)$attrValue;
			}
		}

		// Handle namespaced attributes.
		$namespaces = $xml->getNamespaces(true);
		foreach ($namespaces as $prefix => $namespace) {
			$nsAttributes = $xml->attributes($namespace);
			if (count($nsAttributes) > 0) {
				if (isset($result['@attributes']) === false) {
					$result['@attributes'] = [];
				}

				foreach ($nsAttributes as $attrName => $attrValue) {
					// Preserve the namespace prefix in the attribute name (with colon).
					if (empty($prefix) === false) {
						$nsAttrName = "$prefix:$attrName";
					} else {
						$nsAttrName = $attrName;
					}

					$result['@attributes'][$nsAttrName] = (string)$attrValue;
				}
			}
		}

		// Handle child elements.
		foreach ($xml->children() as $childName => $child) {
			$childArray = $this->xmlToArray(xml: $child);

			if (isset($result[$childName]) === true) {
				// If this child name already exists, convert to or add to array.
				if (is_array($result[$childName]) === false || isset($result[$childName][0]) === false) {
					$result[$childName] = [$result[$childName]];
				}

				$result[$childName][] = $childArray;
			} else {
				$result[$childName] = $childArray;
			}
		}

		// Handle text content.
		$text = trim((string)$xml);
		if (count($result) === 0 && $text !== '') {
			return ['#text' => $text];
		} elseif ($text !== '') {
			$result['#text'] = $text;
		}

		return $result;
	}//end xmlToArray()

	/**
	 * Process a single object during synchronization.
	 *
	 * @param array $synchronization The synchronization being processed.
	 * @param array $object The object to synchronize.
	 * @param array $result The current result tracking data.
	 * @param bool $isTest Whether this is a test run.
	 * @param bool $force Whether to force synchronization regardless of changes.
	 * @param SynchronizationRunLog $log The synchronization log.
	 * @param FlowToken $flowToken The flow token shared across the synchronization run.
	 * @param string|null $mutationType The type of object mutation.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, when this item is processed
	 *                                          from within a traced execution (execution-trace
	 *                                          REQ-001/REQ-002).
	 * @param array|null $contractIndex Contracts pre-read for the whole page, keyed by origin id.
	 *                                  When supplied, a missing key is a MISS rather than a reason
	 *                                  to fall back to a per-item query.
	 *
	 * @return array Contains updated result data and the targetId ['result' => array, 'targetId' => string|null].
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) 10 parameters, threshold 10.
	 *   This is the engine's per-object inner loop and every parameter is state the
	 *   loop carries rather than re-derives per object — bundling them into a context
	 *   object is the obvious cleanup, but it is a signature change across the engine
	 *   and its tests, so it belongs in its own change where it can be verified.
	 */
	private function processSynchronizationObject(
		array $synchronization,
		array $object,
		array $result,
		bool $isTest,
		bool $force,
		SynchronizationRunLog $log,
		FlowToken &$flowToken,
		?string $mutationType = null,
		?ExecutionTraceContext $trace = null,
		?array $contractIndex = null,
	): array {
		// Execution-trace REQ-002: one ordered `synchronization` step per item processed.
		$item = new ItemRun();
		$item->stepStart = microtime(true);
		$item->synchronization = $synchronization;
		$item->flowToken = $flowToken;
		$item->trace = $trace;
		$item->contractIndex = $contractIndex;
		$item->result = $result;
		$item->sourceConfig = $this->callService->applyConfigDot(($synchronization['sourceConfig'] ?? []));

		$rejection = $this->screenItem(item: $item, object: $object);
		if ($rejection !== null) {
			$item->result['objects'][$rejection]++;
			$this->addItemStep(item: $item, step: ['status' => 'skipped', 'input' => $object]);

			return ['result' => $item->result, 'targetId' => null];
		}

		$originId = $this->getOriginId(synchronization: $synchronization, object: $object);

		// REQ-MSV-003: the source object is checked against its message schema before it is mapped.
		$sourceFinding = $this->inspectMessage(
			config: $item->sourceConfig, side: SynchronizationMessageGate::SOURCE, message: $object, originId: (string)$originId
		);
		if ($sourceFinding !== null) {
			$item->result['validation'][] = $sourceFinding;
		}

		$contract = $this->findItemContract(item: $item, originId: $originId);
		$item->isNewContract = is_array($contract) === false;
		if ($item->isNewContract === true) {
			$contract = ['synchronizationId' => ($synchronization['id'] ?? null), 'originId' => $originId];
		}

		$contractResult = $this->synchronizeContract(
			synchronizationContract: $contract,
			synchronization: $synchronization,
			flowToken: $flowToken,
			object: $object,
			isTest: $isTest,
			force: $force,
			log: match ($item->isNewContract) { true => null, false => $log },
			mutationType: $mutationType,
			trace: $trace
		);

		$contract = $contractResult['contract'];
		$this->recordItemResult(item: $item, contractResult: $contractResult);
		$status = match ($item->resultAction) { null => 'skipped', default => 'success' };
		$this->addItemStep(item: $item, step: ['status' => $status, 'input' => $object, 'output' => $contract]);

		return ['result' => $item->result, 'targetId' => ($contract['targetId'] ?? null)];
	}//end processSynchronizationObject()

	/**
	 * Screen a source item before its contract is looked up.
	 *
	 * An item that is not an array is invalid. Extra data configured to be fetched
	 * before the conditions is fetched into the item, and an item that fails the
	 * synchronization's conditions is skipped.
	 *
	 * @param ItemRun $item   The item run.
	 * @param mixed   $object The source item; extended in place with fetched extra data.
	 *
	 * @return string|null The result counter to raise ('invalid' or 'skipped'), or null when the item goes on.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function screenItem(ItemRun $item, mixed &$object): ?string {
		// We can only deal with arrays (based on the source empty values or string might be returned).
		if (is_array($object) === false) {
			$this->warnNonArrayItem(synchronization: $item->synchronization, object: $object);
			return 'invalid';
		}

		// Optional to fetch extra data now instead of later in ->synchronizeContract.
		$sourceConfig = $item->sourceConfig;
		if (isset($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION]) === true
			&& ($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] === true
			|| $sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] === 'true')
		) {
			$object = $this->fetchMultipleExtraData(synchronization: $item->synchronization, sourceConfig: $sourceConfig, object: $object);
		}

		if ($this->itemMeetsConditions(item: $item, object: $object) === false) {
			return 'skipped';
		}

		return null;
	}//end screenItem()

	/**
	 * Log a source item that is not an array, so the run log's `invalid` count can be
	 * traced back to malformed source data rather than to a target-side rejection.
	 *
	 * @param array $synchronization The synchronization.
	 * @param mixed $object          The source item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function warnNonArrayItem(array $synchronization, mixed $object): void {
		// Only a scalar can be previewed; get_debug_type() already names anything else.
		$scalarPreview = '';
		if (is_scalar($object) === true) {
			$scalarPreview = (string)$object;
		}

		$this->logger->warning(
			'Synchronization item counted as invalid: source item is not an array',
			[
				'synchronization' => ($synchronization['name'] ?? ($synchronization['uuid'] ?? null)),
				'receivedType' => get_debug_type($object),
				'preview' => mb_substr($scalarPreview, 0, 200),
			]
		);
	}//end warnNonArrayItem()

	/**
	 * Add the `synchronization` trace step of one item, with its input and output redacted (REQ-003).
	 *
	 * @param ItemRun $item The item run; nothing is added when it is not traced.
	 * @param array   $step `status`, and the optional raw `input` and `output`;
	 *                      an input or output that is not an array is traced as empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function addItemStep(ItemRun $item, array $step): void {
		if ($item->trace === null) {
			return;
		}

		foreach (['input', 'output'] as $key) {
			if (array_key_exists($key, $step) === false) {
				continue;
			}

			$data = [];
			if (is_array($step[$key]) === true) {
				$data = $step[$key];
			}

			$step[$key] = (new SensitiveFieldRegistry())->redactArray(data: $data);
		}

		$item->trace->addStep(
			...array_merge(
				[
					'type' => 'synchronization',
					'name' => ($item->synchronization['name'] ?? ($item->synchronization['uuid'] ?? 'synchronization')),
					'timing' => null,
				],
				$step,
				['startedAtMicrotime' => $item->stepStart, 'finishedAtMicrotime' => microtime(true)]
			)
		);
	}//end addItemStep()

	/**
	 * Whether a source item meets the synchronization's conditions.
	 *
	 * The conditions object is only built when there are conditions: it encodes the whole
	 * item and serialises the flow token, which is per-record work a synchronization
	 * without conditions should not pay for.
	 *
	 * @param ItemRun $item   The item run.
	 * @param array   $object The source item.
	 *
	 * @return bool True when there are no conditions or they hold.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function itemMeetsConditions(ItemRun $item, array $object): bool {
		$conditions = ($item->synchronization['conditions'] ?? []);
		if ($conditions === []) {
			return true;
		}

		$conditionsObject = $this->encodeArrayKeys(array: $object, toReplace: '.', replacement: '&#46;');
		$conditionsObject['flowToken'] = $item->flowToken->__serialize();

		return self::conditionsHold(conditions: $conditions, data: $conditionsObject);
	}//end itemMeetsConditions()

	/**
	 * Find the contract of a source item, from the page's contract index when there is one.
	 *
	 * A key missing from the index is a miss, not a reason to query: the bulk read covered
	 * every origin id on the page. When a contract is found, duplicate contracts for the
	 * same origin are reported from the matches already read (REQ-013).
	 *
	 * @param ItemRun $item     The item run.
	 * @param mixed   $originId The origin id of the item.
	 *
	 * @return mixed The first matching contract, or null.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function findItemContract(ItemRun $item, mixed $originId): mixed {
		$synchronizationId = (string)($item->synchronization['id'] ?? '');
		$contractMatches = null;
		if ($item->contractIndex !== null) {
			$contractMatches = ($item->contractIndex[$originId] ?? []);
			$contract = ($contractMatches[0] ?? null);
		} else {
			$contract = $this->findContractBySyncAndOrigin(
				synchronizationId: $synchronizationId,
				originId: $originId,
				justByOriginId: (isset($item->sourceConfig['findContractByOriginIdOnly']) === true
					&& filter_var($item->sourceConfig['findContractByOriginIdOnly'], FILTER_VALIDATE_BOOLEAN) === true),
				allMatches: $contractMatches
			);
		}

		if (is_array($contract) === true) {
			$this->detectDuplicateContracts(synchronizationId: $synchronizationId, originId: $originId, contracts: $contractMatches);
		}

		return $contract;
	}//end findItemContract()

	/**
	 * Record what synchronizeContract() did with one item in the run result.
	 *
	 * The contract and log uuids are listed, and the result action is counted. For a
	 * contract that did not exist yet, an `update` counts as created.
	 *
	 * @param ItemRun $item           The item run; its result and result action are set.
	 * @param mixed   $contractResult What synchronizeContract() returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function recordItemResult(ItemRun $item, mixed $contractResult): void {
		$item->result['contracts'][] = ($contractResult['contract']['uuid'] ?? null);
		$item->result['logs'][] = ($contractResult['log']['uuid'] ?? null);

		$item->resultAction = ($contractResult['resultAction'] ?? null);
		if ($item->isNewContract === true && $item->resultAction === 'update') {
			$item->resultAction = 'create';
		}

		$this->tallyItemResult(item: $item, contractResult: $contractResult);
	}//end recordItemResult()

	/**
	 * Count one item's result action in the run result.
	 *
	 * An unrecognised or null action counts as invalid and is logged with the shape of the
	 * contract result, so an operator can tell a rejected object from an unknown action.
	 *
	 * @param ItemRun $item           The item run, with its result action.
	 * @param mixed   $contractResult What synchronizeContract() returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 */
	private function tallyItemResult(ItemRun $item, mixed $contractResult): void {
		switch ($item->resultAction) {
			case 'update':
				$item->result['objects']['updated']++;
				return;
			case 'create':
				$item->result['objects']['created']++;
				return;
			case 'delete':
				$item->result['objects']['deleted']++;
				return;
			case 'skip':
				$item->result['objects']['skipped']++;
				return;
		}

		$item->result['objects']['invalid']++;
		$this->logger->warning(
			'Synchronization item counted as invalid: unrecognised resultAction',
			[
				'synchronization' => ($item->synchronization['name'] ?? ($item->synchronization['uuid'] ?? null)),
				'resultAction' => $item->resultAction,
				'contractResultKeys' => array_keys(($contractResult ?? [])),
				'contractError' => ($contractResult['error'] ?? $contractResult['message'] ?? null),
				'contractUuid' => ($contractResult['contract']['uuid'] ?? null),
				'targetId' => ($contractResult['contract']['targetId'] ?? null),
				'originId' => ($contractResult['contract']['originId'] ?? null),
			]
		);
	}//end tallyItemResult()

	/**
	 * Fetch a synchronization OpenRegister object by id or other characteristics.
	 *
	 * Post-cutover, synchronizations are OpenRegister objects (register
	 * `openconnector`, schema `synchronization`); this resolves them without any
	 * caller needing to touch the OpenRegister object service directly.
	 *
	 * @param string|int|null $id The OpenRegister id (UUID) of the synchronization.
	 * @param array $filters Other filters to find the synchronization by.
	 *
	 * @return ObjectEntity The resulting synchronization object.
	 *
	 * @throws DoesNotExistException Thrown if the synchronization does not exist.
	 */
	public function getSynchronization(null|string|int $id = null, array $filters = []) :ObjectEntity {
		if ($id !== null) {
			// OpenRegister ids are UUID strings; pass through unchanged.
			return $this->findSynchronizationObject(id: (string)$id);
		}

		$synchronizations = $this->findAllSynchronizationObjects(filters: $filters);

		if (count($synchronizations) === 0) {
			throw new DoesNotExistException('The synchronization you are looking for does not exist');
		}

		return $synchronizations[0];
	}//end getSynchronization()

	/**
	 * Calculates the median value from an array of numbers.
	 *
	 * This method sorts the input array and returns the middle value for odd-length arrays
	 * or the average of the two middle values for even-length arrays.
	 *
	 * @param array $numbers Array of numeric values to calculate median from.
	 *
	 * @return float The median value, or 0 if the array is empty.
	 *
	 * @psalm-param   array<float|int> $numbers
	 * @phpstan-param array<float|int> $numbers
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function calculateMedian(array $numbers): float {
		if (empty($numbers) === true) {
			return 0.0;
		}

		// Sort the array to find the median.
		sort($numbers);
		$count = count($numbers);

		// If odd number of elements, return the middle one.
		if (($count % 2) === 1) {
			return (float)$numbers[intval($count / 2)];
		}

		// If even number of elements, return average of two middle values.
		$middle1 = $numbers[(intval($count / 2) - 1)];
		$middle2 = $numbers[intval($count / 2)];
		return ($middle1 + $middle2) / 2.0;
	}//end calculateMedian()

	/**
	 * Identifies the slowest stage from timing data.
	 *
	 * This method analyzes the timing stages and returns information about
	 * the stage that took the longest to execute.
	 *
	 * @param array $stages Array of timing stage data with duration_ms values.
	 *
	 * @return array Information about the slowest stage including name, duration, and description.
	 *
	 * @psalm-param    array<string, array{duration_ms: float, description: string}> $stages
	 * @phpstan-param  array<string, array{duration_ms: float, description: string}> $stages
	 * @psalm-return   array{name: string, duration_ms: float, description: string}
	 * @phpstan-return array{name: string, duration_ms: float, description: string}
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function getSlowestInternship(array $stages): array {
		if (empty($stages) === true) {
			return [
				'name' => 'none',
				'duration_ms' => 0.0,
				'description' => 'No stages recorded',
			];
		}

		$slowestInternship = '';
		$slowestDuration = 0.0;
		$slowestDescription = '';

		foreach ($stages as $stageName => $stageData) {
			if ($stageData['duration_ms'] > $slowestDuration) {
				$slowestDuration = $stageData['duration_ms'];
				$slowestInternship = $stageName;
				$slowestDescription = $stageData['description'];
			}
		}

		return [
			'name' => $slowestInternship,
			'duration_ms' => $slowestDuration,
			'description' => $slowestDescription,
		];
	}//end getSlowestStage()

	/**
	 * Calculates the efficiency ratio of the synchronization process.
	 *
	 * This method determines how much time was spent on actual object processing
	 * versus overhead operations like fetching, configuration, and cleanup.
	 * A higher ratio indicates more efficient processing.
	 *
	 * @param array $stages Array of timing stage data with duration_ms values.
	 *
	 * @return float Efficiency ratio between 0 and 1, where 1 means 100% of time spent on processing.
	 *
	 * @psalm-param   array<string, array{duration_ms: float}> $stages
	 * @phpstan-param array<string, array{duration_ms: float}> $stages
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function calculateEfficiencyRatio(array $stages): float {
		if (empty($stages) === true) {
			return 0.0;
		}

		$totalDuration = 0.0;
		$processingDuration = 0.0;

		foreach ($stages as $stageName => $stageData) {
			$totalDuration += $stageData['duration_ms'];

			// Consider 'process_objects' as the core processing stage.
			if ($stageName === 'process_objects') {
				$processingDuration = $stageData['duration_ms'];
			}
		}

		if ($totalDuration === 0.0) {
			return 0.0;
		}

		return round($processingDuration / $totalDuration, 4);
	}//end calculateEfficiencyRatio()

	/**
	 * Cleans up files that are currently attached to an object but not present in the new file set.
	 *
	 * This method compares the currently attached files to an object with the new set of files
	 * being processed and removes any files that are no longer needed.
	 *
	 * @param string $objectId The UUID of the object to clean up files for.
	 * @param array $newFileNames Array of filenames that should remain attached to the object.
	 *
	 * @return int The number of files that were deleted.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 */
	private function cleanupOrphanedFiles(string $objectId, array $newFileNames): int {
		$deletedCount = 0;

		try {
			// Get the object entity.
			$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
			try {
				$objectEntity = $objectService->find(id: $objectId);
			} catch (DoesNotExistException $e) {
				// It is possible we are trying to delete files for an object id where the object
				// has not been persisted yet (for example a zgw informatieobject can have a
				// beforehand generated uuid).
				return 0;
			}

			// Get the file service.
			$fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

			// Get all currently attached files for this object.
			$currentFiles = $fileService->getFiles($objectEntity);

			// Check each current file to see if it should be kept.
			foreach ($currentFiles as $file) {
				$fileName = $file->getName();

				// If this file is not in the new set, delete it.
				if (in_array($fileName, $newFileNames, true) === false) {
					try {
						// Use FileService's deleteFile method instead of direct deletion.
						$result = $fileService->deleteFile($file, $objectEntity);

						if ($result === true) {
							$deletedCount++;
						}
					} catch (Exception $e) {
						$this->logger->error("FAILED to delete orphaned file {$fileName}: " . $e->getMessage(), ['exception' => $e]);
					}
				}
			}
		} catch (Exception $e) {
			$this->logger->critical("FATAL ERROR during file cleanup for object {$objectId}: " . $e->getMessage(), ['exception' => $e]);
		}//end try

		return $deletedCount;
	}//end cleanupOrphanedFiles()

	/**
	 * Processes file fetching for multiple files and handles cleanup of orphaned files.
	 *
	 * This method fetches multiple files for an object and ensures that any files
	 * currently attached to the object but not in the new set are removed.
	 *
	 * @param array $source The source to fetch files from.
	 * @param array $config The fetch_file rule configuration.
	 * @param array $endpoints Array of endpoints/file data to process.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return void
	 */
	private function processMultipleFilesWithCleanup(array $source, array $config, array $endpoints, ?string $objectId = null): void {
		if ($endpoints === [] && $objectId !== null) {
			$targetObjectId = $objectId;
		} elseif ($endpoints === []) {
			return;
		}

		// Resolve every endpoint into a work item FIRST, so the concurrency
		// window can be opened over the complete set. This is also why the
		// behaviour carries no missing-file or double-sync risk and does not
		// depend on any source-side ordering: the file list is fully known
		// before a single request is dispatched, and no internal paging splits
		// the source load.
		$resolved = $this->resolveMultiFileWorkItems(config: $config, endpoints: $endpoints, objectId: $objectId);
		$items = $resolved['items'];

		// The sequential loop this replaced assigned $targetObjectId on EVERY
		// iteration — including endpoints that resolved to null and were never
		// fetched — and cleaned up against the LAST one. Preserved deliberately;
		// changing it would silently repoint file cleanup.
		$targetObjectId = $resolved['lastObjectId'];

		$newFileNames = $this->fetchFilesConcurrently(source: $source, config: $config, items: $items);

		// Always run cleanup, even if newFileNames is empty.
		// This handles the case where all files should be removed from an object.
		$this->cleanupOrphanedFiles(objectId: $targetObjectId, newFileNames: $newFileNames);
	}//end processMultipleFilesWithCleanup()

	/**
	 * Resolve a multi-file endpoint list into per-file work items.
	 *
	 * Extracted from {@see processMultipleFilesWithCleanup()} for ocon#111: the
	 * concurrent fetcher needs the whole set of per-file contexts (filename,
	 * tags, target object, register, published state) up front, before any
	 * request is dispatched.
	 *
	 * @param array $config The fetch_file rule configuration.
	 * @param array $endpoints Array of endpoints/file data to process.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return array{items: array<int, array{endpoint: string, objectId: string|null, filename: string|null,
	 *               tags: array, published: mixed, registerId: mixed}>, lastObjectId: string|null}
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-not-depend-on-source-ordering-or-split-source-load
	 */
	private function resolveMultiFileWorkItems(array $config, array $endpoints, ?string $objectId = null): array {
		$items = [];
		$lastObjectId = $objectId;

		foreach ($endpoints as $endpoint) {
			$filename = null;
			$tags = [];
			$contextObjectId = null;
			$registerId = null;
			$published = null;

			// Handle different endpoint types.
			if (is_array($endpoint) === true) {
				// This is an object with file metadata (multidimensional array case).
				$actualEndpoint = $this->getFileContext(
					config: $config,
					endpoint: $endpoint,
					filename: $filename,
					tags: $tags,
					objectId: $contextObjectId,
					published: $published,
					registerId: $registerId
				);
			} else {
				// This is a simple endpoint string (indexed array case).
				$actualEndpoint = $endpoint;
			}

			// Use context object ID if specified, otherwise fall back to the original object ID.
			$lastObjectId = ($contextObjectId ?? $objectId);

			if ($actualEndpoint === null) {
				continue;
			}

			$items[] = [
				'endpoint' => $actualEndpoint,
				'objectId' => $lastObjectId,
				'filename' => $filename,
				'tags' => $tags,
				'published' => $published,
				'registerId' => $registerId,
			];
		}//end foreach

		return [
			'items' => $items,
			'lastObjectId' => $lastObjectId,
		];
	}//end resolveMultiFileWorkItems()

	/**
	 * Resolve the per-source concurrency cap and in-flight byte budget.
	 *
	 * Both live in `source.configuration` rather than in a global setting or a
	 * new top-level source field, because politeness towards an upstream is a
	 * property of THAT upstream — and because the free-form `configuration` path
	 * already works, where a new declared-but-never-read schema field would just
	 * add to the pile of dead source fields.
	 *
	 * The count cap is NOT a memory control. Measured in the dev container:
	 * `memory_limit` 512 M against a per-request cost of curl buffers and headers
	 * (tens of KB, because each fetch streams to a temp file); `ulimit -n` 1024
	 * against 2 descriptors per fetch, so 40 at the hard maximum. Nothing about
	 * PHP binds at these numbers. The real constraints are the upstream source
	 * and — since saves stay serialized — the sum of the saves.
	 *
	 * The byte budget is the guard that actually matters, because count is the
	 * wrong unit on its own: ten 5 MB attachments are trivial where ten 2 GB
	 * exports are not.
	 *
	 * @param array $source The source value object.
	 *
	 * @return array{concurrency: int, byteBudget: int, maxFileSize: int} The clamped cap, the in-flight
	 *                                                                    byte budget (0 = count-only) and the per-file ceiling (0 = no ceiling).
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	private function resolveFetchConcurrency(array $source): array {
		$sourceConfiguration = ($source['configuration'] ?? []);
		if (is_array($sourceConfiguration) === false) {
			$sourceConfiguration = [];
		}

		$concurrency = (int)($sourceConfiguration['maxConcurrentFetches'] ?? self::FETCH_CONCURRENCY_DEFAULT);
		if ($concurrency < 1) {
			$concurrency = 1;
		}

		if ($concurrency > self::FETCH_CONCURRENCY_MAX) {
			$this->logger->warning(
				'Source requested a file-fetch concurrency of ' . $concurrency
				. ', which exceeds the hard maximum of ' . self::FETCH_CONCURRENCY_MAX . '; clamping.'
			);
			$concurrency = self::FETCH_CONCURRENCY_MAX;
		}

		$byteBudget = (int)($sourceConfiguration['maxInFlightFetchBytes'] ?? self::FETCH_BYTE_BUDGET_DEFAULT);
		if ($byteBudget < 0) {
			$byteBudget = 0;
		}

		// PER-FILE CEILING, distinct from the in-flight budget above: that one
		// bounds how many bytes are moving AT ONCE, this one refuses a single
		// oversized file outright. Without it the only file-size control in the
		// stack is the schema's `maxSize`, and that is enforced by
		// FilePropertyHandler at SAVE time — after the whole file has already
		// been downloaded to disk. A 5 GB attachment was fetched in full and
		// only then rejected.
		//
		// 0 = no ceiling, which is the default: a cap that silently drops
		// legitimate large documents is worse than no cap, so this is opt-in.
		$maxFileSize = (int)($sourceConfiguration['maxFileSize'] ?? self::FETCH_MAX_FILE_SIZE_DEFAULT);
		if ($maxFileSize < 0) {
			$maxFileSize = 0;
		}

		return [
			'concurrency' => $concurrency,
			'byteBudget' => $byteBudget,
			'maxFileSize' => $maxFileSize,
		];
	}//end resolveFetchConcurrency()

	/**
	 * Fetch one object's files concurrently, pipelining each save behind its own
	 * resolved download.
	 *
	 * Net wall-clock moves from `Σ fetch + Σ saves` towards
	 * `max(fetch-window, Σ saves)`: the fetches overlap inside a bounded window,
	 * and each save runs from its own promise's `then()` as soon as that download
	 * resolves, rather than waiting for the slowest sibling.
	 *
	 * Saves are NOT parallel and must not become so. They run from promise
	 * callbacks on Guzzle's task queue, which is single-threaded, so exactly one
	 * OpenRegister write is ever in progress — PHP is single-threaded and
	 * Nextcloud uses one shared database connection. That also means the
	 * achievable gain is capped by `Σ saves`; raising the concurrency cap past
	 * the point where the saves dominate buys nothing.
	 *
	 * Per-file failures are isolated: a rejected fetch or a throwing save is
	 * logged and skipped, its temp file released, and the siblings and the object
	 * continue. The tracking filename is recorded either way, exactly as the
	 * sequential path did — cleanup must not delete a file whose fetch merely
	 * failed this run.
	 *
	 * @param array $source The source to fetch files from.
	 * @param array $config The fetch_file rule configuration.
	 * @param array $items The work items from {@see resolveMultiFileWorkItems()}.
	 *
	 * @return array The tracking filenames for cleanup. Order follows settle order rather
	 *               than endpoint order; cleanup only membership-tests it.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	private function fetchFilesConcurrently(array $source, array $config, array $items): array {
		if ($items === []) {
			return [];
		}

		$limits = $this->resolveFetchConcurrency(source: $source);

		// Shared mutable run state. Passed by reference into the generator and
		// every promise callback: the byte tally has to be readable by the
		// admission gate and writable by both settle legs.
		$state = [
			'fileNames' => [],
			'inFlightBytes' => 0,
			'inFlightSize' => [],
			'throttled' => false,
			'released' => [],
			// Monotonic, never derived from count($state['released']) — slots are
			// unset as they settle, so a count-based index would collide with a
			// live slot the moment one file finishes before another starts.
			'nextSlot' => 0,
		];

		try {
			$this->settleFileFetches(source: $source, config: $config, items: $items, limits: $limits, state: $state);
		} catch (\Throwable $exception) {
			// The per-file legs already isolate their own failures, so reaching
			// here means the WINDOW itself failed (an aggregate rejection, a
			// cancelled wait). The object must still continue and — critically —
			// still reach its file cleanup, exactly as it did when this was a
			// sequential loop with a per-file catch.
			$this->logger->error(
				'Concurrent file fetching for this object failed: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		} finally {
			// An abort mid-settle (a cancelled wait, a throw escaping the task
			// queue) leaves the temp files of items that never settled — and of
			// items the pool was still holding back — on disk. Sweep every
			// allocated path that has not already been released.
			$this->releaseUnsettledFileFetches(state: $state);
		}//end try

		if ($state['throttled'] === true) {
			$this->logger->info(
				'File fetches for this object were throttled: concurrency cap ' . $limits['concurrency']
				. ', in-flight byte budget ' . $limits['byteBudget'] . ' bytes.'
			);
		}

		return $state['fileNames'];
	}//end fetchFilesConcurrently()

	/**
	 * Drive the bounded concurrency window over one object's file fetches.
	 *
	 * The generator is what makes the window lazy: `Each::ofLimit()` pulls the
	 * next promise only when it has room, so dispatch happens at admission time
	 * rather than all at once. `ofLimit()` — not `ofLimitAll()` — because the
	 * aggregate must NOT reject when one file fails.
	 *
	 * @param array $source The source to fetch files from.
	 * @param array $config The fetch_file rule configuration.
	 * @param array $items The per-file work items.
	 * @param array $limits The resolved concurrency cap and byte budget.
	 * @param array $state Shared run state, mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	private function settleFileFetches(array $source, array $config, array $items, array $limits, array &$state): void {
		$promises = (function () use ($source, $config, $items, &$state) {
			foreach ($items as $index => $item) {
				yield $index => $this->fetchFileAsync(source: $source, config: $config, item: $item, state: $state);
			}
		})();

		Each::ofLimit(
			$promises,
			$this->buildFetchAdmissionGate(limits: $limits, state: $state),
			null,
			null
		)->wait();
	}//end settleFileFetches()

	/**
	 * Build the admission gate handed to `Each::ofLimit()` as its concurrency
	 * argument.
	 *
	 * Guzzle calls this with the number of currently pending promises and expects
	 * the total it may have in flight. Returning the pending count admits
	 * nothing more this round; the gate is consulted again as each promise
	 * settles, so a held-back request starts as soon as there is room.
	 *
	 * Two gates, in order:
	 *  1. **Count** — never exceed the per-source cap.
	 *  2. **Bytes** — once the `Content-Length` of the requests currently in
	 *     flight exceeds the budget, admit nothing more. Sizes only become known
	 *     when each response's headers arrive (via the `on_headers` hook), so
	 *     this gates on KNOWN in-flight bytes and degrades to count-only against
	 *     a source that omits `Content-Length` — which is exactly the documented
	 *     fallback.
	 *
	 * At least one request is always admitted when nothing is pending. Without
	 * that floor a single attachment larger than the whole budget would never be
	 * fetched, and — worse — the pool would deadlock: with no promise pending,
	 * nothing would ever settle to re-consult this gate.
	 *
	 * @param array $limits The resolved concurrency cap and byte budget.
	 * @param array $state Shared run state, read for the byte tally and marked when throttling.
	 *
	 * @return callable The concurrency callable.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	private function buildFetchAdmissionGate(array $limits, array &$state): callable {
		return function (int $pending) use ($limits, &$state): int {
			// Note the contract: this returns the TOTAL allowed in flight, not a
			// number to add. EachPromise subtracts the pending count itself, so
			// returning the cap is what fills the window — returning 1 here would
			// silently degenerate the pool to sequential dispatch.
			if ($pending >= $limits['concurrency']) {
				$state['throttled'] = true;
			}

			// Byte gate. Only applied while something is already in flight: with
			// nothing pending there is nothing to wait for, so refusing here
			// would both deadlock the pool (no promise left to settle and
			// re-consult this gate) and make a single attachment larger than the
			// whole budget permanently unfetchable.
			if ($pending > 0
				&& $limits['byteBudget'] > 0
				&& $state['inFlightBytes'] >= $limits['byteBudget']
			) {
				$state['throttled'] = true;

				return $pending;
			}

			return $limits['concurrency'];
		};
	}//end buildFetchAdmissionGate()

	/**
	 * Dispatch one file's fetch asynchronously and attach its save and its
	 * cleanup to the resulting promise.
	 *
	 * The sink is the work item's own temp-file PATH. It is never a stream
	 * handle: Guzzle wraps a resource-typed sink in a PSR-7 Stream and closes
	 * that resource on destruct, and under asynchronous dispatch the destruct
	 * happens at a moment this caller does not control — so N shared handles
	 * would be a strictly worse version of the defect stream-file-content fixed.
	 *
	 * Every leg releases. `then()` saves and releases; `otherwise()` logs and
	 * releases. Splitting fetch from save moved the release out of one `finally`
	 * per call, and N concurrent fetches with partial failures is precisely the
	 * shape where a missing release leaks a descriptor and a temp file per
	 * failure.
	 *
	 * @param array $source The source to fetch the file from.
	 * @param array $config The fetch_file rule configuration.
	 * @param array $item One work item from {@see resolveMultiFileWorkItems()}.
	 * @param array $state Shared run state, mutated in place.
	 *
	 * @return PromiseInterface A promise that settles once this file has been saved or isolated.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) 118 lines. A promise chain, and
	 *   the length is the chain's `then`/`otherwise` handlers written inline where the
	 *   flow reads top to bottom. The second spec above — one file's failure must not
	 *   abort the others or the object — lives entirely in those handlers, so pulling
	 *   them into named callbacks moves the guarantee away from the chain that
	 *   implements it without shortening anything that matters.
	 */
	private function fetchFileAsync(array $source, array $config, array $item, array &$state): PromiseInterface {
		$slot = null;

		try {
			$prepared = $this->prepareFileFetch(source: $source, endpoint: $item['endpoint'], config: $config);

			$slot = $state['nextSlot'];
			$state['nextSlot']++;
			$state['released'][$slot] = $prepared;
			$state['inFlightSize'][$slot] = 0;

			$promise = $this->callSourceObjectAsync(
				source: $source,
				endpoint: $prepared['endpoint'],
				method: $prepared['config']['method'] ?? 'GET',
				config: $prepared['config']['sourceConfiguration'] ?? [],
				sink: $prepared['sinkPath'],
				// Resolved from the source here rather than threaded in: `$limits`
				// belongs to settleFileFetches() and is NOT in scope in this
				// method. Reading it as `$limits['maxFileSize']` from here is
				// silently null — no warning, no error, cap quietly disabled —
				// which is precisely the failure this ceiling exists to prevent.
				onHeaders: $this->buildInFlightSizeRecorder(
					slot: $slot,
					state: $state,
					maxFileSize: $this->resolveFetchConcurrency(source: $source)['maxFileSize']
				)
			);
		} catch (\Throwable $exception) {
			// A SYNCHRONOUS throw before dispatch — an unresolvable source, a Twig
			// error in the source configuration, a guard that raises rather than
			// short-circuits. This must not escape: the generator feeding
			// Each::ofLimit() is advanced inside EachPromise::advanceIterator(),
			// which rejects the AGGREGATE on a throw and would abort every file
			// that had not started yet. Isolate it here and hand back a settled
			// promise so the pool simply moves on.
			$this->logger->error(
				'Failed to dispatch file fetch for endpoint ' . $item['endpoint'] . ': ' . $exception->getMessage(),
				['exception' => $exception]
			);

			$this->trackFetchedFilename(item: $item, filename: $item['filename'], state: $state);

			if ($slot !== null) {
				$this->releaseFetchSlot(slot: $slot, state: $state);
			}

			return new FulfilledPromise(null);
		}//end try

		return $promise->then(
			function ($callLog) use ($prepared, $item, $slot, &$state) {
				try {
					// Runs on the single-threaded promise task queue, so this
					// OpenRegister write is serialized against every sibling
					// save even though the fetches overlapped.
					$filename = $item['filename'];
					$this->saveFetchedFile(
						prepared: $prepared,
						callLog: $callLog,
						objectId: $item['objectId'],
						tags: $item['tags'],
						filename: $filename,
						published: $item['published'],
						registerId: $item['registerId']
					);

					// COUNTED HERE TOO. The concurrent path splits fetch from
					// save and never goes through fetchFileSafely(), so a tally
					// kept only there reported "saved: 0, failed: 0" for a run
					// that had just written 6 files — a counter that is always
					// zero is worse than none, because it reads as a finding.
					$this->fileFetchSucceeded++;

					$this->trackFetchedFilename(item: $item, filename: $filename, state: $state);
				} catch (\Throwable $exception) {
					// A failed SAVE is isolated exactly like a failed fetch: the
					// remaining files and the object continue.
					$this->fileFetchFailed++;
					$this->logger->error(
						'Failed to save file from endpoint ' . $item['endpoint'] . ': ' . $exception->getMessage(),
						['exception' => $exception]
					);

					// The filename is still tracked: cleanup must not delete a
					// file whose save merely failed this run.
					$this->trackFetchedFilename(item: $item, filename: $item['filename'], state: $state);
				} finally {
					$this->releaseFetchSlot(slot: $slot, state: $state);
				}//end try
			},
			function ($reason) use ($item, $slot, &$state) {
				$message = $reason;
				$exception = null;
				if ($reason instanceof \Throwable === true) {
					$message = $reason->getMessage();
					$exception = $reason;
				}

				// The REJECTION leg — a download that never arrived. Counted
				// alongside failed saves so "failed" means "this file is not
				// there", whichever half of the pipeline lost it.
				$this->fileFetchFailed++;

				$this->logger->error(
					'Failed to fetch file from endpoint ' . $item['endpoint'] . ': ' . $message,
					['exception' => $exception]
				);

				// Note: we still keep the filename in the tracking array even if
				// the fetch fails. This prevents cleanup from deleting files that
				// should exist.
				$this->trackFetchedFilename(item: $item, filename: $item['filename'], state: $state);

				$this->releaseFetchSlot(slot: $slot, state: $state);
			}
		);
	}//end fetchFileAsync()

	/**
	 * Build the `on_headers` callback that records one in-flight download's
	 * declared size for the byte budget.
	 *
	 * Guzzle invokes this once the response headers have arrived and BEFORE the
	 * body is downloaded, which is the only point where a size is both known and
	 * still useful for admission control. A source that omits `Content-Length`
	 * records nothing, and that request is then gated by count alone.
	 *
	 * This must never throw ACCIDENTALLY: an exception raised from `on_headers`
	 * rejects the request, which would turn a missing header into a failed file.
	 * The ONE deliberate exception is the per-file ceiling — there, rejecting
	 * before the body arrives is the entire point, and it is the only place in
	 * the stack where an oversized file can be refused without first
	 * downloading it (the schema's `maxSize` is checked at save time).
	 *
	 * @param int $slot The per-file slot index in the run state.
	 * @param array $state Shared run state, mutated in place.
	 * @param int $maxFileSize Per-file ceiling in bytes; 0 disables it.
	 *
	 * @return callable The on_headers callback.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	private function buildInFlightSizeRecorder(int $slot, array &$state, int $maxFileSize = 0): callable {
		return function ($response) use ($slot, &$state, $maxFileSize): void {
			$declared = 0;
			if (is_object($response) === true && method_exists($response, 'getHeaderLine') === true) {
				$declared = (int)$response->getHeaderLine('Content-Length');
			}

			if ($declared <= 0) {
				return;
			}

			// Refuse BEFORE the body downloads. A source that omits
			// Content-Length cannot be gated here — it falls through to the
			// schema's save-time `maxSize`, which is the existing behaviour.
			if ($maxFileSize > 0 && $declared > $maxFileSize) {
				// LOG BEFORE THROWING. Guzzle replaces anything raised here with
				// its own "An error was encountered during the on_headers
				// event", so the reason never reaches the operator otherwise —
				// a deliberate size refusal would be indistinguishable from a
				// broken source.
				$this->logger->warning(
					'[SynchronizationService] file refused before download: declares ' . $declared
					. ' bytes, exceeding the per-file ceiling of ' . $maxFileSize
					. ' bytes (source configuration.maxFileSize).'
				);

				throw new Exception(
					'File declares ' . $declared . ' bytes, which exceeds the per-file ceiling of '
					. $maxFileSize . ' bytes (source configuration.maxFileSize); refused before download.'
				);
			}

			$state['inFlightSize'][$slot] = $declared;
			$state['inFlightBytes'] += $declared;
		};
	}//end buildInFlightSizeRecorder()

	/**
	 * Release one file's slot: drop its share of the in-flight byte tally and
	 * remove its temp file.
	 *
	 * Idempotent, because it is reachable from both promise legs and again from
	 * {@see releaseUnsettledFileFetches()} when a run unwinds early.
	 *
	 * @param int $slot The per-file slot index in the run state.
	 * @param array $state Shared run state, mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	private function releaseFetchSlot(int $slot, array &$state): void {
		if (isset($state['inFlightSize'][$slot]) === true) {
			$state['inFlightBytes'] -= $state['inFlightSize'][$slot];
			unset($state['inFlightSize'][$slot]);
		}

		if ($state['inFlightBytes'] < 0) {
			$state['inFlightBytes'] = 0;
		}

		if (isset($state['released'][$slot]) === false) {
			return;
		}

		$this->releaseFileFetch(prepared: $state['released'][$slot]);
		unset($state['released'][$slot]);
	}//end releaseFetchSlot()

	/**
	 * Sweep the temp files of every fetch that was allocated but never settled.
	 *
	 * Reached when a run unwinds before the pool drained — a cancelled wait, a
	 * throw escaping the task queue, or requests the admission gate was still
	 * holding back when the object aborted. Slots that settled normally have
	 * already removed themselves, so this is a backstop rather than the primary
	 * release path.
	 *
	 * @param array $state Shared run state, mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	private function releaseUnsettledFileFetches(array &$state): void {
		foreach (array_keys($state['released']) as $slot) {
			$this->releaseFetchSlot(slot: $slot, state: $state);
		}
	}//end releaseUnsettledFileFetches()

	/**
	 * Record the tracking filename for one processed file.
	 *
	 * Carries over the sequential path's rules verbatim: prefer the filename the
	 * fetch resolved, fall back to the last endpoint path segment, and fall back
	 * again to a hash when that segment is empty or query-string-bearing.
	 *
	 * @param array $item The work item.
	 * @param string|null $filename The filename resolved by the save phase, when any.
	 * @param array $state Shared run state, mutated in place.
	 *
	 * @return void
	 */
	private function trackFetchedFilename(array $item, ?string $filename, array &$state): void {
		$trackingFilename = $filename;

		if ($trackingFilename === null) {
			// Try to extract filename from endpoint URL.
			$pathParts = explode('/', $item['endpoint']);
			$trackingFilename = end($pathParts);

			// If still no clear filename, generate a fallback.
			if (empty($trackingFilename) === true || strpos($trackingFilename, '?') !== false) {
				$trackingFilename = 'file_' . md5($item['endpoint']);
			}
		}

		if (empty($trackingFilename) === false) {
			$state['fileNames'][] = $trackingFilename;
		}
	}//end trackFetchedFilename()

	/**
	 * Cleans up files for an object based on the current attachments array.
	 *
	 * This method compares the files currently attached to an object with the files
	 * that should exist based on the attachments array from the synchronized data.
	 * Files that are no longer referenced in the attachments will be removed.
	 *
	 * @param string $objectId The UUID of the object to clean up files for.
	 * @param array $attachments Array of attachment objects with filename properties.
	 *
	 * @return int The number of files that were deleted.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 */
	public function cleanupFilesFromAttachments(string $objectId, array $attachments): int {
		// Extract filenames from attachments array.
		$expectedFileNames = [];
		foreach ($attachments as $attachment) {
			if (isset($attachment['filename']) === true && empty($attachment['filename']) === false) {
				$expectedFileNames[] = $attachment['filename'];
			}
		}

		// Remove duplicates in case the same file appears multiple times with different labels.
		$expectedFileNames = array_unique($expectedFileNames);

		// Use the existing cleanup method.
		return $this->cleanupOrphanedFiles(objectId: $objectId, newFileNames: $expectedFileNames);
	}//end cleanupFilesFromAttachments()

	/**
	 * Determines if a file should be published based on the published parameter.
	 *
	 * This method checks if the published parameter indicates that a file should be published.
	 * It supports boolean values (true/false), string values ("true"/"false"), and date strings.
	 * For date strings, it assumes the file should be published if a date is provided.
	 *
	 * @param string|null $published The published parameter from the attachment data.
	 *
	 * @return bool True if the file should be published, false otherwise.
	 */
	private function shouldPublishFile(?string $published): bool {
		if ($published === null) {
			return false;
		}

		// Handle boolean true values.
		if ($published === true || $published === 'true' || $published === '1') {
			return true;
		}

		// Handle boolean false values.
		if ($published === false || $published === 'false' || $published === '0') {
			return false;
		}

		// Handle date strings - if it's a valid date string, consider it as published.
		if (empty($published) === false) {
			// Try to parse as a date.
			$date = \DateTime::createFromFormat(\DateTime::ATOM, $published);
			if ($date !== false) {
				return true;
			}

			// Try other common date formats.
			$formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'];
			foreach ($formats as $format) {
				$date = \DateTime::createFromFormat($format, $published);
				if ($date !== false) {
					return true;
				}
			}
		}

		return false;
	}//end shouldPublishFile()

	/*
	 * Fetches a file from a given endpoint and saves it to the OpenRegister system.
	 *
	 * This method downloads a file from a remote source and stores it in the OpenRegister
	 * file system, optionally applying tags and sharing settings.
	 *
	 * @param array $source The source configuration for the API call.
	 * @param string $endpoint The endpoint URL to fetch the file from.
	 * @param array $config Configuration array containing method, write settings, etc.
	 * @param string $objectId The UUID of the object to attach the file to.
	 * @param array|null $tags Optional array of tags to apply to the file.
	 * @param string|null $filename Optional filename to use for the saved file.
	 * @param string|null $published Optional published status to determine if file should be published.
	 *
	 * @return string The original endpoint URL.
	 * @throws Exception If the file cannot be fetched or saved.
	 * @throws \OCP\DB\Exception
	 */

}//end class
