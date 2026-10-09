<?php

/**
 * Integriq SLO curriculum mapper.
 *
 * Turns walked SLO nodes into learniq `competency-framework` and `competency`
 * records. Field names come from the seeded integriq mapping presets
 * (`slo-curriculum-framework-mapping`, `slo-curriculum-competency-mapping`),
 * applied with the first rule of `MappingService::executeMapping()`: a value
 * that names an input field is copied, any other value is a literal. That
 * subset is exact for these presets, so a later Synchronization running the
 * same mapping objects through MappingService yields the same objects.
 *
 * Ids are UUID v5 (design.md D4): the same tenant, set, root and SLO node
 * always give the same id, so a re-import updates and never duplicates, and a
 * parent's id is computed instead of looked up.
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

use Adbar\Dot;
use Symfony\Component\Uid\Factory\UuidFactory;

/**
 * Maps normalised SLO records onto learniq records with stable ids.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */
final class SloCurriculumMapper {
	/**
	 * UUID v5 namespace for every id this adapter derives. Never change it:
	 * every imported learniq object's id is derived from it.
	 */
	public const UUID_NAMESPACE = 'c2af4e70-7135-4b1b-9be9-85715348d906';

	/**
	 * Target register slug.
	 */
	public const REGISTER = 'learniq';

	/**
	 * Target schema slug for frameworks.
	 */
	public const FRAMEWORK_SCHEMA = 'competency-framework';

	/**
	 * Target schema slug for competencies.
	 */
	public const COMPETENCY_SCHEMA = 'competency';

	/**
	 * Base of the persistent SLO uri of an entity.
	 */
	public const SLO_URI_BASE = 'https://opendata.slo.nl/curriculum/uuid/';

	/**
	 * Constructor.
	 *
	 * @param SloYearAllocator $allocator Turns SLO niveaus into year labels.
	 */
	public function __construct(
		private readonly SloYearAllocator $allocator,
	) {
	}//end __construct()

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
	public function frameworkUuid(string $tenantId, string $setKey, ?string $rootUuid): string {
		$root = 'aggregate';
		if ($rootUuid !== null && $rootUuid !== '') {
			$root = $rootUuid;
		}

		return $this->uuid(name: sprintf('framework|%s|%s|%s', $tenantId, $setKey, $root));
	}//end frameworkUuid()

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
	public function competencyUuid(string $frameworkUuid, string $sloUuid): string {
		return $this->uuid(name: sprintf('competency|%s|%s', $frameworkUuid, $sloUuid));
	}//end competencyUuid()

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
	public function frameworkRecord(string $uuid, array $framework, array $mapping, string $originId): array {
		return $this->envelope(
			schema: self::FRAMEWORK_SCHEMA,
			uuid: $uuid,
			originId: $originId,
			object: $this->apply(mapping: $mapping, input: $framework)
		);
	}//end frameworkRecord()

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
	public function competencyRecords(array $nodes, array $context, array $mapping): array {
		$frameworkUuid = (string)$context['frameworkUuid'];
		$records = [];

		foreach ($nodes as $node) {
			$parentUuid = null;
			if ($node['parentSloUuid'] !== null) {
				$parentUuid = $this->competencyUuid(frameworkUuid: $frameworkUuid, sloUuid: (string)$node['parentSloUuid']);
			}

			$sloUuid = (string)$node['sloUuid'];
			$normalised = [
				'frameworkId' => $frameworkUuid,
				'parentId' => $parentUuid,
				'code' => (string)$node['code'],
				'title' => (string)$node['title'],
				'description' => $node['description'],
				'order' => (int)$node['order'],
				'applicableYears' => $this->allocator->allocate(
					niveaus: (array)$node['niveaus'],
					yearNiveaus: (array)($context['yearNiveaus'] ?? [])
				),
				'subjectId' => $this->subjectId(node: $node, context: $context),
				'tenantId' => (string)$context['tenantId'],
				'sloUuid' => $sloUuid,
				'sloType' => (string)$node['sloType'],
				'sloUri' => self::SLO_URI_BASE . $sloUuid,
			];

			$records[] = $this->envelope(
				schema: self::COMPETENCY_SCHEMA,
				uuid: $this->competencyUuid(frameworkUuid: $frameworkUuid, sloUuid: $sloUuid),
				originId: $sloUuid,
				object: $this->apply(mapping: $mapping, input: $normalised)
			);
		}//end foreach

		return $records;
	}//end competencyRecords()

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
	public function apply(array $mapping, array $input): array {
		$source = new Dot($input);
		$output = new Dot();

		foreach ($mapping as $key => $value) {
			if (is_string($value) === true && $source->has($value) === true) {
				$output->set((string)$key, $source->get($value));
				continue;
			}

			$output->set((string)$key, $value);
		}

		return $output->all();
	}//end apply()

	/**
	 * The sha256 change-detection hash of a mapped object.
	 *
	 * @param array<string,mixed> $object The mapped object.
	 *
	 * @return string Lower-case hex sha256.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function originHash(array $object): string {
		return hash(
			'sha256',
			json_encode($object, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
		);
	}//end originHash()

	/**
	 * The subject (a learniq Course uuid) of a top-level node, from the caller's map.
	 *
	 * @param array<string,mixed> $node The node.
	 * @param array<string,mixed> $context The mapping context.
	 *
	 * @return string|null The Course uuid, or null.
	 */
	private function subjectId(array $node, array $context): ?string {
		$subjects = (array)($context['subjectCourseIds'] ?? []);
		if ($node['parentSloUuid'] !== null || $subjects === []) {
			return null;
		}

		$keys = (array)($context['rootSubjectKeys'] ?? []);
		if (($context['subjectFrom'] ?? 'root') === 'node') {
			$keys = (array)($node['subjectKeys'] ?? []);
		}

		foreach ($keys as $key) {
			if (isset($subjects[$key]) === true) {
				return (string)$subjects[$key];
			}
		}

		return null;
	}//end subjectId()

	/**
	 * Wrap a mapped object in the record envelope.
	 *
	 * @param string $schema Target schema slug.
	 * @param string $uuid The record id.
	 * @param string $originId The SLO-side id.
	 * @param array<string,mixed> $object The mapped object.
	 *
	 * @return array<string,mixed> The envelope.
	 */
	private function envelope(string $schema, string $uuid, string $originId, array $object): array {
		return [
			'register' => self::REGISTER,
			'schema' => $schema,
			'uuid' => $uuid,
			'originId' => $originId,
			'originHash' => $this->originHash(object: $object),
			'object' => $object,
		];
	}//end envelope()

	/**
	 * A UUID v5 in this adapter's namespace.
	 *
	 * @param string $name The name to hash.
	 *
	 * @return string An RFC 4122 UUID.
	 */
	private function uuid(string $name): string {
		return (new UuidFactory())->nameBased(self::UUID_NAMESPACE)->create($name)->toRfc4122();
	}//end uuid()
}//end class
