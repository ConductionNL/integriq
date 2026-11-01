<?php

/**
 * Contract of the InlineSecretMigrationPlanner.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * Public operations of {@see InlineSecretMigrationPlanner} that other components call.
 */
interface InlineSecretMigrationPlannerInterface {
	/**
	 * The OpenRegister register slug holding integriq's objects.
	 *
	 * @var string
	 */
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
	public const APP_ID = 'openconnector';

	/**
	 * Appconfig key: '1' when NO source holds an unmigrated inline secret.
	 * Phase D's gate — it may remove the schema properties only when this is '1'.
	 *
	 * @var string
	 */
	public const KEY_CLEAN = 'inline_secrets_clean';

	/**
	 * Appconfig key: the count of inline secret fields still awaiting migration
	 * (auto-mappable). Diagnostic; not the gate.
	 *
	 * @var string
	 */
	public const KEY_PENDING = 'inline_secrets_pending';

	/**
	 * Appconfig key: the count of inline secret fields needing manual review
	 * (e.g. `authenticationConfig`). Also blocks Phase D.
	 *
	 * @var string
	 */
	public const KEY_MANUAL = 'inline_secrets_manual_review';

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
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-raw-secret-read
	 */
	public function readRawSource(string $uuid, string $schema = self::SCHEMA): array;

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
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
	 */
	public function planSource(string $uuid, string $name, array $rawData, string $schema = self::SCHEMA): array;

	/**
	 * The fields one schema declares as migratable.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int,string> The field names, empty when the schema is unknown.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function fieldsFor(string $schema): array;

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
	public function referenceFieldFor(string $schema, string $field): ?string;

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
	public function planSchema(string $schema, int $limit = 1000): array;

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
	public function planEverything(int $limit = 1000): array;

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
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
	 */
	public function planAll(int $limit = 1000): array;

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
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
	 */
	public function listSourceUuids(int $limit): array;

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
	public function listUuids(string $schema, int $limit): array;
}
