<?php

/**
 * Contract of the SloCurriculumMapper.
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

/**
 * Public operations of {@see SloCurriculumMapper} that other components call.
 */
interface SloCurriculumMapperInterface {
	/**
	 * Base of the persistent SLO uri of an entity.
	 */
	public const SLO_URI_BASE = 'https://opendata.slo.nl/curriculum/uuid/';

	/**
	 * The stable id of a framework.
	 *
	 * @param string $tenantId The learniq tenant uuid.
	 * @param string $setKey The set profile key.
	 * @param string|null $rootUuid The SLO root uuid, or null for an aggregate set.
	 *
	 * @return string An RFC 4122 UUID v5.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function frameworkUuid(string $tenantId, string $setKey, ?string $rootUuid): string;

	/**
	 * The stable id of a competency.
	 *
	 * @param string $frameworkUuid The owning framework's id.
	 * @param string $sloUuid The SLO uuid of the node.
	 *
	 * @return string An RFC 4122 UUID v5.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function competencyUuid(string $frameworkUuid, string $sloUuid): string;

	/**
	 * The framework record.
	 *
	 * @param string $uuid The framework id (from frameworkUuid()).
	 * @param array<string,mixed> $framework The normalised framework (name, sourceAuthority,
	 *                                       sourceRef, edition, level, description,
	 *                                       proficiencyLevels, tenantId).
	 * @param array<string,mixed> $mapping The framework mapping preset.
	 * @param string $originId The SLO root uuid, or the set key for an aggregate set.
	 *
	 * @return array<string,mixed> The record envelope.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
	 */
	public function frameworkRecord(string $uuid, array $framework, array $mapping, string $originId): array;

	/**
	 * The competency records, in the node order (parents first).
	 *
	 * @param array<int,array<string,mixed>> $nodes Walked nodes (from SloCurriculumTreeWalker::walk()).
	 * @param array<string,mixed> $context frameworkUuid, tenantId, yearNiveaus,
	 *                                     subjectCourseIds (normalised), subjectFrom
	 *                                     (`root` or `node`), rootSubjectKeys.
	 * @param array<string,mixed> $mapping The competency mapping preset.
	 *
	 * @return array<int,array<string,mixed>> Record envelopes.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function competencyRecords(array $nodes, array $context, array $mapping): array;

	/**
	 * Apply a mapping preset: a value naming an input field is copied, any
	 * other value is a literal (MappingService::executeMapping()'s first rule).
	 *
	 * @param array<string,mixed> $mapping Output key => input path or literal.
	 * @param array<string,mixed> $input The normalised record.
	 *
	 * @return array<string,mixed> The mapped object.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
	 */
	public function apply(array $mapping, array $input): array;

	/**
	 * The sha256 change-detection hash of a mapped object.
	 *
	 * @param array<string,mixed> $object The mapped object.
	 *
	 * @return string Lower-case hex sha256.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function originHash(array $object): string;
}
