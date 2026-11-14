<?php

/**
 * Integriq LookupAllowlist.
 *
 * The register schemas a mapping's `lookup()` may read, kept by an
 * administrator. Mapping templates run in a system context during a
 * synchronization, so a lookup that could read any schema would let anyone
 * who can edit a mapping read any register (mapping-formats-and-lookups D4).
 * Stored in app config like the expression-source allowlist; every entry
 * names who added it and when. The list starts empty.
 *
 * @category Twig
 * @package  OCA\Integriq\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Twig;

use DateTimeImmutable;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The administered list of register schemas a mapping may look up in.
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */
class LookupAllowlist {

	/**
	 * The app the list is stored under.
	 */
	public const APP_ID = 'integriq';

	/**
	 * The config key holding the list.
	 */
	public const CONFIG_KEY = 'mapping_lookup_allowlist';

	/**
	 * A schema is named as `register/schema`, each a slug or an id.
	 */
	public const SCHEMA_PATTERN = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig Where the list is stored.
	 * @param LoggerInterface $logger    The logger.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why a schema may not be added, or null when it may.
	 *
	 * @param string $schema The schema, as `register/schema`.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function refusalFor(string $schema): ?string {
		if (preg_match(self::SCHEMA_PATTERN, trim($schema)) !== 1) {
			return sprintf('"%s" is refused: name one schema as register/schema, without a wildcard.', trim($schema));
		}

		return null;
	}//end refusalFor()

	/**
	 * The listed schemas, each with who added it and when.
	 *
	 * An unreadable list is an empty list, never "everything".
	 *
	 * @return array<string, array{addedBy: string, addedAt: string}>
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function entries(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$this->logger->error('[LookupAllowlist] the stored list could not be read; no lookup resolves until it is repaired');
			return [];
		}

		$entries = [];
		foreach ($decoded as $schema => $entry) {
			if ($this->refusalFor(schema: (string)$schema) !== null || is_array($entry) === false) {
				continue;
			}

			$entries[(string)$schema] = ['addedBy' => (string)($entry['addedBy'] ?? ''), 'addedAt' => (string)($entry['addedAt'] ?? '')];
		}

		return $entries;
	}//end entries()

	/**
	 * Whether a schema is on the list, by exact name.
	 *
	 * @param string $schema The schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
	 */
	public function allows(string $schema): bool {
		return array_key_exists($schema, $this->entries());
	}//end allows()

	/**
	 * Add a schema, recording who added it and when.
	 *
	 * @param string $schema    The schema.
	 * @param string $principal Who added it.
	 *
	 * @return array{added: bool, reason: string}
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function add(string $schema, string $principal): array {
		$refusal = $this->refusalFor(schema: $schema);
		if ($refusal !== null) {
			return ['added' => false, 'reason' => $refusal];
		}

		if (trim($principal) === '') {
			return ['added' => false, 'reason' => 'A schema is added by somebody.'];
		}

		$schema = trim($schema);
		$entries = $this->entries();
		if (array_key_exists($schema, $entries) === true) {
			return ['added' => false, 'reason' => sprintf('"%s" is already on the list.', $schema)];
		}

		$entries[$schema] = ['addedBy' => trim($principal), 'addedAt' => (new DateTimeImmutable())->format(DATE_ATOM)];
		$this->store(entries: $entries);

		return ['added' => true, 'reason' => ''];
	}//end add()

	/**
	 * Remove a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return bool Whether it was on the list.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function remove(string $schema): bool {
		$entries = $this->entries();
		if (array_key_exists($schema, $entries) === false) {
			return false;
		}

		unset($entries[$schema]);
		$this->store(entries: $entries);

		return true;
	}//end remove()

	/**
	 * Write the list.
	 *
	 * @param array<string, array{addedBy: string, addedAt: string}> $entries The list.
	 *
	 * @return void
	 */
	private function store(array $entries): void {
		ksort($entries);
		$this->appConfig->setValueString(self::APP_ID, self::CONFIG_KEY, (string)json_encode($entries));
	}//end store()
}//end class
