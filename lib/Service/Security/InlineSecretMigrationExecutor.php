<?php

/**
 * Integriq Inline Secret Migration Executor.
 *
 * Phase C (executor half) of ocon#151 / ADR-064 §6. Where
 * {@see InlineSecretMigrationPlanner} only REPORTS what would migrate, this
 * class actually REWRITES LIVE CREDENTIALS: for every `source` object that
 * still holds an INLINE secret (`apikey`, `secret`, `password`, `jwt`) it mints
 * an OpenRegister broker credential, VERIFIES the secret round-trips, and only
 * then writes the `{credentialRef}` placeholder and nulls the inline value.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS NOW (the Phase C blocker is resolved)
 * ─────────────────────────────────────────────────────────────────────────────
 * Phase C originally shipped WITHOUT an executor: ADR-064 Rule 4 requires a
 * source's credential to be `organisation`-scoped, and at the time an
 * organisation-scoped credential could not be resolved without a live user
 * session — so the mandatory verify step could never pass sessionlessly.
 * openregister#450 added the sessionless `actingOrganisationId` assertion to
 * {@see CredentialBrokerService::resolveInjectable()}, and or#440 added the
 * sessionless {@see CredentialBrokerService::mint()}. The organisation guard now
 * admits a trusted in-process caller iff the asserted `actingOrganisationId`
 * equals the credential's organisation. That is exactly this migration's
 * context (OR's own mint() docblock names "an openconnector migration repair
 * step folding an inline plaintext source secret into the broker"), so the
 * executor is now implementable — and safe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SAFETY CONTRACT (ADR-064 §6 — every line is load-bearing)
 * ─────────────────────────────────────────────────────────────────────────────
 *  - VERIFY BEFORE NULL. A field's inline value is nulled ONLY after the minted
 *    credential resolves back to a byte-for-byte identical secret. A mint
 *    failure, a verify mismatch, a verify exception, or a save failure all leave
 *    the inline value COMPLETELY INTACT — the source keeps working exactly as
 *    before.
 *  - PER-SOURCE AND PER-FIELD ISOLATION. One field's failure never aborts the
 *    batch and never half-writes another field or source. Each field is minted,
 *    verified and saved on its own working copy, committed only on success.
 *  - ORGANISATION-SCOPED, NEVER PERSONAL. A source with no organisation is
 *    BLOCKED (never minted at `personal` scope — that would couple infrastructure
 *    to one employee and mask the missing organisation), keeping the Phase D gate
 *    closed until a human fixes it.
 *  - IDEMPOTENT. Fields already carrying a `{credentialRef}` placeholder are
 *    skipped; a second run is a no-op. Every classification is re-derived from a
 *    FRESH raw read, so concurrent progress is respected.
 *  - FAIL CLOSED ON AN OLD BROKER. If the installed OpenRegister lacks
 *    {@see CredentialBrokerService::mint()} or the 4-argument
 *    {@see CredentialBrokerService::resolveInjectable()}, the run refuses with an
 *    upgrade hint and rewrites nothing — it NEVER leaves plaintext silently.
 *  - SECRETS NEVER LOGGED. No message, context array, or exception text ever
 *    carries a secret value.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Executes (mint → verify → write ref → null) the inline-secret → credentialRef migration.
 *
 * The class-length / complexity suppressions mirror the deliberately exhaustive,
 * single-purpose guard chain: each outcome (migrated / failed-* / blocked-* /
 * skipped-*) is its own small branch, and splitting the fail-closed flow across
 * classes would fragment the one write path without reducing real complexity.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
 */
class InlineSecretMigrationExecutor {

	/**
	 * The app id the broker authorises against a credential's allowedApps, and
	 * the caller id asserted on the in-process resolveInjectable path.
	 *
	 * @var string
	 */
	// Frozen on the old id: this is NOT this app's Nextcloud app id but the identity
	// OpenRegister's credential broker matches against a stored credential's
	// `allowedApps` (strict in_array). Every credential minted so far carries
	// "openconnector"; renaming it fails every brokered resolve CLOSED.
	// Moves only with a credential re-provisioning pass.
	public const APP_ID = 'openconnector';

	/**
	 * FQCN of the OpenRegister credential broker, resolved lazily — the class
	 * only exists on OpenRegister versions that ship the credential broker.
	 *
	 * @var string
	 */
	public const BROKER_CLASS = 'OCA\OpenRegister\Service\Credential\CredentialBrokerService';

	/**
	 * The mint scope for a source credential: infrastructure, never one
	 * employee's property (ADR-064 Rule 4).
	 *
	 * @var string
	 */
	public const SCOPE_ORGANISATION = 'organisation';

	/**
	 * The broker parameter name that makes sessionless organisation resolution
	 * work (openregister#450). Feature-detected by reflection so an older broker
	 * fails closed with an upgrade hint instead of a TypeError.
	 *
	 * @var string
	 */
	private const ORG_RESOLUTION_PARAMETER = 'actingOrganisationId';

	/**
	 * Lazily resolved broker instance (per-process memo).
	 *
	 * @var object|null
	 */
	private ?object $broker = null;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service (raw reads + source saves).
	 * @param InlineSecretMigrationPlanner $planner The read-safe planner reused for classification + the post-run gate.
	 * @param LoggerInterface $logger Secret-free logging only.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly InlineSecretMigrationPlanner $planner,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Migrate every source's inline secrets, then re-report the true Phase D gate.
	 *
	 * Fails closed BEFORE touching any source when the installed broker cannot
	 * mint or cannot resolve organisation-scoped credentials sessionlessly.
	 *
	 * @param int $limit Maximum number of sources to inspect.
	 *
	 * @return array{sources: array<int, array<string, mixed>>, totalSources: int, migrated: int, failed: int,
	 *     blocked: int, skipped: int, postRun: array{clean: bool, pending: int, manual: int}}
	 *
	 * @throws RuntimeException When the broker is unavailable or too old to migrate safely (nothing is rewritten).
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	public function migrateAll(int $limit = 1000): array {
		$broker = $this->assertBrokerCapable();

		$prePlan = $this->planner->planAll(limit: $limit);

		$sources = [];
		$totals = [
			'migrated' => 0,
			'failed' => 0,
			'blocked' => 0,
			'skipped' => 0,
		];

		foreach ((array)$prePlan['sources'] as $planned) {
			$uuid = (string)($planned['uuid'] ?? '');
			$name = (string)($planned['name'] ?? $uuid);
			if ($uuid === '') {
				continue;
			}

			// Per-source isolation: a single source's failure must never abort the batch.
			$result = $this->migrateSource(broker: $broker, uuid: $uuid, name: $name);

			$sources[] = $result['record'];
			$totals['migrated'] += $result['migrated'];
			$totals['failed'] += $result['failed'];
			$totals['blocked'] += $result['blocked'];
			$totals['skipped'] += $result['skipped'];
		}//end foreach

		// Re-plan from FRESH raw reads so the Phase D gate reflects the true
		// post-run state — a field we could not migrate keeps it closed.
		$postPlan = $this->planner->planAll(limit: $limit);

		return [
			'sources' => $sources,
			'totalSources' => (int)$prePlan['totalSources'],
			'migrated' => $totals['migrated'],
			'failed' => $totals['failed'],
			'blocked' => $totals['blocked'],
			'skipped' => $totals['skipped'],
			'postRun' => [
				'clean' => (bool)$postPlan['clean'],
				'pending' => (int)$postPlan['wouldMigrate'],
				'manual' => (int)$postPlan['needsReview'],
			],
		];
	}//end migrateAll()

	/**
	 * Migrate one source. Reads the ENTITY raw (for organisation + owner, which
	 * live as columns and are NOT in getObject()) and re-classifies from the
	 * fresh raw data before touching any field.
	 *
	 * @param object $broker The resolved credential broker.
	 * @param string $uuid The source UUID.
	 * @param string $name The source's human-readable name (not a secret).
	 *
	 * @return array{record: array<string, mixed>, migrated: int, failed: int, blocked: int, skipped: int}
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function migrateSource(object $broker, string $uuid, string $name): array {
		$entity = $this->readRawEntity(uuid: $uuid);
		if ($entity instanceof ObjectEntity === false) {
			// Fail closed for the whole source; the post-run plan keeps the gate
			// closed while its secret survives. Secret-free by construction.
			$this->logger->warning(
				'[integriq] inline-secret executor: raw source read failed; source left untouched',
				['uuid' => $uuid]
			);
			return [
				'record' => ['uuid' => $uuid, 'name' => $name, 'organisation' => null, 'fields' => [], 'readFailed' => true],
				'migrated' => 0,
				'failed' => 0,
				'blocked' => 0,
				'skipped' => 0,
			];
		}

		$organisation = trim((string)($entity->getOrganisation() ?? ''));
		$owner = trim((string)($entity->getOwner() ?? ''));
		$rawData = $entity->getObject();

		// Re-classify from the fresh raw read: this is what makes the executor
		// idempotent (a concurrently-migrated field now reads as a placeholder).
		$freshPlan = $this->planner->planSource(uuid: $uuid, name: $name, rawData: $rawData);

		$working = $rawData;
		$outcomes = [];
		$counts = [
			'migrated' => 0,
			'failed' => 0,
			'blocked' => 0,
			'skipped' => 0,
		];

		foreach ((array)$freshPlan['fields'] as $classified) {
			$field = (string)$classified['field'];
			$state = (string)$classified['state'];
			$provider = ($classified['provider'] ?? null);

			$outcome = $this->migrateField(
				broker: $broker,
				uuid: $uuid,
				name: $name,
				field: $field,
				state: $state,
				provider: $provider,
				organisation: $organisation,
				owner: $owner,
				working: $working
			);

			$outcomes[] = $outcome['record'];
			$counts[$outcome['bucket']] += 1;
		}//end foreach

		$organisationOut = $organisation;
		if ($organisation === '') {
			$organisationOut = null;
		}

		return [
			'record' => [
				'uuid' => $uuid,
				'name' => $name,
				'organisation' => $organisationOut,
				'fields' => $outcomes,
			],
			'migrated' => $counts['migrated'],
			'failed' => $counts['failed'],
			'blocked' => $counts['blocked'],
			'skipped' => $counts['skipped'],
		];
	}//end migrateSource()

	/**
	 * Classify-and-dispatch a single field: skip, block, or run mint→verify→null.
	 *
	 * `$working` is the source's in-progress data; on a successful migration it is
	 * mutated IN PLACE (the ref written, the inline value nulled) — but only after
	 * the save has persisted, so a half-written field never leaks into a later
	 * field's save.
	 *
	 * @param object $broker The resolved credential broker.
	 * @param string $uuid The source UUID.
	 * @param string $name The source name (label only, never a secret).
	 * @param string $field The inline secret field name.
	 * @param string $state The planner classification for this field.
	 * @param string|null $provider The mapped broker provider (null unless would-migrate).
	 * @param string $organisation The source's organisation UUID (may be empty).
	 * @param string $owner The source's owner UID.
	 * @param array<string, mixed> $working The source's in-progress data (mutated on success only).
	 *
	 * @return array{record: array<string, mixed>, bucket: string}
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function migrateField(
		object $broker,
		string $uuid,
		string $name,
		string $field,
		string $state,
		?string $provider,
		string $organisation,
		string $owner,
		array &$working,
	): array {
		if ($state === 'already-migrated') {
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'skipped', reason: 'already-migrated', bucket: 'skipped');
		}

		// `authenticationConfig` (and any other unmappable field) is a multi-value
		// bag no single inject-only provider can hold — a human must split it.
		if ($state === 'needs-manual-review') {
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'blocked', reason: 'needs-manual-review', bucket: 'blocked');
		}

		if ($state !== 'would-migrate' || $provider === null) {
			// Defensive: the planner already drops `empty`; anything else is a no-op.
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'skipped', reason: 'nothing-to-do', bucket: 'skipped');
		}

		// ADR-064 Rule 4: NEVER mint infrastructure at personal scope. No
		// organisation ⇒ block, keeping the Phase D gate closed for a human.
		if ($organisation === '') {
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'blocked', reason: 'no-organisation', bucket: 'blocked');
		}

		$secret = ($working[$field] ?? null);
		if (is_string($secret) === false || $secret === '') {
			// The fresh read no longer carries a plaintext secret here.
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'skipped', reason: 'empty-on-reread', bucket: 'skipped');
		}

		return $this->mintVerifyNull(
			broker: $broker,
			uuid: $uuid,
			name: $name,
			field: $field,
			provider: $provider,
			secret: $secret,
			organisation: $organisation,
			owner: $owner,
			working: $working
		);
	}//end migrateField()

	/**
	 * The safety-critical core: mint → VERIFY → (only then) write ref + null + save.
	 *
	 * The ordering is the whole contract. The inline value is nulled ONLY inside
	 * the `$verified === $secret` branch, and ONLY after the save persists. Break
	 * that ordering (null before/without verify) and the mutation test fails.
	 *
	 * @param object $broker The resolved credential broker.
	 * @param string $uuid The source UUID.
	 * @param string $name The source name (label only).
	 * @param string $field The inline secret field name.
	 * @param string $provider The mapped inject-only broker provider.
	 * @param string $secret The live inline secret (never logged).
	 * @param string $organisation The source's organisation UUID.
	 * @param string $owner The source's owner UID.
	 * @param array<string, mixed> $working The source's in-progress data (mutated only on a persisted success).
	 *
	 * @return array{record: array<string, mixed>, bucket: string}
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function mintVerifyNull(
		object $broker,
		string $uuid,
		string $name,
		string $field,
		string $provider,
		string $secret,
		string $organisation,
		string $owner,
		array &$working,
	): array {
		// 1) MINT — organisation-scoped, allowedApps pinned so Guard 2 admits us.
		try {
			// Positional args: $broker is only known to expose mint() — its parameter
			// names cannot be verified statically (duck-typed cross-app class).
			$credential = $broker->mint(
				$name . ' — ' . $field,
				$provider,
				$owner,
				[self::APP_ID],
				$secret,
				self::SCOPE_ORGANISATION,
				$organisation
			);
		} catch (Throwable $e) {
			$this->logFailure(step: 'mint', uuid: $uuid, field: $field, provider: $provider, e: $e);
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'failed', reason: 'mint-failed', bucket: 'failed');
		}

		$credentialId = '';
		if ($credential instanceof ObjectEntity === true) {
			$credentialId = (string)$credential->getUuid();
		}

		if ($credentialId === '') {
			$this->logFailure(step: 'mint-no-uuid', uuid: $uuid, field: $field, provider: $provider, e: null);
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'failed', reason: 'mint-no-uuid', bucket: 'failed');
		}

		// 2) VERIFY — resolve it back sessionlessly with the source's organisation
		// asserted as actingOrganisationId (the whole point of openregister#450).
		try {
			$verified = $broker->resolveInjectable($credentialId, self::APP_ID, null, $organisation);
		} catch (Throwable $e) {
			$this->logFailure(step: 'verify', uuid: $uuid, field: $field, provider: $provider, e: $e);
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'failed', reason: 'verify-exception', bucket: 'failed');
		}

		// 3) The round-trip MUST match byte-for-byte, or we do NOT null anything.
		if (is_string($verified) === false || hash_equals($secret, $verified) === false) {
			$this->logFailure(step: 'verify-mismatch', uuid: $uuid, field: $field, provider: $provider, e: null);
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'failed', reason: 'verify-mismatch', bucket: 'failed');
		}

		// 4) VERIFIED — write the nested ref + null the inline value on a WORKING
		// COPY, then persist. Only a successful save commits the mutation.
		$candidate = $this->applyMigration(data: $working, field: $field, credentialId: $credentialId);

		try {
			$this->objectService->saveObject(
				object: $candidate,
				register: InlineSecretMigrationPlanner::REGISTER,
				schema: InlineSecretMigrationPlanner::SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// Save failed ⇒ the inline value is still persisted intact (the source
			// keeps working); the minted credential is left resolvable for a retry.
			$this->logFailure(step: 'save', uuid: $uuid, field: $field, provider: $provider, e: $e);
			return $this->fieldRecord(field: $field, provider: $provider, outcome: 'failed', reason: 'save-failed', bucket: 'failed');
		}

		// Commit the working copy only now that the save has persisted.
		$working = $candidate;

		return $this->fieldRecord(
			field: $field,
			provider: $provider,
			outcome: 'migrated',
			reason: 'migrated',
			bucket: 'migrated',
			credentialId: $credentialId
		);
	}//end mintVerifyNull()

	/**
	 * Write the nested `{credentialRef}` placeholder and null the inline value.
	 *
	 * The ref goes to the NESTED `configuration.authentication.<field>` (the
	 * app-side injection position BrokeredCallService hydrates), NOT the top-level
	 * proxy `authentication.credentialRef`. The inline value is set to null
	 * explicitly (never merely unset) so a merge-based save still overwrites the
	 * live secret.
	 *
	 * @param array<string, mixed> $data The source data to copy-and-mutate.
	 * @param string $field The inline secret field name.
	 * @param string $credentialId The minted credential UUID (the credentialRef).
	 *
	 * @return array<string, mixed> A new data array with the ref written and the inline value nulled.
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function applyMigration(array $data, string $field, string $credentialId): array {
		$configuration = ($data['configuration'] ?? []);
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		$authentication = ($configuration['authentication'] ?? []);
		if (is_array($authentication) === false) {
			$authentication = [];
		}

		// Exactly `{"credentialRef": {"credentialId": "<uuid>"}}` — the sole key,
		// matching BrokeredCallService::isPlaceholder()/validateInnerRef().
		$authentication[$field] = ['credentialRef' => ['credentialId' => $credentialId]];
		$configuration['authentication'] = $authentication;
		$data['configuration'] = $configuration;

		// Null (not unset) the top-level inline secret so a merge-save cannot
		// silently retain the old plaintext.
		$data[$field] = null;

		return $data;
	}//end applyMigration()

	/**
	 * Read a source's raw ENTITY (secrets + organisation/owner columns intact).
	 *
	 * Uses `_render: false` — the ONLY read that survives the writeOnly render
	 * boundary (see {@see InlineSecretMigrationPlanner::readRawSource()}) — and
	 * returns the ENTITY (not just getObject()) because `organisation` and `owner`
	 * are entity columns, absent from the object data.
	 *
	 * @param string $uuid The source UUID.
	 *
	 * @return ObjectEntity|null The raw entity, or null when unreadable.
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function readRawEntity(string $uuid): ?ObjectEntity {
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: InlineSecretMigrationPlanner::REGISTER,
				schema: InlineSecretMigrationPlanner::SCHEMA,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[integriq] inline-secret executor: raw entity read threw',
				['uuid' => $uuid, 'errorClass' => get_class($e)]
			);
			return null;
		}

		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		return $entity;
	}//end readRawEntity()

	/**
	 * Assert the installed broker can mint AND resolve organisation-scoped
	 * credentials sessionlessly; otherwise refuse the whole run (fail closed).
	 *
	 * @return object The resolved, capable broker.
	 *
	 * @throws RuntimeException When the broker is unavailable or too old (nothing is rewritten).
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function assertBrokerCapable(): object {
		if ($this->isBrokerClassAvailable() === false) {
			throw new RuntimeException(
				message: 'The OpenRegister credential broker is unavailable. Enable the openregister app on a '
					. 'version that ships CredentialBrokerService before migrating inline secrets. '
					. 'Nothing was rewritten.'
			);
		}

		$broker = $this->resolveBroker();

		if (method_exists($broker, 'mint') === false) {
			throw new RuntimeException(
				message: 'The installed OpenRegister credential broker cannot mint credentials '
					. '(CredentialBrokerService::mint() is missing). Upgrade OpenRegister to a version that ships '
					. 'sessionless minting (openregister#440) before migrating. Nothing was rewritten.'
			);
		}

		if ($this->brokerSupportsOrganisationResolution(broker: $broker) === false) {
			throw new RuntimeException(
				message: 'The installed OpenRegister credential broker cannot resolve organisation-scoped '
					. 'credentials sessionlessly (CredentialBrokerService::resolveInjectable() lacks the '
					. self::ORG_RESOLUTION_PARAMETER . ' parameter). Upgrade OpenRegister to a version that ships '
					. 'openregister#450 before migrating — an organisation-scoped mint could not be verified '
					. 'without it, and this migration never nulls an inline secret it cannot verify. Nothing was '
					. 'rewritten.'
			);
		}

		return $broker;
	}//end assertBrokerCapable()

	/**
	 * Feature-detect the 4-argument resolveInjectable (openregister#450) by reflection.
	 *
	 * @param object $broker The resolved broker instance.
	 *
	 * @return bool Whether resolveInjectable() accepts the actingOrganisationId parameter.
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function brokerSupportsOrganisationResolution(object $broker): bool {
		if (method_exists($broker, 'resolveInjectable') === false) {
			return false;
		}

		try {
			$method = new ReflectionMethod($broker, 'resolveInjectable');
		} catch (ReflectionException) {
			return false;
		}

		foreach ($method->getParameters() as $parameter) {
			if ($parameter->getName() === self::ORG_RESOLUTION_PARAMETER) {
				return true;
			}
		}

		return false;
	}//end brokerSupportsOrganisationResolution()

	/**
	 * Whether the broker class is loadable (protected seam for tests).
	 *
	 * @return bool Whether the OpenRegister credential broker class exists.
	 */
	protected function isBrokerClassAvailable(): bool {
		return class_exists(self::BROKER_CLASS);
	}//end isBrokerClassAvailable()

	/**
	 * Resolve the broker instance from the server container (protected seam for tests).
	 *
	 * @return object The CredentialBrokerService instance.
	 *
	 * @throws RuntimeException When the container cannot resolve the broker.
	 *
	 * @spec exclude Container-resolution seam — lazy cross-app service lookup, no domain behavior (overridden in tests).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) -- \OCP\Server is the only way to
	 * lazily resolve a cross-app class that may not exist at DI wiring time.
	 */
	protected function resolveBroker(): object {
		if ($this->broker !== null) {
			return $this->broker;
		}

		try {
			$this->broker = \OCP\Server::get(self::BROKER_CLASS);
		} catch (Throwable $e) {
			throw new RuntimeException(
				message: 'The OpenRegister credential broker could not be resolved: ' . $e->getMessage()
					. '. Nothing was rewritten.'
			);
		}

		return $this->broker;
	}//end resolveBroker()

	/**
	 * Log a per-field failure — step name + identity only, NEVER a secret.
	 *
	 * @param string $step The failing step (mint / verify / verify-mismatch / save / ...).
	 * @param string $uuid The source UUID (not a secret).
	 * @param string $field The field name (not a secret).
	 * @param string $provider The provider id (not a secret).
	 * @param \Throwable|null $e The optional cause (only its CLASS is logged, never its message).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function logFailure(string $step, string $uuid, string $field, string $provider, ?Throwable $e): void {
		$context = [
			'uuid' => $uuid,
			'field' => $field,
			'provider' => $provider,
			'step' => $step,
		];
		if ($e !== null) {
			// Only the exception CLASS — its message could interpolate a secret.
			$context['errorClass'] = get_class($e);
		}

		$this->logger->warning('[integriq] inline-secret executor: field left intact after failure', $context);
	}//end logFailure()

	/**
	 * Build one per-field outcome record + its counter bucket.
	 *
	 * @param string $field The field name.
	 * @param string|null $provider The provider id (or null).
	 * @param string $outcome The public outcome (migrated / failed / blocked / skipped).
	 * @param string $reason The machine reason code.
	 * @param string $bucket The counter bucket (migrated / failed / blocked / skipped).
	 * @param string|null $credentialId The minted credential id on success (never a secret).
	 *
	 * @return array{record: array<string, mixed>, bucket: string}
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	private function fieldRecord(
		string $field,
		?string $provider,
		string $outcome,
		string $reason,
		string $bucket,
		?string $credentialId = null,
	): array {
		return [
			'record' => [
				'field' => $field,
				'provider' => $provider,
				'outcome' => $outcome,
				'reason' => $reason,
				'credentialId' => $credentialId,
			],
			'bucket' => $bucket,
		];
	}//end fieldRecord()
}//end class
