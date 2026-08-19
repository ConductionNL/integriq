<?php

/**
 * OpenConnector Synchronization → Flow migration generator.
 *
 * Task 3.1 of `flow-native-synchronization`: renders an existing
 * Synchronization entity into a GENERATED FLOW DOCUMENT — the decomposed
 * pipeline the design draws, built entirely out of the five page-level nodes
 * already on development plus the OpenRegister nodes they compose with.
 *
 * It writes a document and nothing else. It creates no flow object, touches no
 * SynchronizationContract, and never enables anything: `enabled` is `false` on
 * every document it produces, because the design says "named, disabled until
 * reviewed" and a generator that switched syncs over by itself would be a
 * migration nobody read.
 *
 * WHAT IT EMITS
 * -------------
 *   trigger-manual
 *     → openconnector.source-paginate   (output: page)
 *     → openregister.explode            (page.results → one item per object)
 *     → openconnector.apply-mapping     (source → target)
 *     → openconnector.contract          (create / update / skip / invalid)
 *     → openregister.set-fields         (targetUuid, defaulted to "")
 *     → openregister.object-write       (upsert, matched on @self.uuid)
 *     → openconnector.contract-commit
 *     → openconnector.contract-sweep
 *     → openregister.end
 *
 * WHY AN `explode` STEP THE DESIGN DOES NOT DRAW
 * ---------------------------------------------
 * `source-paginate` emits one item per PAGE, carrying that page's objects as an
 * array. Every step after it — `contract` reads ONE origin id per item,
 * `object-write` writes ONE object per item — acts per item. Without the
 * explode, a whole page would be treated as a single object: every item would
 * be decided `invalid` (a page has no `id`), nothing would be contracted, and
 * the sweep would then see zero synced target ids. The batching the design asks
 * for is not lost by exploding: the engine hands a node its WHOLE item list in
 * one `execute()` call, which is exactly where the one contract SELECT and the
 * one bulk write happen.
 *
 * WHY `upsert` AND NOT `bulk: true`
 * ---------------------------------
 * A real pass mixes creates and updates in one run, and the flow engine cannot
 * route per item: `FlowTokenRouter::takenExits()` evaluates a branch condition
 * against `$items[0]` and sends the WHOLE token down one exit, so a
 * create-branch / update-branch split is not expressible. That leaves one node
 * to do both, and `object-write`'s `upsert` does — it creates when its match
 * finds nothing. But a BULK upsert refuses an unresolvable match value
 * (`bulkRowId()` throws rather than "widening into a create"), and a create
 * decision has no `contract.targetId` to resolve. So the generated flow uses
 * the single-object upsert path, whose `findMatch()` returns null for an empty
 * uuid and lets the upsert insert. The `set-fields` step exists solely to make
 * that uuid ALWAYS resolvable — `{"var": ["json.contract.targetId", ""]}` —
 * because a match value that is merely ABSENT is refused, while one that is
 * empty is a miss.
 *
 * WHY THE COMMIT STEP HASHES `target` AND NOT `written`
 * -----------------------------------------------------
 * `contract-commit`'s `targetHashPosition` names the MAPPED object — the key
 * `apply-mapping` wrote its result under — because that is the only value on
 * the item that is a pure function of the source. The WRITTEN object carries
 * server-assigned `@self` fields, `updated` among them, so hashing it would
 * produce a different hash on every pass and `contract`'s `skip` would stay as
 * unreachable as it was before the hash existed at all.
 *
 * The three keys have to agree or the flow writes empty objects: `map` names
 * its output `target`, `write`'s `fields` templates read `{{target.<prop>}}`,
 * and `commit` hashes `target`. The top-level record keeps the SOURCE, which
 * is what `contract`'s `idPosition` (`source.<idPosition>`) reads.
 *
 * KNOWN DEVIATIONS FROM THE LEGACY PASS (deliberate, not hidden)
 * -------------------------------------------------------------
 *  - `skip` decisions still reach the OBJECT write. The legacy engine performs
 *    zero writes for an unchanged object; here the item still passes through
 *    the upsert, because dropping it with a filter would also drop its target
 *    id from the stale sweep — and a sweep that cannot see an unchanged object
 *    DELETES it. Re-writing is doing more, never less; deleting would be the
 *    other way. What a `skip` DOES buy is a zero-write CONTRACT commit: the
 *    commit step passes skipped items through untouched, so an unchanged page
 *    costs one contract SELECT and no contract upsert at all. The object write
 *    itself is not conditional, and `SaveObject` stamps `@self.updated` on
 *    every update it performs, so a re-run still moves the target objects'
 *    `updated` timestamps. Making the object write conditional too needs the
 *    sweep to learn a second source of target ids, which is its own change.
 *  - The written field list is frozen at generation time. `object-write` has no
 *    "write the whole record" shorthand — `buildPayload()` iterates the
 *    configured `fields` map only — so the generator enumerates the mapping's
 *    own output keys. A key added to the mapping later is NOT written until the
 *    flow is regenerated. The generated flow's description says so.
 *  - `pageSize` / `maxPages` are omitted rather than carried across from
 *    `sourceConfig`: the legacy keys count HTTP pages, the node's count emitted
 *    item batches. Same word, different unit, so copying them would be a guess.
 *  - `onError` is omitted, leaving the engine's default `stop`. The legacy
 *    engine has no per-step equivalent to translate.
 *  - `force` is never set on the sweep. It overrides the deletion-ratio guard,
 *    and a generator is not the place that decision gets made.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Exception\SynchronizationNotMigratableException;
use OCA\OpenConnector\Flow\ApplyMappingNode;
use OCA\OpenConnector\Flow\ContractCommitNode;
use OCA\OpenConnector\Flow\ContractMatchNode;
use OCA\OpenConnector\Flow\ContractSweepNode;
use OCA\OpenConnector\Flow\SourcePaginateNode;
use OCP\IL10N;
use Throwable;

/**
 * Renders a Synchronization into a disabled, reviewable flow document.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#3-migration--deprecation
 */
class SynchronizationFlowGenerator {

	/**
	 * The item key the fetched page is written under.
	 *
	 * @var string
	 */
	public const KEY_PAGE = 'page';

	/**
	 * The item key one exploded source object is written under.
	 *
	 * @var string
	 */
	public const KEY_SOURCE = 'source';

	/**
	 * The item key the mapped target object is written under.
	 *
	 * @var string
	 */
	public const KEY_TARGET = 'target';

	/**
	 * The item key the contract decision block is written under.
	 *
	 * @var string
	 */
	public const KEY_CONTRACT = 'contract';

	/**
	 * The item key the written object is written under.
	 *
	 * @var string
	 */
	public const KEY_WRITTEN = 'written';

	/**
	 * The item key carrying an always-resolvable target uuid.
	 *
	 * @var string
	 */
	public const KEY_TARGET_UUID = 'targetUuid';

	/**
	 * The field carrying the target id this pass REACHED, written or skipped.
	 *
	 * `contract-sweep` deletes whatever its items do not name. A skipped item
	 * never gets a `written` block, so reading the sweep's ids from
	 * `written.uuid` alone would drop every unchanged object out of the synced
	 * set and the sweep would delete exactly the objects that were fine.
	 *
	 * @var string
	 */
	public const KEY_SYNCED_ID = 'syncedId';

	/**
	 * The source kind the decomposed fetch step can serve.
	 *
	 * `SourcePaginateNode` delegates to `getAllObjectsFromApi()` directly, not
	 * to the `getAllObjectsFromSource()` switch, so `nextcloud-table`,
	 * `nextcloud-form`, `register/schema` and `database` sources have no fetch
	 * step at all.
	 *
	 * @var string
	 */
	private const SUPPORTED_SOURCE_TYPE = 'api';

	/**
	 * The target kind `openregister.object-write` can serve.
	 *
	 * @var string
	 */
	private const SUPPORTED_TARGET_TYPE = 'register/schema';

	/**
	 * The fetch strategy the decomposed flow expresses.
	 *
	 * @var string
	 */
	private const SUPPORTED_SYNC_MODE = 'full';

	/**
	 * Constructor.
	 *
	 * @param SynchronizationService $synchronizationService Resolves a synchronization by uuid, slug or reference.
	 * @param MappingService $mappingService Resolves the source→target mapping whose keys become the written fields.
	 * @param IL10N $l10n Translations.
	 */
	public function __construct(
		private readonly SynchronizationService $synchronizationService,
		private readonly MappingService $mappingService,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Generate the flow document for a synchronization named by reference.
	 *
	 * @param string $reference The synchronization's uuid, slug or reference.
	 *
	 * @return array The flow document.
	 *
	 * @throws SynchronizationNotMigratableException When the synchronization cannot be
	 *                                               resolved, or uses a feature the
	 *                                               decomposed flow cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFor(string $reference): array {
		$trimmed = trim($reference);
		if ($trimmed === '') {
			throw new SynchronizationNotMigratableException(
				message: $this->l10n->t('A synchronization reference is required.'),
				reasons: [$this->l10n->t('No synchronization was named.')]
			);
		}

		try {
			$synchronization = $this->synchronizationService->getSynchronization(id: $trimmed)->jsonSerialize();
		} catch (Throwable $exception) {
			throw new SynchronizationNotMigratableException(
				message: $this->l10n->t('The synchronization "%1$s" could not be read.', [$trimmed]),
				reasons: [$exception->getMessage()],
				previous: $exception
			);
		}

		return $this->generateFrom(synchronization: (array)$synchronization);

	}//end generateFor()

	/**
	 * Generate the flow document for an already-read synchronization.
	 *
	 * Nothing is persisted and no contract is touched: the caller decides what
	 * to do with the document, and the document itself is disabled.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array The flow document.
	 *
	 * @throws SynchronizationNotMigratableException When the synchronization uses a
	 *                                               feature the decomposed flow
	 *                                               cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFrom(array $synchronization): array {
		$reasons = $this->refusalsFor(synchronization: $synchronization);
		if ($reasons !== []) {
			throw new SynchronizationNotMigratableException(
				message: $this->l10n->t(
					'The synchronization "%1$s" cannot be migrated to a flow yet: %2$s unsupported feature(s).',
					[$this->labelOf(synchronization: $synchronization), (string)count($reasons)]
				),
				reasons: $reasons
			);
		}

		$reference = $this->referenceOf(synchronization: $synchronization);
		$nodes = $this->nodesFor(synchronization: $synchronization, reference: $reference);

		return [
			'name' => $this->l10n->t(
				'%1$s (generated from synchronization)',
				[$this->labelOf(synchronization: $synchronization)]
			),
			'description' => $this->describe(synchronization: $synchronization, reference: $reference),
			'enabled' => false,
			'trigger' => 'manual',
			'nodes' => $nodes,
			'edges' => $this->edgesFor(nodes: $nodes),
		];

	}//end generateFrom()

	/**
	 * Every feature of this synchronization the decomposed flow cannot express.
	 *
	 * An empty list means the synchronization is migratable. A non-empty one is
	 * the refusal: emitting a flow anyway would replace a rule with silence,
	 * which is strictly worse than not migrating at all.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function refusalsFor(array $synchronization): array {
		return array_merge(
			$this->transportRefusals(synchronization: $synchronization),
			$this->semanticRefusals(synchronization: $synchronization),
			$this->fieldRefusals(synchronization: $synchronization)
		);

	}//end refusalsFor()

	/**
	 * Refusals about where the objects come from and where they go.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function transportRefusals(array $synchronization): array {
		$reasons = [];

		if ($this->referenceOf(synchronization: $synchronization) === '') {
			$reasons[] = $this->l10n->t(
				'The synchronization carries no uuid, slug or reference, so no flow step could name it.'
			);
		}

		$sourceType = trim((string)($synchronization['sourceType'] ?? ''));
		if ($sourceType !== self::SUPPORTED_SOURCE_TYPE) {
			$reasons[] = $this->l10n->t(
				'sourceType "%1$s": the fetch step only serves "api" sources; there is no decomposed step for this kind yet.',
				[$sourceType]
			);
		}

		$targetType = trim((string)($synchronization['targetType'] ?? ''));
		if ($targetType !== self::SUPPORTED_TARGET_TYPE) {
			$reasons[] = $this->l10n->t(
				'targetType "%1$s": the write step only writes OpenRegister objects ("register/schema").',
				[$targetType]
			);
		}

		// `targetPair()` is all-or-nothing: an unusable pair returns two empty
		// halves, so the first one answers for both.
		[$register] = $this->targetPair(synchronization: $synchronization);
		if ($register === '') {
			$reasons[] = $this->l10n->t(
				'targetId "%1$s" is not a "register/schema" pair, so the write step has no register and schema to name.',
				[trim((string)($synchronization['targetId'] ?? ''))]
			);
		}

		return $reasons;

	}//end transportRefusals()

	/**
	 * Refusals about rules the flow would otherwise silently drop.
	 *
	 * Each of these changes WHICH objects are synchronised, or what happens
	 * around the write. A generated flow that omitted one would run green and
	 * do less than the synchronization it replaced.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function semanticRefusals(array $synchronization): array {
		$sourceConfig = (array)($synchronization['sourceConfig'] ?? []);
		$reasons = [];

		$syncMode = trim((string)($synchronization['syncMode'] ?? self::SUPPORTED_SYNC_MODE));
		if ($syncMode !== '' && $syncMode !== self::SUPPORTED_SYNC_MODE) {
			$reasons[] = $this->l10n->t(
				'syncMode "%1$s": no step carries the cursor watermark, and the stale sweep refuses an incremental pass.',
				[$syncMode]
			);
		}

		if (trim((string)($synchronization['sourceHashMapping'] ?? '')) !== '') {
			$reasons[] = $this->l10n->t(
				'sourceHashMapping: the contract step hashes a dot-path on the item, not the output of a mapping, '
				. 'so change detection would compare a different value than the stored contract.'
			);
		}

		if (trim((string)($synchronization['targetSourceMapping'] ?? '')) !== '') {
			$reasons[] = $this->l10n->t(
				'targetSourceMapping: the reverse (target→source) leg of a bidirectional sync has no decomposed steps.'
			);
		}

		foreach (['conditions', 'actions', 'followUps'] as $key) {
			if ((array)($synchronization[$key] ?? []) === []) {
				continue;
			}

			$reasons[] = $this->l10n->t(
				'%1$s: this synchronization declares them and no generated step evaluates them.',
				[$key]
			);
		}

		return array_merge($reasons, $this->sourceConfigRefusals(sourceConfig: $sourceConfig));

	}//end semanticRefusals()

	/**
	 * Refusals hidden inside `sourceConfig`.
	 *
	 * @param array $sourceConfig The synchronization's source configuration.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function sourceConfigRefusals(array $sourceConfig): array {
		$reasons = [];

		if ((array)($sourceConfig['subObjects'] ?? []) !== []) {
			$reasons[] = $this->l10n->t(
				'sourceConfig.subObjects: nested objects are written and contracted by the legacy engine only.'
			);
		}

		if ((bool)($sourceConfig['requiresApproval'] ?? false) === true) {
			$reasons[] = $this->l10n->t(
				'sourceConfig.requiresApproval: the write would bypass the approval gate the synchronization requires.'
			);
		}

		foreach (['originIdsToReplace', 'idsToReplaceWithTargetIdsBeforeRules'] as $key) {
			if ((array)($sourceConfig[$key] ?? []) === []) {
				continue;
			}

			$reasons[] = $this->l10n->t(
				'sourceConfig.%1$s: origin-id rewriting happens inside the legacy write path and has no step.',
				[$key]
			);
		}

		return $reasons;

	}//end sourceConfigRefusals()

	/**
	 * Refusals about the field list the write step has to enumerate.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function fieldRefusals(array $synchronization): array {
		$reference = trim((string)($synchronization['sourceTargetMapping'] ?? ''));
		if ($reference === '') {
			return [
				$this->l10n->t(
					'sourceTargetMapping is not set: with no mapping the written properties cannot be enumerated, '
					. 'and "openregister.object-write" has no shorthand for writing an item whole.'
				),
			];
		}

		try {
			$mapping = $this->mappingService->getMapping(mappingId: $reference);
		} catch (Throwable $exception) {
			return [
				$this->l10n->t(
					'sourceTargetMapping "%1$s" could not be read: %2$s',
					[$reference, $exception->getMessage()]
				),
			];
		}

		if ($mapping->getPassThrough() === true) {
			return [
				$this->l10n->t(
					'The mapping "%1$s" passes unmapped fields through, so the set of written properties is not '
					. 'knowable ahead of a run and the write step would silently drop the ones it did not list.',
					[$reference]
				),
			];
		}

		if ($this->fieldsFor(mapping: $mapping) === []) {
			return [
				$this->l10n->t(
					'The mapping "%1$s" declares no output field left to write.',
					[$reference]
				),
			];
		}

		return [];

	}//end fieldRefusals()

	/**
	 * The `fields` map the write step writes, one entry per mapped property.
	 *
	 * Derived from the MAPPING rather than the target schema on purpose: the
	 * mapping's keys are exactly what the synchronization writes today, where a
	 * schema's property list also contains properties the sync never sets.
	 * Dotted keys (`a.b`) build a nested value under `a`, so only the top-level
	 * segment is a written property — and a dotted `unset` entry removes a
	 * sub-key, never the whole property.
	 *
	 * @param object $mapping The resolved source→target mapping.
	 *
	 * @return array<string, string> Property name => item template.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function fieldsFor(object $mapping): array {
		$properties = [];
		foreach (array_keys((array)$mapping->getMapping()) as $key) {
			$top = trim(explode('.', (string)$key)[0]);
			if ($top === '') {
				continue;
			}

			$properties[$top] = true;
		}

		foreach ((array)$mapping->getUnset() as $key) {
			$key = trim((string)$key);
			if ($key === '' || str_contains($key, '.') === true) {
				continue;
			}

			unset($properties[$key]);
		}

		$fields = [];
		foreach (array_keys($properties) as $property) {
			$fields[$property] = '{{' . self::KEY_TARGET . '.' . $property . '}}';
		}

		return $fields;

	}//end fieldsFor()

	/**
	 * Build the flow's action nodes, in pipeline order.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 * @param string $reference The reference every step names the synchronization by.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function nodesFor(array $synchronization, string $reference): array {
		$sourceConfig = (array)($synchronization['sourceConfig'] ?? []);
		$idPosition = trim((string)($sourceConfig['idPosition'] ?? ''));
		if ($idPosition === '') {
			$idPosition = 'id';
		}

		[$register, $schema] = $this->targetPair(synchronization: $synchronization);
		$mapping = $this->mappingService->getMapping(
			mappingId: trim((string)($synchronization['sourceTargetMapping'] ?? ''))
		);

		return [
			['id' => 'trigger', 'type' => 'openregister.trigger-manual', 'config' => []],
			[
				'id' => 'fetch',
				'type' => SourcePaginateNode::NODE_ID,
				'config' => [
					'synchronization' => $reference,
					'output' => self::KEY_PAGE,
				],
			],
			[
				'id' => 'explode',
				'type' => 'openregister.explode',
				'config' => [
					'path' => self::KEY_PAGE . '.results',
					'as' => self::KEY_SOURCE,
					'keepRecord' => true,
				],
			],
			[
				'id' => 'map',
				'type' => ApplyMappingNode::NODE_ID,
				'config' => [
					'mapping' => trim((string)$synchronization['sourceTargetMapping']),
					'input' => self::KEY_SOURCE,
					'output' => self::KEY_TARGET,
				],
			],
			[
				'id' => 'contract',
				'type' => ContractMatchNode::NODE_ID,
				'config' => [
					'synchronization' => $reference,
					'idPosition' => self::KEY_SOURCE . '.' . $idPosition,
					'hashPosition' => self::KEY_SOURCE,
					'output' => self::KEY_CONTRACT,
				],
			],
			[
				'id' => 'target-uuid',
				'type' => 'openregister.set-fields',
				'config' => [
					'compute' => [
						self::KEY_TARGET_UUID => [
							'var' => ['json.' . self::KEY_CONTRACT . '.targetId', ''],
						],
					],
				],
			],
			[
				'id' => 'write',
				'type' => 'openregister.object-write',
				'config' => [
					'register' => $register,
					'schema' => $schema,
					'operation' => 'upsert',
					'replace' => true,
					'match' => [
						['property' => '@self.uuid', 'value' => '{{' . self::KEY_TARGET_UUID . '}}'],
					],
					'fields' => $this->fieldsFor(mapping: $mapping),
					'output' => self::KEY_WRITTEN,
					// An item the contract step decided is unchanged passes
					// through UNWRITTEN. Without this the re-run rewrote every
					// object it had just decided to skip, because
					// SaveObject::updateObject() stamps `updated`
					// unconditionally.
					'skipWhen' => self::KEY_CONTRACT . '.outcome',
				],
			],
			[
				// The sweep's id source has to survive a skip. A written item
				// has `written.uuid`; a skipped one does not, but it does have
				// the contract's own targetId (a `skip` decision requires one).
				// Collapsing both into one field is what keeps an unchanged
				// object inside the synced set instead of on the delete list.
				'id' => 'synced-id',
				'type' => 'openregister.set-fields',
				'config' => [
					'compute' => [
						self::KEY_SYNCED_ID => [
							'if' => [
								['var' => ['json.' . self::KEY_WRITTEN . '.uuid', '']],
								['var' => ['json.' . self::KEY_WRITTEN . '.uuid', '']],
								['var' => ['json.' . self::KEY_TARGET_UUID, '']],
							],
						],
					],
				],
			],
			[
				'id' => 'commit',
				'type' => ContractCommitNode::NODE_ID,
				'config' => [
					'synchronization' => $reference,
					'contractPosition' => self::KEY_CONTRACT,
					'targetIdPosition' => self::KEY_WRITTEN . '.uuid',
					'targetHashPosition' => self::KEY_TARGET,
				],
			],
			[
				'id' => 'sweep',
				'type' => ContractSweepNode::NODE_ID,
				'config' => [
					'synchronization' => $reference,
					'targetIdsPosition' => self::KEY_SYNCED_ID,
					'fetchComplete' => self::KEY_PAGE . '.fetchInfo.complete',
				],
			],
			['id' => 'end', 'type' => 'openregister.end', 'config' => []],
		];

	}//end nodesFor()

	/**
	 * Chain the nodes together, one edge per consecutive pair.
	 *
	 * @param array $nodes The flow's nodes, in pipeline order.
	 *
	 * @return array<int, array<string, string>> The edges.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function edgesFor(array $nodes): array {
		$ids = array_map(static fn (array $node): string => (string)$node['id'], array_values($nodes));

		$edges = [];
		$total = count($ids);
		for ($index = 0; $index < ($total - 1); $index++) {
			$from = $ids[$index];
			$to = $ids[($index + 1)];
			$edges[] = [
				'id' => $from . '-' . $to,
				'from' => $from,
				'to' => $to,
			];
		}

		return $edges;

	}//end edgesFor()

	/**
	 * The description that lets a human trace the flow back to its source.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 * @param string $reference The reference every step names the synchronization by.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function describe(array $synchronization, string $reference): string {
		return $this->l10n->t(
			'Generated from synchronization "%1$s" (%2$s) by the flow-native-synchronization migration. '
			. 'It is disabled until a human has reviewed it. The written properties are the mapping\'s output '
			. 'keys AS THEY WERE at generation time — a property added to the mapping later is not written '
			. 'until this flow is regenerated. Contracts are untouched: the first run upserts exactly as the '
			. 'synchronization\'s next run would have.',
			[$this->labelOf(synchronization: $synchronization), $reference]
		);

	}//end describe()

	/**
	 * The reference every generated step names the synchronization by.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return string The uuid, slug or reference; empty when there is none.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function referenceOf(array $synchronization): string {
		foreach (['uuid', 'slug', 'reference', 'id'] as $key) {
			$value = ($synchronization[$key] ?? null);
			if (is_scalar($value) === false) {
				continue;
			}

			$candidate = trim((string)$value);
			if ($candidate !== '') {
				return $candidate;
			}
		}

		return '';

	}//end referenceOf()

	/**
	 * The synchronization's human label, for names and messages.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return string The name, falling back to the reference.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function labelOf(array $synchronization): string {
		$name = trim((string)($synchronization['name'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return $this->referenceOf(synchronization: $synchronization);

	}//end labelOf()

	/**
	 * Split the overloaded `targetId` into its register and schema halves.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array{0: string, 1: string} The register and schema; both empty when unusable.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function targetPair(array $synchronization): array {
		$target = trim((string)($synchronization['targetId'] ?? ''));

		// One expression rather than three checks, so "two halves" and "neither
		// half is empty" cannot drift apart: a `register/schema` pair is exactly
		// two non-empty, slash-free halves.
		$halves = [];
		if (preg_match('#^([^/]+)/([^/]+)$#', $target, $halves) !== 1) {
			return ['', ''];
		}

		return [$halves[1], $halves[2]];

	}//end targetPair()
}//end class
