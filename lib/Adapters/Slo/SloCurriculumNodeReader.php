<?php

/**
 * Integriq SLO curriculum node reader.
 *
 * Reads the facts of one SLO entity the import needs: its uuid, the code,
 * title and description a set profile's field paths point at, its SLO
 * niveaus, and the keys a caller's subject map can match. Links (`@link`)
 * are resolved against the index of the documents read so far, so a niveau
 * that JSONTag wrote once and linked afterwards still yields its title.
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

/**
 * Reads the import-relevant facts of SLO entities.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
 */
final class SloCurriculumNodeReader {
	/**
	 * Default field paths when a profile names none for a type.
	 */
	private const DEFAULT_FIELDS = [
		'code' => ['prefix', 'title'],
		'title' => ['title'],
		'description' => ['description'],
	];

	/**
	 * Constructor.
	 *
	 * @param JsonTagReader $reader Resolves links and id segments.
	 */
	public function __construct(
		private readonly JsonTagReader $reader,
	) {
	}//end __construct()

	/**
	 * The SLO uuid of an entity: `uuid`, else `id`, else the tail of `@id` or `@link`.
	 *
	 * @param array<string,mixed> $entity An SLO entity or reference.
	 *
	 * @return string The uuid, or '' when there is none.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
	 */
	public function uuidOf(array $entity): string {
		foreach (['uuid', 'id', '@id', '@link'] as $key) {
			if (is_string($entity[$key] ?? null) === true && $entity[$key] !== '') {
				return $this->reader->lastSegment(value: $entity[$key]);
			}
		}

		return '';
	}//end uuidOf()

	/**
	 * Identity and headline facts of one entity.
	 *
	 * @param array<string,mixed> $entity An SLO entity.
	 * @param array<string,array<string,mixed>> $index Objects by id, for links.
	 *
	 * @return array{uuid:string,type:string,title:string,status:string|null,versie:string|null,subjectKeys:array<int,string>}
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function describe(array $entity, array $index = []): array {
		return [
			'uuid' => $this->uuidOf(entity: $entity),
			'type' => (string)($entity['@type'] ?? ''),
			'title' => trim((string)($entity['title'] ?? '')),
			'status' => $this->optionalString(value: ($entity['status'] ?? null)),
			'versie' => $this->optionalString(value: ($entity['versie'] ?? null)),
			'subjectKeys' => $this->subjectKeys(entity: $entity, index: $index),
		];
	}//end describe()

	/**
	 * The normalised record of one node.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param array<string,mixed> $context uuid, type, parentUuid, isLeaf and the
	 *                                     profile's `fields` member.
	 * @param array<string,array<string,mixed>> $index Objects by id, for links.
	 *
	 * @return array<string,mixed> sloUuid, sloType, code, title, description, parentSloUuid,
	 *                             order, isLeaf, niveaus, subjectKeys.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
	 */
	public function record(array $entity, array $context, array $index): array {
		$uuid = (string)$context['uuid'];
		$type = (string)$context['type'];
		$fields = array_replace(self::DEFAULT_FIELDS, (array)($context['fields'][$type] ?? []));
		$texts = $this->texts(entity: $entity, fields: $fields, index: $index, uuid: $uuid);

		return [
			'sloUuid' => $uuid,
			'sloType' => $type,
			'code' => $texts['code'],
			'title' => $texts['title'],
			'description' => $texts['description'],
			'parentSloUuid' => $context['parentUuid'],
			'order' => 0,
			'isLeaf' => (bool)$context['isLeaf'],
			'niveaus' => $this->niveaus(entity: $entity, index: $index),
			'subjectKeys' => $this->subjectKeys(entity: $entity, index: $index),
		];
	}//end record()

	/**
	 * The SLO niveaus an entity is tagged with.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param array<string,array<string,mixed>> $index Objects by id, for links.
	 *
	 * @return array<int,array{uuid:string,title:string|null}> Niveaus.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
	 */
	public function niveaus(array $entity, array $index): array {
		$niveaus = [];
		foreach ($this->listOf(value: ($entity['Niveau'] ?? $entity['NiveauIndex'] ?? [])) as $item) {
			$niveau = $this->reader->resolve(value: $item, index: $index);
			if (is_array($niveau) === false || $this->uuidOf(entity: $niveau) === '') {
				continue;
			}

			$niveaus[] = ['uuid' => $this->uuidOf(entity: $niveau), 'title' => $this->optionalString(value: ($niveau['title'] ?? null))];
		}

		return $niveaus;
	}//end niveaus()

	/**
	 * Keys a caller's subject map can use for this entity: its vakleergebied's
	 * uuid and lower-cased title, then its own.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param array<string,array<string,mixed>> $index Objects by id, for links.
	 *
	 * @return array<int,string> Keys, most specific first.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function subjectKeys(array $entity, array $index): array {
		$subject = ($this->listOf(value: ($entity['Vakleergebied'] ?? []))[0] ?? null);
		$subject = $this->reader->resolve(value: $subject, index: $index);

		$keys = [];
		foreach ([$subject, $entity] as $candidate) {
			if (is_array($candidate) === false) {
				continue;
			}

			$keys[] = $this->uuidOf(entity: $candidate);
			$keys[] = mb_strtolower(trim((string)($candidate['title'] ?? '')));
		}

		return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
	}//end subjectKeys()

	/**
	 * Code, title and description, with the fallbacks that keep code and title non-empty.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param array<string,mixed> $fields Field paths per output field.
	 * @param array<string,array<string,mixed>> $index Objects by id, for links.
	 * @param string $uuid The entity's uuid, the last fallback.
	 *
	 * @return array{code:string,title:string,description:string|null} The texts.
	 */
	private function texts(array $entity, array $fields, array $index, string $uuid): array {
		$code = $this->firstText(entity: $entity, paths: (array)$fields['code'], index: $index);
		$title = $this->firstText(entity: $entity, paths: (array)$fields['title'], index: $index);
		$description = $this->firstText(entity: $entity, paths: (array)$fields['description'], index: $index);

		$code = ($code ?? $title ?? $uuid);
		$title = ($title ?? $code);

		return ['code' => $code, 'title' => $title, 'description' => $description];
	}//end texts()

	/**
	 * The first non-empty text among dot paths into the entity.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param array<int,mixed> $paths Candidate paths, such as `title` or `Doel.0.title`.
	 * @param array<string,array<string,mixed>> $index Objects by id, for links.
	 *
	 * @return string|null The trimmed text, or null.
	 */
	private function firstText(array $entity, array $paths, array $index): ?string {
		foreach ($paths as $path) {
			$text = $this->optionalString(value: $this->valueAt(entity: $entity, path: (string)$path, index: $index));
			if ($text !== null) {
				return $text;
			}
		}

		return null;
	}//end firstText()

	/**
	 * Read a dot path, resolving links on the way.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param string $path The dot path.
	 * @param array<string,array<string,mixed>> $index Objects by id, for links.
	 *
	 * @return mixed The value, or null when the path does not exist.
	 */
	private function valueAt(array $entity, string $path, array $index): mixed {
		$current = $entity;
		foreach (explode('.', $path) as $segment) {
			$current = $this->reader->resolve(value: $current, index: $index);
			if (is_array($current) === false || array_key_exists($segment, $current) === false) {
				return null;
			}

			$current = $current[$segment];
		}

		return $this->reader->resolve(value: $current, index: $index);
	}//end valueAt()

	/**
	 * A list from a list, a single object, or anything else (empty).
	 *
	 * @param mixed $value A decoded value.
	 *
	 * @return array<int,mixed> The list.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
	 */
	public function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		if (array_is_list($value) === false) {
			return [$value];
		}

		return $value;
	}//end listOf()

	/**
	 * A non-empty trimmed string (numbers count), or null.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return string|null The string, or null.
	 */
	private function optionalString(mixed $value): ?string {
		if (is_string($value) === false && is_int($value) === false && is_float($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end optionalString()
}//end class
