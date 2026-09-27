<?php

/**
 * Integriq SLO curriculum preset registry.
 *
 * Reads the seeded `slo-curriculum` source template and its two mapping
 * presets from `lib/Settings/register.d/slo-curriculum-source.json`: the set
 * profiles, the CC BY 4.0 attribution, the default proficiency scale, the
 * year-niveau table and the learniq field mappings. The fragment is the single
 * source of truth: OpenRegister imports the same objects on install, so what
 * an operator sees in integriq is what the adapter runs.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

use OCA\Integriq\Exception\SloCurriculumException;
use OCA\Integriq\Exception\UnknownSloCurriculumSetException;

/**
 * Loads the seeded SLO source template once; immutable at runtime.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
 */
final class SloCurriculumPresetRegistry {
	/**
	 * Slug of the seeded source object.
	 */
	public const SOURCE_SLUG = 'slo-curriculum';

	/**
	 * Path to the register.d fragment, relative to this class.
	 */
	private const FRAGMENT_PATH = __DIR__ . '/../../Settings/register.d/slo-curriculum-source.json';

	/**
	 * Profile defaults, so a hand-added profile needs only what differs.
	 */
	private const PROFILE_DEFAULTS = [
		'label' => '',
		'sourceAuthority' => 'other',
		'level' => null,
		'edition' => null,
		'editionFrom' => null,
		'framework' => 'perRoot',
		'discover' => ['path' => '', 'query' => []],
		'levels' => [],
		'leafTypes' => [],
		'leafNiveauFilter' => [],
		'subjectFrom' => 'root',
		'namePrefix' => '',
		'fields' => [],
	];

	/**
	 * The seeded source object.
	 *
	 * @var array<string,mixed>
	 */
	private array $source = [];

	/**
	 * Seeded mapping objects keyed by slug.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $mappings = [];

	/**
	 * Constructor. Reads the fragment eagerly: it is small, static and shipped
	 * with the app.
	 *
	 * @param string|null $fragmentPath Override for the fragment path (tests only).
	 */
	public function __construct(?string $fragmentPath = null) {
		$path = ($fragmentPath ?? self::FRAGMENT_PATH);
		$raw = '';
		if (is_file($path) === true) {
			$raw = (string)file_get_contents($path);
		}

		$decoded = json_decode($raw, true);
		$objects = [];
		if (is_array($decoded) === true && is_array($decoded['components']['objects'] ?? null) === true) {
			$objects = $decoded['components']['objects'];
		}

		foreach ($objects as $object) {
			if (is_array($object) === false) {
				continue;
			}

			$schema = (string)($object['@self']['schema'] ?? '');
			$slug = (string)($object['@self']['slug'] ?? '');
			if ($schema === 'source' && $slug === self::SOURCE_SLUG) {
				$this->source = $object;
				continue;
			}

			if ($schema === 'mapping' && $slug !== '' && is_array($object['mapping'] ?? null) === true) {
				$this->mappings[$slug] = $object;
			}
		}
	}//end __construct()

	/**
	 * The seeded source object, as seeded.
	 *
	 * @return array<string,mixed> The source object (empty when the fragment is missing).
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function source(): array {
		return $this->source;
	}//end source()

	/**
	 * Every seeded set key.
	 *
	 * @return array<int,string> Set keys in seeded order.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function setKeys(): array {
		return array_map('strval', array_keys($this->rawSets()));
	}//end setKeys()

	/**
	 * One set profile, with defaults filled in.
	 *
	 * @param string $setKey The set key.
	 *
	 * @return array<string,mixed> The profile.
	 *
	 * @throws UnknownSloCurriculumSetException When no profile is seeded under the key.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function set(string $setKey): array {
		$sets = $this->rawSets();
		if (isset($sets[$setKey]) === false || is_array($sets[$setKey]) === false) {
			throw new UnknownSloCurriculumSetException(setKey: $setKey, known: $this->setKeys());
		}

		$profile = array_replace(self::PROFILE_DEFAULTS, $sets[$setKey]);
		$profile['key'] = $setKey;
		if (is_array($profile['discover']) === false) {
			$profile['discover'] = self::PROFILE_DEFAULTS['discover'];
		}

		$profile['discover'] = array_replace(self::PROFILE_DEFAULTS['discover'], $profile['discover']);

		return $profile;
	}//end set()

	/**
	 * Every profile, described for a listing.
	 *
	 * @return array<int,array{key:string,label:string,sourceAuthority:string,level:string|null,framework:string}>
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function describeSets(): array {
		$described = [];
		foreach ($this->setKeys() as $key) {
			$profile = $this->set(setKey: $key);
			$level = $profile['level'];
			if (is_string($level) === false) {
				$level = null;
			}

			$described[] = [
				'key' => $key,
				'label' => (string)$profile['label'],
				'sourceAuthority' => (string)$profile['sourceAuthority'],
				'level' => $level,
				'framework' => (string)$profile['framework'],
			];
		}

		return $described;
	}//end describeSets()

	/**
	 * The learniq field mapping for frameworks.
	 *
	 * @return array<string,mixed> learniq field => normalised field (or literal).
	 *
	 * @throws SloCurriculumException When the mapping preset is not seeded.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
	 */
	public function frameworkMapping(): array {
		$slug = (string)($this->configuration()['frameworkMapping'] ?? 'slo-curriculum-framework-mapping');
		return $this->mapping(slug: $slug);
	}//end frameworkMapping()

	/**
	 * The learniq field mapping for competencies.
	 *
	 * @return array<string,mixed> learniq field => normalised field (or literal).
	 *
	 * @throws SloCurriculumException When the mapping preset is not seeded.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
	 */
	public function competencyMapping(): array {
		$slug = (string)($this->configuration()['competencyMapping'] ?? 'slo-curriculum-competency-mapping');
		return $this->mapping(slug: $slug);
	}//end competencyMapping()

	/**
	 * One seeded mapping preset's field map.
	 *
	 * @param string $slug The mapping slug.
	 *
	 * @return array<string,mixed> The `mapping` member.
	 *
	 * @throws SloCurriculumException When no mapping is seeded under the slug.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
	 */
	public function mapping(string $slug): array {
		if (isset($this->mappings[$slug]) === false) {
			throw new SloCurriculumException(
				message: sprintf('The SLO curriculum mapping preset "%s" is not seeded in the register.d fragment.', $slug)
			);
		}

		return $this->mappings[$slug]['mapping'];
	}//end mapping()

	/**
	 * The CC BY 4.0 attribution block.
	 *
	 * @return array<string,string> publisher, dataset, sourceUrl, licence, licenceUrl, text.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function attribution(): array {
		$attribution = ($this->configuration()['attribution'] ?? []);
		if (is_array($attribution) === false) {
			return [];
		}

		return array_map('strval', $attribution);
	}//end attribution()

	/**
	 * The default proficiency scale every imported framework gets.
	 *
	 * @return array<int,array<string,mixed>> Levels `{levelId, label, order}`.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function proficiencyLevels(): array {
		$levels = ($this->configuration()['proficiencyLevels'] ?? []);
		if (is_array($levels) === false) {
			return [];
		}

		return array_values(array_filter($levels, 'is_array'));
	}//end proficiencyLevels()

	/**
	 * SLO niveau uuid => year labels.
	 *
	 * @return array<string,array<int,string>> The seeded year table.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
	 */
	public function yearNiveaus(): array {
		$table = ($this->configuration()['yearNiveaus'] ?? []);
		if (is_array($table) === false) {
			return [];
		}

		$years = [];
		foreach ($table as $uuid => $labels) {
			if (is_array($labels) === true) {
				$years[(string)$uuid] = array_values(array_map('strval', $labels));
			}
		}

		return $years;
	}//end yearNiveaus()

	/**
	 * The seeded source's configuration member.
	 *
	 * @return array<string,mixed> The configuration.
	 */
	private function configuration(): array {
		$configuration = ($this->source['configuration'] ?? []);
		if (is_array($configuration) === false) {
			return [];
		}

		return $configuration;
	}//end configuration()

	/**
	 * The raw `sets` member of the configuration.
	 *
	 * @return array<string,mixed> Profiles keyed by set key.
	 */
	private function rawSets(): array {
		$sets = ($this->configuration()['sets'] ?? []);
		if (is_array($sets) === false) {
			return [];
		}

		return $sets;
	}//end rawSets()
}//end class
