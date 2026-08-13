<?php

/**
 * OpenConnector — remove the auto-migratable inline-secret fields from the LIVE
 * source schema, ONCE the fleet has verifiably migrated them (Phase D / ocon#151).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS DOES — and why it is IRREVERSIBLE
 * ─────────────────────────────────────────────────────────────────────────────
 * Phase C ({@see \OCA\OpenConnector\Repair\RecordInlineSecretMigrationStatus},
 * which now RUNS {@see \OCA\OpenConnector\Service\Security\InlineSecretMigrationExecutor})
 * folds every inline `apikey`/`secret`/`password`/`jwt` on a `source` into the
 * OpenRegister credential broker, leaving only a `{credentialRef}` placeholder.
 * This step is the LAST move: when EVERY source is verifiably clean of inline
 * values in those four fields, it deletes the four properties from the live
 * `source` schema so the plaintext columns can never be written again.
 *
 * That schema mutation is IRREVERSIBLE and UNGATED by any later import — see the
 * CATASTROPHIC-TRAP note on {@see \OCA\OpenConnector\Repair\RemoveMigratedSourceSecretFields::run()}.
 * The whole safety of this app therefore rests on ONE invariant:
 *
 *     NEVER remove a field while ANY source still holds an inline value for it.
 *
 * The gate that enforces it is {@see isAutoMigratableClean()} — computed from RAW
 * `_render: false` reads via {@see InlineSecretMigrationPlanner} (the ONLY read
 * that survives the writeOnly render boundary). If the gate is not clean, this
 * step does NOTHING.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SCOPE — exactly four fields, all-or-nothing
 * ─────────────────────────────────────────────────────────────────────────────
 *  - The four AUTO-MIGRATABLE fields (`apikey`, `secret`, `password`, `jwt`) are
 *    removed together, and only when ALL four are clean across the whole fleet
 *    (all-4-or-nothing: simpler and safer than per-field — a single gate, one
 *    schema write). The planner's `wouldMigrate` counter counts EXACTLY these
 *    four (each maps to a broker provider), so `wouldMigrate === 0` IS the
 *    four-field gate.
 *  - `authenticationConfig` is DELIBERATELY EXCLUDED. It is `needs-manual-review`
 *    (a multi-value bag no single inject-only provider can hold — the planner
 *    refuses to guess its decomposition), so it is never removed here and, being
 *    tracked in the planner's SEPARATE `needsReview` counter, it never blocks the
 *    four. It stays on the schema, still `writeOnly`, until the manual-review
 *    follow-up (ocon#232) migrates it.
 *
 * Idempotent (a field already absent is skipped; nothing to remove is a no-op),
 * never fatal (a failure leaves the schema untouched — the pre-existing state),
 * a clean no-op when OpenRegister is absent, and secret-free in every log line.
 *
 * @category Repair
 * @package  OCA\OpenConnector\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Repair;

use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the four auto-migratable secret fields from the live source schema once clean.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-remove-migrated-fields
 */
class RemoveMigratedSourceSecretFields implements IRepairStep {

	/**
	 * FQCN of OpenRegister's SchemaMapper, resolved lazily so OpenConnector still
	 * boots (and this step is a clean no-op) without OpenRegister.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * FQCN of OpenRegister's ObjectService — resolved lazily for the raw-read gate.
	 *
	 * @var string
	 */
	private const OR_OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The source schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'source';

	/**
	 * Appconfig app id.
	 *
	 * @var string
	 */
	private const APP_ID = 'openconnector';

	/**
	 * Appconfig key: '1' when the live source schema no longer declares ANY of the
	 * four auto-migratable secret fields; '0' when removal is still blocked (a
	 * source holds an inline value) or the schema could not be read.
	 *
	 * @var string
	 */
	public const KEY_FIELDS_REMOVED = 'inline_secret_fields_removed';

	/**
	 * The four AUTO-MIGRATABLE inline secret fields removed by this step.
	 *
	 * These are exactly {@see InlineSecretMigrationPlanner::PROVIDER_MAP}'s keys —
	 * `authenticationConfig` is NOT here on purpose (it is unmappable / manual
	 * review and must never be removed by this automatic step).
	 *
	 * @var array<int, string>
	 */
	public const AUTO_MIGRATABLE_FIELDS = ['apikey', 'secret', 'password', 'jwt'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (to resolve OR lazily).
	 * @param IAppConfig $appConfig The appconfig store for the done marker.
	 * @param LoggerInterface $logger Secret-free logging only.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude Framework IRepairStep metadata accessor; no domain behavior.
	 */
	public function getName(): string {
		return 'Remove migrated inline-secret fields (apikey/secret/password/jwt) from the OpenConnector source schema once clean';
	}//end getName()

	/**
	 * Remove the four fields from the live source schema — but ONLY when clean.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * THE CATASTROPHIC TRAP THIS STEP NAVIGATES
	 * ─────────────────────────────────────────────────────────────────────────
	 * The four fields MUST STAY in `lib/Settings/openconnector_register.json`.
	 * OpenRegister's `Schema::hydrate()` sets properties via `setProperties()` — a
	 * WHOLESALE REPLACE — so a register import that BUMPS the source schema version
	 * (ImportHandler::handleSchema → updateFromArray, verified at OR
	 * origin/development) would PRUNE any property ABSENT from the JSON, UNGATED by
	 * this per-instance migration gate. Removing the fields from the JSON would
	 * therefore drop them on every instance on the next version-bumping upgrade,
	 * orphaning every un-migrated inline secret fleet-wide. So the JSON keeps all
	 * five fields and removal happens ONLY here, per-instance, gated on clean.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-remove-migrated-fields
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\' . self::SCHEMA_MAPPER) === false || class_exists('\\' . self::OR_OBJECT_SERVICE) === false) {
			$output->info('OpenConnector: OpenRegister not available; skipping source-secret field removal.');
			return;
		}

		try {
			// THE GATE. Never remove a field while any source still holds an inline
			// value for one of the four — computed from RAW `_render: false` reads.
			if ($this->isAutoMigratableClean() === false) {
				$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_FIELDS_REMOVED, value: '0');
				$output->info(
					'OpenConnector: at least one source still holds an inline apikey/secret/password/jwt — '
					. 'NOT removing the schema fields. Run `occ openconnector:migrate-inline-secrets --dry-run` for the breakdown.'
				);
				return;
			}

			$this->removeFieldsWhenClean(output: $output);
		} catch (Throwable $e) {
			// Never fatal: leaving the fields on the schema is the safe pre-existing
			// state. But record the gate closed so observability reflects the failure.
			$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_FIELDS_REMOVED, value: '0');
			$output->warning('OpenConnector: could not remove migrated source-secret fields: ' . $e->getMessage());
			$this->logger->error(
				'[openconnector] RemoveMigratedSourceSecretFields failed; source schema left untouched',
				['errorClass' => get_class($e)]
			);
		}//end try
	}//end run()

	/**
	 * The safety gate: are ALL four auto-migratable fields clean across the fleet?
	 *
	 * Uses {@see InlineSecretMigrationPlanner::planAll()}, whose `wouldMigrate`
	 * counter counts EXACTLY the four provider-mapped fields (an inline, non-ref,
	 * non-empty value in `apikey`/`secret`/`password`/`jwt`). `authenticationConfig`
	 * is counted separately in `needsReview` and is intentionally IGNORED here, so
	 * an unmigrated auth-config never blocks the four.
	 *
	 * @return bool True only when NO source holds an inline value in any of the four fields.
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-remove-migrated-fields
	 */
	private function isAutoMigratableClean(): bool {
		$objectService = $this->container->get(self::OR_OBJECT_SERVICE);
		$planner = new InlineSecretMigrationPlanner(
			objectService: $objectService,
			logger: $this->logger
		);

		$plan = $planner->planAll();

		// `wouldMigrate` counts ONLY the four provider-mapped fields — it is the
		// four-field gate. `needsReview` (authenticationConfig) is excluded by design.
		return ((int)$plan['wouldMigrate'] === 0);
	}//end isAutoMigratableClean()

	/**
	 * Delete the four fields from the live source schema (gate already passed).
	 *
	 * Idempotent: only fields still present are unset; if none are present the
	 * schema is not written. Bumps the schema `version` (patch) on a real change so
	 * the mutation is observable and versioned.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-remove-migrated-fields
	 */
	private function removeFieldsWhenClean(IOutput $output): void {
		$schemaMapper = $this->container->get(self::SCHEMA_MAPPER);

		// Resolve the live source schema by slug; if it is not there, nothing to do.
		$schema = $schemaMapper->find(self::SCHEMA_SLUG);
		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_FIELDS_REMOVED, value: '0');
			return;
		}

		$removed = [];
		foreach (self::AUTO_MIGRATABLE_FIELDS as $field) {
			if (array_key_exists($field, $properties) === true) {
				unset($properties[$field]);
				$removed[] = $field;
			}
		}

		if ($removed === []) {
			// Already removed on a previous run (or never present) — idempotent no-op.
			$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_FIELDS_REMOVED, value: '1');
			$output->info('OpenConnector: source-secret fields already removed from the schema; nothing to do.');
			return;
		}

		$schema->setProperties($properties);
		$schema->setVersion($this->bumpPatchVersion(version: (string)$schema->getVersion()));
		$schemaMapper->update($schema);

		$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_FIELDS_REMOVED, value: '1');
		$output->info(
			'OpenConnector: removed migrated source-secret fields (' . implode('/', $removed) . ') from the live source schema. '
			. 'authenticationConfig is retained (manual review).'
		);
	}//end removeFieldsWhenClean()

	/**
	 * Bump the patch component of a semver-ish version string.
	 *
	 * @param string $version The current version (e.g. "1.2.0").
	 *
	 * @return string The version with its patch component incremented (e.g. "1.2.1").
	 *
	 * @spec exclude Pure string helper; no domain behavior.
	 */
	private function bumpPatchVersion(string $version): string {
		$version = trim($version);
		if ($version === '') {
			$version = '1.0.0';
		}

		// Pad to at least three components without a count()-in-loop: the array
		// union fills any missing 0/1/2 keys, then take the first three.
		$parts = array_slice((explode('.', $version) + ['0', '0', '0']), 0, 3);
		$parts[2] = (string)(((int)$parts[2]) + 1);

		return implode('.', $parts);
	}//end bumpPatchVersion()
}//end class
