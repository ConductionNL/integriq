<?php

/**
 * Contract of the SloCurriculumPresetRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Adapters\Slo
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

namespace OCA\Integriq\Adapters\Slo;

use OCA\Integriq\Exception\SloCurriculumException;
use OCA\Integriq\Exception\UnknownSloCurriculumSetException;

/**
 * Public operations of {@see SloCurriculumPresetRegistry} that other components call.
 */
interface SloCurriculumPresetRegistryInterface {
	/**
	 * The seeded source object, as seeded.
	 *
	 * @return array<string,mixed> The source object (empty when the fragment is missing).
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function source(): array;

	/**
	 * Every seeded set key.
	 *
	 * @return array<int,string> Set keys in seeded order.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function setKeys(): array;

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
	public function set(string $setKey): array;

	/**
	 * Every profile, described for a listing.
	 *
	 * @return array<int,array{key:string,label:string,sourceAuthority:string,level:string|null,framework:string}>
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function describeSets(): array;

	/**
	 * The learniq field mapping for frameworks.
	 *
	 * @return array<string,mixed> learniq field => normalised field (or literal).
	 *
	 * @throws SloCurriculumException When the mapping preset is not seeded.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
	 */
	public function frameworkMapping(): array;

	/**
	 * The learniq field mapping for competencies.
	 *
	 * @return array<string,mixed> learniq field => normalised field (or literal).
	 *
	 * @throws SloCurriculumException When the mapping preset is not seeded.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
	 */
	public function competencyMapping(): array;

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
	public function mapping(string $slug): array;

	/**
	 * The CC BY 4.0 attribution block.
	 *
	 * @return array<string,string> publisher, dataset, sourceUrl, licence, licenceUrl, text.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function attribution(): array;

	/**
	 * The default proficiency scale every imported framework gets.
	 *
	 * @return array<int,array<string,mixed>> Levels `{levelId, label, order}`.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function proficiencyLevels(): array;

	/**
	 * SLO niveau uuid => year labels.
	 *
	 * @return array<string,array<int,string>> The seeded year table.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
	 */
	public function yearNiveaus(): array;
}
