<?php

/**
 * Configuration reference translator.
 *
 * Translates the entity references in a nested configuration array between
 * ids and slugs, using the global mappings of a configuration export/import.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service\ConfigurationHandlers;

/**
 * One translation run over a nested configuration: every value under a key
 * that names an entity type (`source`) or ends in one (`sourceId`) becomes
 * its counterpart when the global mappings know it.
 *
 * @spec openspec/specs/configuration-export-import/spec.md
 */
final class ConfigurationReferenceTranslator {
	/**
	 * The entity types a configuration key can refer to.
	 *
	 * @var string[]
	 */
	private const ENTITY_TYPES = ['source', 'job', 'endpoint', 'mapping', 'register', 'schema', 'synchronization'];

	/**
	 * The mapping ids translated so far, in traversal order (ids to slugs only).
	 *
	 * @var array<int, mixed>
	 */
	public array $mappingIds = [];

	/**
	 * The global id/slug mappings, per entity type.
	 *
	 * @var array
	 */
	private array $mappings = [];

	/**
	 * The mappings key to translate with: idToSlug or slugToId.
	 *
	 * @var string
	 */
	private string $direction = 'idToSlug';

	/**
	 * A translator from ids to slugs, for export.
	 *
	 * @param array $mappings The global mappings.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public static function idsToSlugs(array $mappings): self {
		$translator = new self();
		$translator->mappings = $mappings;

		return $translator;
	}//end idsToSlugs()

	/**
	 * A translator from slugs to ids, for import.
	 *
	 * @param array $mappings The global mappings.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public static function slugsToIds(array $mappings): self {
		$translator = self::idsToSlugs(mappings: $mappings);
		$translator->direction = 'slugToId';

		return $translator;
	}//end slugsToIds()

	/**
	 * Translate a configuration array, recursing into nested arrays.
	 *
	 * @param array $config The configuration array.
	 *
	 * @return array The configuration with its references translated.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function translate(array $config): array {
		foreach ($config as $key => $value) {
			if (is_array($value) === true) {
				$config[$key] = $this->translate(config: $value);
				continue;
			}

			$config[$key] = $this->translateValue(key: $key, value: $value);
		}

		return $config;
	}//end translate()

	/**
	 * The counterpart of a value when its key refers to an entity type the
	 * mappings know the value for, otherwise the value itself.
	 *
	 * @param int|string $key   The configuration key.
	 * @param mixed      $value The scalar value.
	 *
	 * @return mixed The translated or unchanged value.
	 */
	private function translateValue(int|string $key, mixed $value): mixed {
		foreach (self::ENTITY_TYPES as $type) {
			if ($this->refersTo(key: $key, type: $type) === true && isset($this->mappings[$type][$this->direction][$value]) === true) {
				return $this->counterpart(type: $type, value: $value);
			}
		}

		return $value;
	}//end translateValue()

	/**
	 * Whether a key names the entity type exactly (`source`) or by its id
	 * suffix (`sourceId`).
	 *
	 * @param int|string $key  The configuration key.
	 * @param string     $type The entity type.
	 *
	 * @return boolean
	 */
	private function refersTo(int|string $key, string $type): bool {
		return $key === $type || str_ends_with($key, $type . 'Id') === true;
	}//end refersTo()

	/**
	 * The mapped counterpart of a value, recording a translated mapping id.
	 *
	 * @param string $type  The entity type.
	 * @param mixed  $value The value the mappings know.
	 *
	 * @return mixed The counterpart.
	 */
	private function counterpart(string $type, mixed $value): mixed {
		if ($type === 'mapping' && $this->direction === 'idToSlug') {
			$this->mappingIds[] = $value;
		}

		return $this->mappings[$type][$this->direction][$value];
	}//end counterpart()
}//end class
