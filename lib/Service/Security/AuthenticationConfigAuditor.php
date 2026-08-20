<?php

/**
 * Read-only audit of the vestigial `source.authenticationConfig` field (ocon#232).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS FIELD IS TREATED AS DEAD DATA RATHER THAN MIGRATED
 * ─────────────────────────────────────────────────────────────────────────────
 * ocon#151 migrated four inline source secrets (`apikey`/`secret`/`password`/
 * `jwt`) into the OpenRegister credential broker. The fifth,
 * `authenticationConfig`, was parked as `needs-manual-review` because it is a
 * `type: object` bag that no single inject-only provider can hold
 * ({@see InlineSecretMigrationPlanner::UNMAPPABLE_FIELDS}).
 *
 * Re-examining it for ocon#232 shows a migration would be POINTLESS, because NO
 * PHP CODE AUTHENTICATES FROM IT. Verified at `origin/development`:
 *
 *  - {@see \OCA\OpenConnector\Service\AuthenticationService} builders
 *    (`createClientCredentialConfig`, `createPasswordConfig`, `fetchOAuthTokens`,
 *    `fetchJWTToken`, `buildWsSecurityHeader`) read `$configuration['client_secret']`
 *    / `['username']` / `['password']` / `['secret']` — from the `configuration`
 *    array handed to them, never from `authenticationConfig`.
 *  - {@see \OCA\OpenConnector\Twig\AuthenticationRuntime} does
 *    `new Dot($source['configuration'])` and passes the `configuration.authentication.*`
 *    region onward. It has NOT read `authenticationConfig` since commit b6470597
 *    (2024-11-19), which replaced `$source->getAuthenticationConfig()` with
 *    `$source->getConfiguration()` + `authentication.*` and never migrated the data
 *    nor updated the docs.
 *  - The only remaining PHP references are REDACTION: `SourceHandler` unsets it for
 *    export, `ConfigurationImportPreviewService` + `SensitiveFieldRegistry` list it
 *    as sensitive. Nothing bridges `authenticationConfig` → `configuration`.
 *
 * So a credentialRef minted for it would never be resolved by anything. The field
 * is plaintext credential data at rest with no consumer — REMOVAL, not migration,
 * is the correct treatment. Because removal DELETES data, it is operator-gated:
 * this auditor is the read-only half an operator runs first.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE ONE LIVE PATH — WHY {@see auditSource()} SCANS FOR TWIG REFERENCES
 * ─────────────────────────────────────────────────────────────────────────────
 * "No PHP code reads it" is NOT the same as "nothing can read it".
 * {@see \OCA\OpenConnector\Service\CallService::renderValue()} renders every
 * `configuration` value as a Twig template with `context: ['source' => $sourceData]`,
 * and since ocon#215 `$sourceData` is the RAW source (`_render: false`) — secrets
 * intact. So an OPERATOR-AUTHORED template such as
 *
 *     "Authorization": "Bearer {{ source.authenticationConfig.client_secret }}"
 *
 * DOES resolve to a live secret today. That is a data-driven path invisible to any
 * grep for `['authenticationConfig']` in `lib/`. Deleting the value under such a
 * source would break its outbound auth. Hence every audited source is scanned for a
 * Twig reference to `source.authenticationConfig`, and a referenced source is
 * reported `referenced: true` and REFUSED by
 * {@see AuthenticationConfigRemover} — the audit is what makes the removal safe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SECRET DISCLOSURE IS THE HAZARD THIS CLASS IS DESIGNED AROUND
 * ─────────────────────────────────────────────────────────────────────────────
 * An operator must be able to see WHAT KIND OF THING sits in the bag without this
 * tool becoming a secret-disclosure vector. So the report carries KEY NAMES ONLY
 * (`array_keys()`), a value-SHAPE hint, and a non-reversible 4-byte fingerprint.
 * A VALUE IS NEVER emitted, logged, or returned. See {@see describeValue()}.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Security
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
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Security;

/**
 * Reports, per source, what `authenticationConfig` holds — key names only, never values.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
 */
class AuthenticationConfigAuditor {

	/**
	 * The vestigial field this auditor reports on.
	 *
	 * @var string
	 */
	public const FIELD = 'authenticationConfig';

	/**
	 * A source whose `authenticationConfig` is absent / null / empty.
	 *
	 * @var string
	 */
	public const STATE_CLEAR = 'clear';

	/**
	 * A source that still holds a non-empty `authenticationConfig`.
	 *
	 * @var string
	 */
	public const STATE_HOLDS_VALUE = 'holds-value';

	/**
	 * A source whose raw read failed — state UNKNOWN, so it must never be treated as clear.
	 *
	 * @var string
	 */
	public const STATE_UNREADABLE = 'unreadable';

	/**
	 * Matches a Twig reference to `source.authenticationConfig` in a configuration template.
	 *
	 * Covers the three ways Twig can reach the property:
	 *   - dot access      `{{ source.authenticationConfig.client_secret }}`
	 *   - subscript       `{{ source['authenticationConfig']['x'] }}`
	 *   - attribute()     `{{ attribute(source, 'authenticationConfig') }}`
	 *
	 * Deliberately BROAD (any mention wins): a false positive costs an operator one
	 * manual review, a false negative silently breaks a live source's outbound auth.
	 *
	 * @var string
	 */
	private const TWIG_REF_PATTERN = '/(?:\bsource\s*\.\s*authenticationConfig\b)'
		. '|(?:\bsource\s*\[\s*[\'"]authenticationConfig[\'"]\s*\])'
		. '|(?:\battribute\s*\(\s*source\s*,\s*[\'"]authenticationConfig[\'"])/i';

	/**
	 * Constructor.
	 *
	 * No logger: this class is read-only and every read it performs is delegated to
	 * {@see InlineSecretMigrationPlanner}, which already logs its own failures
	 * secret-free. Injecting an unused logger would be dead weight.
	 *
	 * @param InlineSecretMigrationPlanner $planner The planner — reused for its raw read + fleet listing.
	 */
	public function __construct(
		private readonly InlineSecretMigrationPlanner $planner,
	) {

	}//end __construct()

	/**
	 * Audit every source's `authenticationConfig`.
	 *
	 * Per-source isolation: an unreadable source is recorded as `unreadable` and
	 * never aborts the batch, and never counts as clear.
	 *
	 * @param integer $limit Maximum number of sources to inspect.
	 *
	 * @return array<string, mixed> The audit report (key names only — never a value).
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	public function auditAll(int $limit = 1000): array {
		$uuids = $this->planner->listSourceUuids(limit: $limit);

		$sources = [];
		$holdValue = 0;
		$referenced = 0;
		$clear = 0;
		$unreadable = 0;

		foreach ($uuids as $uuid => $name) {
			$record = $this->auditSource(uuid: (string)$uuid, name: (string)$name);
			$sources[] = $record;

			if ($record['state'] === self::STATE_HOLDS_VALUE) {
				$holdValue++;
			}

			if ($record['state'] === self::STATE_CLEAR) {
				$clear++;
			}

			if ($record['state'] === self::STATE_UNREADABLE) {
				$unreadable++;
			}

			if ($record['referenced'] === true) {
				$referenced++;
			}
		}

		// The schema property may only be dropped when the whole fleet is clear,
		// nothing still references it from a Twig template, and every source was
		// actually READABLE (an unreadable source's state is unknown — fail closed).
		$removable = ($holdValue === 0 && $referenced === 0 && $unreadable === 0);

		return [
			'field' => self::FIELD,
			'totalSources' => count($uuids),
			'holdValue' => $holdValue,
			'clear' => $clear,
			'unreadable' => $unreadable,
			'referenced' => $referenced,
			'schemaPropertyRemovable' => $removable,
			'sources' => $sources,
		];
	}//end auditAll()

	/**
	 * Audit ONE source.
	 *
	 * The read goes through {@see InlineSecretMigrationPlanner::readRawSource()},
	 * which uses `_render: false`. THAT ARGUMENT IS LOAD-BEARING: `authenticationConfig`
	 * is `writeOnly: true`, and OpenRegister strips every writeOnly property on ANY
	 * rendered read — the strip is SCHEMA-gated, not `_rbac`-gated (openregister#389/#429).
	 * A rendered read would therefore report EVERY source as "clear" and this audit
	 * would greenlight dropping a schema property that still holds live credentials.
	 *
	 * @param string $uuid The source uuid.
	 * @param string $name The source name.
	 *
	 * @return array<string, mixed> The per-source record (key names only — never a value).
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	public function auditSource(string $uuid, string $name): array {
		$rawData = $this->planner->readRawSource(uuid: $uuid);
		if ($rawData === []) {
			// Unknown, NOT clear. readRawSource() has already logged (secret-free).
			return [
				'uuid' => $uuid,
				'name' => $name,
				'state' => self::STATE_UNREADABLE,
				'keys' => [],
				'shapes' => [],
				'referenced' => false,
				'references' => [],
			];
		}

		$value = ($rawData[self::FIELD] ?? null);
		$references = $this->findTwigReferences(configuration: ($rawData['configuration'] ?? []));

		$state = self::STATE_HOLDS_VALUE;
		if ($value === null || $value === [] || $value === '') {
			$state = self::STATE_CLEAR;
		}

		$described = ['keys' => [], 'shapes' => []];
		if ($state === self::STATE_HOLDS_VALUE) {
			$described = $this->describeBag(value: $value);
		}

		return [
			'uuid' => $uuid,
			'name' => $name,
			'state' => $state,
			'keys' => $described['keys'],
			'shapes' => $described['shapes'],
			'referenced' => ($references !== []),
			'references' => $references,
		];
	}//end auditSource()

	/**
	 * Describe a non-empty `authenticationConfig` bag: key NAMES + per-key shapes.
	 *
	 * Extracted from {@see auditSource()} to keep that method's branching honest, and
	 * because "what may be reported about the bag" is the single most security-relevant
	 * decision in this class — it deserves to be one small, readable function.
	 *
	 * @param mixed $value The raw, non-empty `authenticationConfig` value.
	 *
	 * @return array{keys: array<int, string>, shapes: array<string, array{shape: string, fingerprint: string|null}>}
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	private function describeBag(mixed $value): array {
		if (is_array($value) === false) {
			// Off-schema scalar (the schema says object): report its shape at the top
			// level, still without the value.
			return ['keys' => [], 'shapes' => ['(scalar)' => $this->describeValue(value: $value)]];
		}

		// KEY NAMES ONLY. array_keys() by construction cannot carry a value.
		$keys = array_map('strval', array_keys($value));
		$shapes = [];
		foreach ($value as $key => $entry) {
			$shapes[(string)$key] = $this->describeValue(value: $entry);
		}

		return ['keys' => $keys, 'shapes' => $shapes];
	}//end describeBag()

	/**
	 * Describe a value WITHOUT disclosing it.
	 *
	 * Emits a type/size hint plus a 4-byte (8 hex chars) sha256 prefix. The prefix is
	 * deliberately truncated: it is enough to correlate "the same secret appears on
	 * two sources" or "this changed since the last audit", and — being both truncated
	 * and a one-way hash — is useless for recovering the value.
	 *
	 * The value itself is NEVER placed in the returned array.
	 *
	 * @param mixed $value The value to describe.
	 *
	 * @return array{shape: string, fingerprint: string|null} The non-reversible description.
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	private function describeValue(mixed $value): array {
		if ($value === null) {
			return ['shape' => 'null', 'fingerprint' => null];
		}

		if (is_bool($value) === true) {
			return ['shape' => 'boolean', 'fingerprint' => null];
		}

		if (is_int($value) === true) {
			return ['shape' => 'integer', 'fingerprint' => $this->fingerprint(value: (string)$value)];
		}

		if (is_float($value) === true) {
			return ['shape' => 'float', 'fingerprint' => $this->fingerprint(value: (string)$value)];
		}

		if (is_string($value) === true) {
			return [
				'shape' => sprintf('string(%d)', strlen($value)),
				'fingerprint' => $this->fingerprint(value: $value),
			];
		}

		if (is_array($value) === true) {
			if (array_is_list($value) === true) {
				return ['shape' => sprintf('array(%d items)', count($value)), 'fingerprint' => null];
			}

			return ['shape' => sprintf('object(%d keys)', count($value)), 'fingerprint' => null];
		}

		return ['shape' => 'unknown', 'fingerprint' => null];
	}//end describeValue()

	/**
	 * Non-reversible 4-byte fingerprint of a value.
	 *
	 * @param string $value The value to fingerprint.
	 *
	 * @return string 8 hex characters (the first 4 bytes of the sha256 digest).
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	private function fingerprint(string $value): string {
		return substr(hash('sha256', $value), 0, 8);
	}//end fingerprint()

	/**
	 * Find Twig templates in `configuration` that reference `source.authenticationConfig`.
	 *
	 * Returns the dot-PATHS at which a reference was found — never the template body,
	 * which could itself embed a literal secret.
	 *
	 * @param mixed $configuration The source's raw `configuration` value.
	 * @param string $path The dot-path walked so far (internal recursion state).
	 *
	 * @return array<int, string> The configuration paths holding a reference.
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	private function findTwigReferences(mixed $configuration, string $path = ''): array {
		if (is_string($configuration) === true) {
			if (preg_match(self::TWIG_REF_PATTERN, $configuration) === 1) {
				$found = $path;
				if ($found === '') {
					$found = '(root)';
				}

				return [$found];
			}

			return [];
		}

		if (is_array($configuration) === false) {
			return [];
		}

		$references = [];
		foreach ($configuration as $key => $entry) {
			$childPath = (string)$key;
			if ($path !== '') {
				$childPath = $path . '.' . (string)$key;
			}

			$references = array_merge(
				$references,
				$this->findTwigReferences(configuration: $entry, path: $childPath)
			);
		}

		return $references;
	}//end findTwigReferences()
}//end class
