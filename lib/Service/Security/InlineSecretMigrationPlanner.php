<?php
/**
 * OpenConnector Inline Secret Migration Planner.
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
 *      `occ openconnector:migrate-inline-secrets --dry-run`.
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
 * @package  OCA\OpenConnector\Service\Security
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
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Plans (never executes) the inline-secret → credentialRef migration.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
 */
class InlineSecretMigrationPlanner
{

    /**
     * The OpenRegister register slug holding openconnector's objects.
     *
     * @var string
     */
    public const REGISTER = 'openconnector';

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
        'apikey'   => 'generic-apikey',
        'password' => 'generic-basic',
        'secret'   => 'generic-oauth2',
        'jwt'      => 'generic-bearer',
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
     * Constructor.
     *
     * @param OrObjectService $objectService The OpenRegister object service.
     * @param LoggerInterface $logger        Secret-free logging only.
     */
    public function __construct(
        private readonly OrObjectService $objectService,
        private readonly LoggerInterface $logger
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
     *
     * @return array<string, mixed> The raw source data (secrets intact), or [] when unreadable.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-raw-secret-read
     */
    public function readRawSource(string $uuid): array
    {
        try {
            $entity = $this->objectService->find(
                id: $uuid,
                register: self::REGISTER,
                schema: self::SCHEMA,
                _rbac: false,
                _multitenancy: false,
                _render: false
            );
        } catch (Throwable $e) {
            // Secret-free: the uuid is not a secret, the exception text may not
            // be quoted verbatim in case an upstream ever interpolates data.
            $this->logger->warning(
                '[openconnector] inline-secret planner: raw source read failed',
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
     * @param mixed  $value The raw field value (never returned, never logged).
     *
     * @return array{field: string, state: string, provider: string|null}
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
     */
    private function describeValue(string $field, mixed $value): array
    {
        // Already a `{credentialRef: {...}}` placeholder → nothing to do. Matches
        // BrokeredCallService::isPlaceholder(): credentialRef must be the SOLE key.
        if (is_array($value) === true && array_keys($value) === ['credentialRef']) {
            return ['field' => $field, 'state' => 'already-migrated', 'provider' => null];
        }

        // Empty / null → nothing to migrate. An empty string is not a secret.
        if ($value === null || $value === '' || $value === []) {
            return ['field' => $field, 'state' => 'empty', 'provider' => null];
        }

        if (in_array($field, self::UNMAPPABLE_FIELDS, true) === true) {
            return ['field' => $field, 'state' => 'needs-manual-review', 'provider' => null];
        }

        $provider = (self::PROVIDER_MAP[$field] ?? null);
        if ($provider === null) {
            return ['field' => $field, 'state' => 'needs-manual-review', 'provider' => null];
        }

        return ['field' => $field, 'state' => 'would-migrate', 'provider' => $provider];
    }//end describeValue()

    /**
     * Plan one source's inline-secret migration from its RAW data.
     *
     * @param string               $uuid    The source UUID.
     * @param string               $name    The source's human-readable name (not a secret).
     * @param array<string, mixed> $rawData The RAW source data (from {@see readRawSource()}).
     *
     * @return array{uuid: string, name: string, fields: array<int, array{field: string,
     *     state: string, provider: string|null}>, wouldMigrate: int, needsReview: int}
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
     */
    public function planSource(string $uuid, string $name, array $rawData): array
    {
        $fields       = [];
        $wouldMigrate = 0;
        $needsReview  = 0;

        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $rawData) === false) {
                continue;
            }

            $described = $this->describeValue(field: $field, value: $rawData[$field]);
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
            'uuid'         => $uuid,
            'name'         => $name,
            'fields'       => $fields,
            'wouldMigrate' => $wouldMigrate,
            'needsReview'  => $needsReview,
        ];
    }//end planSource()

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
     *   2. Worse at runtime: openconnector's source calls are predominantly
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
    public function planAll(int $limit=1000): array
    {
        $uuids = $this->listSourceUuids(limit: $limit);

        $plans        = [];
        $wouldMigrate = 0;
        $needsReview  = 0;

        foreach ($uuids as $uuid => $name) {
            // Per-source isolation: one unreadable source must never abort the
            // batch or mask the rest of the fleet's state.
            $rawData = $this->readRawSource(uuid: (string) $uuid);
            if ($rawData === []) {
                continue;
            }

            $plan = $this->planSource(uuid: (string) $uuid, name: $name, rawData: $rawData);
            if ($plan['fields'] === []) {
                continue;
            }

            $wouldMigrate += $plan['wouldMigrate'];
            $needsReview  += $plan['needsReview'];
            $plans[]       = $plan;
        }//end foreach

        return [
            'sources'      => $plans,
            'totalSources' => count($uuids),
            'wouldMigrate' => $wouldMigrate,
            'needsReview'  => $needsReview,
            // THE PHASE D GATE. True only when no source holds an unmigrated
            // inline secret in any field — including the ones we cannot map.
            'clean'        => ($wouldMigrate === 0 && $needsReview === 0),
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
    public function listSourceUuids(int $limit): array
    {
        try {
            $result = $this->objectService->findAll(
                config: [
                    'limit'   => $limit,
                    'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                '[openconnector] inline-secret planner: source listing failed',
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

            $uuid = (string) $item->getUuid();
            if ($uuid === '') {
                continue;
            }

            $data         = $item->getObject();
            $uuids[$uuid] = (string) ($data['name'] ?? $uuid);
        }

        return $uuids;
    }//end listSourceUuids()
}//end class
