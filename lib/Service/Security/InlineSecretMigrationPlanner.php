<?php

/**
 * Integriq Inline Secret Migration Planner.
 *
 * Phase C of ocon#151 / ADR-064: plans the migration of a `source` object's
 * INLINE credential fields (`apikey`, `secret`, `password`, `jwt`,
 * `authenticationConfig`) into the OpenRegister credential broker, so the
 * source stores only a `{credentialRef: {credentialId: <uuid>}}` placeholder
 * and the secret bytes live in Doriath.
 *
 * THIS CLASS IS READ-ONLY BY CONSTRUCTION. It never mints, never writes, and
 * never nulls an inline value. It answers exactly two questions:
 *
 *   1. "What WOULD migrate?" — the per-source / per-field plan behind
 *      `occ integriq:migrate-inline-secrets --dry-run`.
 *   2. "Are there ZERO unmigrated inline secrets left?" — the machine-readable
 *      gate Phase D must pass before it may remove the schema properties.
 *
 * The executing half (mint → verify → write ref → null inline) is deliberately
 * NOT implemented here: see the BLOCKER note on {@see planAll()}. Shipping the
 * planner without the executor is intentional — the plan is the artefact a
 * human needs in order to decide, and it carries zero risk to live credentials.
 *
 * SECRET HYGIENE: no method on this class returns, logs, or embeds a secret
 * VALUE. The plan carries field NAMES, provider ids and booleans only. See
 * {@see describeValue()} — the one place a secret is inspected, and it emits
 * only a classification.
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
use Throwable;

/**
 * Plans (never executes) the inline-secret → credentialRef migration.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
 */
class InlineSecretMigrationPlanner {

	/**
	 * The OpenRegister register slug holding integriq's objects.
	 *
	 * @var string
	 */
	// Frozen on the old id: this is the OpenRegister REGISTER SLUG, not the app id.
	// OpenRegister matches registers by slug; renaming it orphans every stored object.
	public const REGISTER = 'integriq';

	/**
	 * The OpenRegister schema slug for source objects.
	 *
	 * @var string
	 */
	public const SCHEMA = 'source';

	/**
	 * The app id the broker authorises against a credential's allowedApps.
	 *
	 * Guard 2 of `CredentialBrokerService::resolveInjectable()` does a strict
	 * `in_array($appId, $allowedApps, true)`, so a minted credential MUST carry
	 * this exact string or it fails closed at call time.
	 *
	 * @var string
	 */
	// Frozen on the old id: this is NOT this app's Nextcloud app id but the identity
	// OpenRegister's credential broker matches against a stored credential's
	// `allowedApps` (strict in_array), so a minted credential MUST carry this exact
	// string. Renaming it fails every brokered resolve CLOSED. Moves only with a
	// credential re-provisioning pass.
	public const APP_ID = 'openconnector';

	/**
	 * Inline `source` secret fields, mapped to the inject-only broker provider
	 * whose `secret` semantics match the field.
	 *
	 * Verified against OpenRegister `origin/development`
	 * lib/Settings/credential-providers.json (v1.6.0). Only inject-only
	 * providers appear here: a source points at an arbitrary/self-hosted host,
	 * which carries no `baseUrl`/`allowRules` and therefore cannot be proxied —
	 * `request()` refuses it, so app-side injection is the only available mode
	 * (ADR-064 Rule 3).
	 *
	 * Mapping rationale, per provider `$comment` in the catalogue:
	 *  - `apikey`   → `generic-apikey`: "The secret is the bare API key."
	 *  - `password` → `generic-basic` : "The secret is the PASSWORD only; the
	 *                 username is non-sensitive and stays readable on the app's
	 *                 own config." So the username is NOT migrated.
	 *  - `secret`   → `generic-oauth2`: the schema titles this "OAuth client
	 *                 secret", and the provider's secret is exactly the
	 *                 `client_secret` (client_id / token URL / scope stay app-side).
	 *  - `jwt`      → `generic-bearer`: the schema titles this "JWT Token" — a
	 *                 pre-issued BEARER token, not a signing key. `generic-jwt`
	 *                 is deliberately NOT used: its secret is "the JWT signing
	 *                 secret", which the app uses to SIGN a token. Storing an
	 *                 already-issued token as a signing secret would mint a
	 *                 credential whose provider semantics are a lie.
	 *
	 * `authenticationConfig` is absent on purpose — see {@see UNMAPPABLE_FIELDS}.
	 *
	 * @var array<string, string>
	 */
	public const PROVIDER_MAP = [
		'apikey' => 'generic-apikey',
		'password' => 'generic-basic',
		'secret' => 'generic-oauth2',
		'jwt' => 'generic-bearer',
	];

	/**
	 * Inline secret fields that CANNOT be mapped to a single broker credential.
	 *
	 * `authenticationConfig` is `type: object` — a provider-specific bag that
	 * may hold a client_secret AND a username AND non-secret claims/algorithm
	 * at once. Every inject-only provider stores exactly ONE opaque secret
	 * string, so a faithful migration would have to decompose the bag
	 * per-provider-shape and leave the non-secret keys behind. Guessing that
	 * decomposition would silently corrupt credentials, so the planner reports
	 * these for manual review instead of inventing a mapping.
	 *
	 * @var array<int, string>
	 */
	public const UNMAPPABLE_FIELDS = ['authenticationConfig'];

	/**
	 * Every inline secret field the `source` schema marks `writeOnly: true`.
	 *
	 * Mirrors lib/Settings/register.d/99-source-secrets-writeonly.json.
	 *
	 * @var array<int, string>
	 */
	public const SECRET_FIELDS = ['apikey', 'secret', 'password', 'jwt', 'authenticationConfig'];

	/**
	 * Every schema this migration knows how to move, and how.
	 *
	 * `source` repeats the constants above rather than replacing them: they are
	 * public, `RenderBoundarySimulatingObjectService` and `AuthenticationConfigAuditor`
	 * read them, and changing their meaning would change behaviour for credentials
	 * already in production. The map is the generalisation; the constants remain
	 * the `source` answer.
	 *
	 * `refField` is where the `{credentialRef}` placeholder is written:
	 *   - null  → in place, over the secret itself (what `source` has always done)
	 *   - map   → into a SEPARATE readable property, the original then emptied
	 *
	 * That asymmetry is the point of the sender_identity enrolment. The reference
	 * has to stay readable — an operator must be able to see which credential an
	 * identity points at — while the key itself becomes write-only.
	 *
	 * @var array<string, array{fields: array<int,string>, providers: array<string,string>,
	 *     unmappable: array<int,string>, refField: array<string,string>|null}>
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public const MIGRATABLE = [
		self::SCHEMA => [
			'fields' => self::SECRET_FIELDS,
			'providers' => self::PROVIDER_MAP,
			'unmappable' => self::UNMAPPABLE_FIELDS,
			'refField' => null,
		],
		'sender_identity' => [
			'fields' => ['smimePrivateKey'],
			// `generic-apikey` is a deliberate interim: it is `inject_only`, so the
			// app reads the material back and signs locally, but it describes an
			// API key rather than key material. See ConductionNL/openregister#4008.
			'providers' => ['smimePrivateKey' => 'generic-apikey'],
			'unmappable' => [],
			'refField' => ['smimePrivateKey' => 'smimePrivateKeyRef'],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 * @param LoggerInterface $logger Secret-free logging only.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Read a source's RAW, unrendered data — the only read that still carries secrets.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * THE TRAP THIS METHOD EXISTS TO AVOID (ocon#215, openregister#389/#429)
	 * ─────────────────────────────────────────────────────────────────────────
	 * `source`'s credential fields are `writeOnly: true`. OpenRegister's render
	 * boundary strips `writeOnly` properties UNCONDITIONALLY — including for
	 * admins, including `_rbac: false`, and including the `@self.relations`
	 * mirror. Verified at OR `origin/development`
	 * lib/Service/Object/RenderObject.php::renderEntity():
	 *
	 *     $doWriteOnly = ($schema !== null && $schema->hasWriteOnlyProperties() === true);
	 *     if ($doWriteOnly === true || $doAuthz === true) { ... }
	 *
	 * `$doWriteOnly` is gated on NOTHING but the schema. `_rbac: false` and
	 * `SystemOperationContext` gate `$doAuthz` ONLY. So a migration that reads
	 * through the render path sees NO secrets, concludes "nothing to migrate",
	 * reports success — and Phase D then drops the columns, destroying every
	 * credential in the fleet.
	 *
	 * `_render: false` is the sanctioned escape: ObjectService::find() returns
	 * the entity BEFORE renderEntity() is ever called, while retrieval, the
	 * permission check and AVG read-logging all still run:
	 *
	 *     if ($_render === false) {
	 *         $this->logProcessingRead(object: $object);
	 *         return $object;      // <- raw entity, secrets intact
	 *     }
	 *     ...
	 *     return $this->renderHandler->renderEntity(...);
	 *
	 * DO NOT "simplify" this call by dropping `_render: false`. Do not reach for
	 * `ObjectService::getMapper()` either: it returns an ObjectServiceMapperAdapter
	 * that delegates straight back to ObjectService::find() WITH rendering on —
	 * it looks like a raw mapper and is not one.
	 *
	 * @param string $uuid The source object's UUID.
	 * @param string $schema The schema to read it from; defaults to `source`.
	 *
	 * @return array<string, mixed> The raw source data (secrets intact), or [] when unreadable.
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-raw-secret-read
	 */
	public function readRawSource(string $uuid, string $schema = self::SCHEMA): array {
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Throwable $e) {
			// Secret-free: the uuid is not a secret, the exception text may not
			// be quoted verbatim in case an upstream ever interpolates data.
			$this->logger->warning(
				'[integriq] inline-secret planner: raw source read failed',
				['uuid' => $uuid, 'errorClass' => get_class($e)]
			);
			return [];
		}

		if ($entity instanceof ObjectEntity === false) {
			return [];
		}

		return $entity->getObject();
	}//end readRawSource()

	/**
	 * Classify a single inline field WITHOUT revealing its value.
	 *
	 * @param string $field The field name.
	 * @param mixed $value The raw field value (never returned, never logged).
	 * @param string $schema The schema whose field map applies; defaults to `source`.
	 *
	 * @return array{field: string, state: string, provider: string|null}
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
	 */
	private function describeValue(string $field, mixed $value, string $schema = self::SCHEMA): array {
		// Already a `{credentialRef: {...}}` placeholder → nothing to do. Matches
		// BrokeredCallService::isPlaceholder(): credentialRef must be the SOLE key.
		if (is_array($value) === true && array_keys($value) === ['credentialRef']) {
			return ['field' => $field, 'state' => 'already-migrated', 'provider' => null];
		}

		// Empty / null → nothing to migrate. An empty string is not a secret.
		if ($value === null || $value === '' || $value === []) {
			return ['field' => $field, 'state' => 'empty', 'provider' => null];
		}

		$unmappable = (self::MIGRATABLE[$schema]['unmappable'] ?? self::UNMAPPABLE_FIELDS);
		if (in_array($field, $unmappable, true) === true) {
			return ['field' => $field, 'state' => 'needs-manual-review', 'provider' => null];
		}

		$providers = (self::MIGRATABLE[$schema]['providers'] ?? self::PROVIDER_MAP);
		$provider = ($providers[$field] ?? null);
		if ($provider === null) {
			return ['field' => $field, 'state' => 'needs-manual-review', 'provider' => null];
		}

		return ['field' => $field, 'state' => 'would-migrate', 'provider' => $provider];
	}//end describeValue()

	/**
	 * Plan one source's inline-secret migration from its RAW data.
	 *
	 * @param string $uuid The source UUID.
	 * @param string $name The source's human-readable name (not a secret).
	 * @param array<string, mixed> $rawData The RAW source data (from {@see readRawSource()}).
	 * @param string $schema The schema being planned; defaults to `source`.
	 *
	 * @return array{uuid: string, name: string, fields: array<int, array{field: string,
	 *     state: string, provider: string|null}>, wouldMigrate: int, needsReview: int}
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
	 */
	public function planSource(string $uuid, string $name, array $rawData, string $schema = self::SCHEMA): array {
		$fields = [];
		$wouldMigrate = 0;
		$needsReview = 0;

		$declared = (self::MIGRATABLE[$schema]['fields'] ?? self::SECRET_FIELDS);
		foreach ($declared as $field) {
			if (array_key_exists($field, $rawData) === false) {
				continue;
			}

			$described = $this->describeValue(field: $field, value: $rawData[$field], schema: $schema);
			if ($described['state'] === 'empty') {
				continue;
			}

			if ($described['state'] === 'would-migrate') {
				$wouldMigrate++;
			}

			if ($described['state'] === 'needs-manual-review') {
				$needsReview++;
			}

			$fields[] = $described;
		}//end foreach

		return [
			'uuid' => $uuid,
			'name' => $name,
			'fields' => $fields,
			'wouldMigrate' => $wouldMigrate,
			'needsReview' => $needsReview,
		];
	}//end planSource()

	/**
	 * The fields one schema declares as migratable.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int,string> The field names, empty when the schema is unknown.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function fieldsFor(string $schema): array {
		return (self::MIGRATABLE[$schema]['fields'] ?? []);

	}//end fieldsFor()

	/**
	 * Where one schema's field writes its reference, if not in place.
	 *
	 * @param string $schema The schema slug.
	 * @param string $field The field name.
	 *
	 * @return string|null The separate reference property, or null to write in place.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function referenceFieldFor(string $schema, string $field): ?string {
		return (self::MIGRATABLE[$schema]['refField'][$field] ?? null);

	}//end referenceFieldFor()

	/**
	 * Plan one schema's whole estate.
	 *
	 * Separate from {@see planAll()} on purpose: planAll() is the `source` Phase D
	 * gate and {@see \OCA\Integriq\Repair\RemoveMigratedSourceSecretFields} reads
	 * it to decide whether SOURCE properties may be removed. Folding another
	 * schema into that number would block source's removal on an unrelated
	 * schema's state, which is not what the gate means.
	 *
	 * @param string $schema The schema slug.
	 * @param int $limit Maximum objects to inspect.
	 *
	 * @return array{schema: string, objects: array<int, array<string,mixed>>,
	 *     totalObjects: int, wouldMigrate: int, needsReview: int, clean: bool}
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function planSchema(string $schema, int $limit = 1000): array {
		$uuids = $this->listUuids(schema: $schema, limit: $limit);

		$plans = [];
		$wouldMigrate = 0;
		$needsReview = 0;

		foreach ($uuids as $uuid => $name) {
			// Per-object isolation, exactly as planAll(): one unreadable object
			// must never abort the batch or mask the rest of the estate.
			$rawData = $this->readRawSource(uuid: (string)$uuid, schema: $schema);
			if ($rawData === []) {
				continue;
			}

			$plan = $this->planSource(uuid: (string)$uuid, name: $name, rawData: $rawData, schema: $schema);
			if ($plan['fields'] === []) {
				continue;
			}

			$wouldMigrate += $plan['wouldMigrate'];
			$needsReview += $plan['needsReview'];
			$plans[] = $plan;
		}//end foreach

		return [
			'schema' => $schema,
			'objects' => $plans,
			'totalObjects' => count($uuids),
			'wouldMigrate' => $wouldMigrate,
			'needsReview' => $needsReview,
			'clean' => ($wouldMigrate === 0 && $needsReview === 0),
		];

	}//end planSchema()

	/**
	 * Plan every migratable schema.
	 *
	 * @param int $limit Maximum objects per schema.
	 *
	 * @return array{schemas: array<string, array<string,mixed>>, wouldMigrate: int,
	 *     needsReview: int, clean: bool} The estate, and whether ALL of it is clean.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function planEverything(int $limit = 1000): array {
		$schemas = [];
		$wouldMigrate = 0;
		$needsReview = 0;

		foreach (array_keys(self::MIGRATABLE) as $schema) {
			$plan = $this->planSchema(schema: $schema, limit: $limit);
			$schemas[$schema] = $plan;
			$wouldMigrate += $plan['wouldMigrate'];
			$needsReview += $plan['needsReview'];
		}

		return [
			'schemas' => $schemas,
			'wouldMigrate' => $wouldMigrate,
			'needsReview' => $needsReview,
			'clean' => ($wouldMigrate === 0 && $needsReview === 0),
		];

	}//end planEverything()

	/**
	 * Build the full fleet plan across every `source` object.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * WHY THERE IS NO EXECUTOR — THE ORGANISATION-SCOPE BLOCKER
	 * ─────────────────────────────────────────────────────────────────────────
	 * ADR-064 Rule 4 requires infrastructure credentials (a `source`'s) to be
	 * minted at `organisation` scope. At OpenRegister `origin/development` an
	 * organisation-scoped credential CANNOT be resolved without a live user
	 * session — `CredentialBrokerService::assertOrganisationMember()`:
	 *
	 *     if ($this->userSession->getUser() === null) {
	 *         $this->deny(reason: 'organisation credential requires a user session', ...);
	 *     }
	 *
	 * and the sessionless `actingUserId` fallback is DELIBERATELY not consulted
	 * on that branch (design D3). `SystemOperationContext` does not help: it
	 * bypasses RBAC only, never organisation membership.
	 *
	 * Two consequences, both disqualifying:
	 *   1. A repair step / occ run is sessionless, so the mandatory verify step
	 *      ("resolve it back and assert it round-trips") can never pass. Under
	 *      ADR-064 §6 we may not null an inline value without that proof, so an
	 *      organisation-scoped migration is a guaranteed no-op.
	 *   2. Worse at runtime: integriq's source calls are predominantly
	 *      sessionless background sync jobs. An organisation-scoped credential
	 *      would fail closed on every one of them — trading a stripped secret
	 *      for a broken integration.
	 *
	 * `personal` scope is the only scope that resolves sessionlessly (via
	 * `actingUserId`), but ADR-064 Rule 4 forbids it for infrastructure, and it
	 * couples the source's lifetime to one employee's account. Both options are
	 * wrong, so this planner reports and refuses rather than guessing. See the
	 * tracking issue referenced in the PR body.
	 *
	 * @param int $limit Maximum number of sources to inspect per page.
	 *
	 * @return array{sources: array<int, array<string, mixed>>, totalSources: int, wouldMigrate: int, needsReview: int, clean: bool}
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
	 */
	public function planAll(int $limit = 1000): array {
		$uuids = $this->listSourceUuids(limit: $limit);

		$plans = [];
		$wouldMigrate = 0;
		$needsReview = 0;

		foreach ($uuids as $uuid => $name) {
			// Per-source isolation: one unreadable source must never abort the
			// batch or mask the rest of the fleet's state.
			$rawData = $this->readRawSource(uuid: (string)$uuid);
			if ($rawData === []) {
				continue;
			}

			$plan = $this->planSource(uuid: (string)$uuid, name: $name, rawData: $rawData);
			if ($plan['fields'] === []) {
				continue;
			}

			$wouldMigrate += $plan['wouldMigrate'];
			$needsReview += $plan['needsReview'];
			$plans[] = $plan;
		}//end foreach

		return [
			'sources' => $plans,
			'totalSources' => count($uuids),
			'wouldMigrate' => $wouldMigrate,
			'needsReview' => $needsReview,
			// THE PHASE D GATE. True only when no source holds an unmigrated
			// inline secret in any field — including the ones we cannot map.
			'clean' => ($wouldMigrate === 0 && $needsReview === 0),
		];
	}//end planAll()

	/**
	 * List every source's uuid => name.
	 *
	 * Deliberately uses the RENDERED findAll(): this call needs identity only
	 * (uuid + name), never a secret, and the per-source raw read happens in
	 * {@see readRawSource()}. Reading identity through the render boundary keeps
	 * the raw-secret surface as small as possible — one object at a time.
	 *
	 * Public so {@see AuthenticationConfigAuditor} can enumerate the SAME fleet
	 * through the SAME listing rather than re-implementing it (ocon#232).
	 *
	 * @param int $limit Maximum number of sources to list.
	 *
	 * @return array<string, string> uuid => name.
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
	 */
	public function listSourceUuids(int $limit): array {
		return $this->listUuids(schema: self::SCHEMA, limit: $limit);

	}//end listSourceUuids()

	/**
	 * List one schema's uuid => name.
	 *
	 * The generic form of {@see listSourceUuids()}, which stays as the `source`
	 * entry point because {@see \OCA\Integriq\Service\Security\AuthenticationConfigAuditor}
	 * enumerates the fleet through it.
	 *
	 * Deliberately uses the RENDERED findAll(): this call needs identity only
	 * (uuid + name), never a secret. The per-object raw read happens in
	 * {@see readRawSource()}, one object at a time.
	 *
	 * @param string $schema The schema slug.
	 * @param int $limit Maximum number of objects to list.
	 *
	 * @return array<string, string> uuid => name.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function listUuids(string $schema, int $limit): array {
		try {
			$result = $this->objectService->findAll(
				config: [
					'limit' => $limit,
					'filters' => ['register' => self::REGISTER, 'schema' => $schema],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[integriq] inline-secret planner: source listing failed',
				['errorClass' => get_class($e)]
			);
			return [];
		}

		$items = ($result['results'] ?? $result);
		if (is_array($items) === false) {
			return [];
		}

		$uuids = [];
		foreach ($items as $item) {
			if ($item instanceof ObjectEntity === false) {
				continue;
			}

			$uuid = (string)$item->getUuid();
			if ($uuid === '') {
				continue;
			}

			$data = $item->getObject();
			$uuids[$uuid] = (string)($data['name'] ?? $uuid);
		}

		return $uuids;
	}//end listSourceUuids()
}//end class
