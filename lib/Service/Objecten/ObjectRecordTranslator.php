<?php

/**
 * An OpenRegister object as the standard's record, and nothing else.
 *
 * 🔴 IT IS AN ALLOWLIST, NOT A BLOCKLIST. The requirement says no OpenRegister
 * field name a VNG consumer does not expect may reach them, and the only way to
 * keep that true is to name what goes OUT. A blocklist of known metadata keys
 * is correct until OpenRegister adds a field — and then it ships that field to
 * every counterparty in the landscape, silently, in a release nobody connected
 * to the leak.
 *
 * 🔑 THE OBJECT'S OWN DATA IS THE ONE PLACE THAT IS NOT FILTERED, because it is
 * the schema's, not OpenRegister's. But `@self` and `id`-shaped metadata can
 * appear INSIDE it when a caller wrote them there, so the payload is stripped
 * of the metadata envelope before it becomes `record.data`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

/**
 * Renders the Objecten API's record shape from an OpenRegister object.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjectRecordTranslator {

	/**
	 * The metadata envelope OpenRegister wraps an object in.
	 *
	 * @var string
	 */
	public const METADATA_KEY = '@self';

	/**
	 * The fields a record carries, and no others.
	 *
	 * @var array<int, string>
	 */
	public const RECORD_FIELDS = ['index', 'typeVersion', 'data', 'geometry', 'startAt', 'endAt', 'registrationAt', 'correctionFor', 'correctedBy'];

	/**
	 * The fields the envelope around a record carries.
	 *
	 * @var array<int, string>
	 */
	public const OBJECT_FIELDS = ['url', 'uuid', 'type', 'record'];

	/**
	 * Keys that must never appear inside `record.data`.
	 *
	 * Belt and braces beside the allowlist: these are the OpenRegister
	 * metadata spellings that end up INSIDE a payload when a caller writes
	 * them there, so stripping the envelope alone would not remove them.
	 *
	 * @var array<int, string>
	 */
	public const STRIPPED_FROM_DATA = ['@self', 'id', '_id', 'register', 'schema', 'uuid', 'files', 'relations', 'locked', 'owner', 'organisation'];

	/**
	 * Render one object as a record.
	 *
	 * @param array<string, mixed> $object     The OpenRegister object.
	 * @param string               $objecttype The published objecttype uuid.
	 * @param string               $baseUrl    The API base, for `url`.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function toRecord(array $object, string $objecttype, string $baseUrl = ''): array {
		$metadata = ((array)($object[self::METADATA_KEY] ?? []));
		$uuid = trim((string)($metadata['id'] ?? $object['id'] ?? $object['uuid'] ?? ''));

		$record = [
			'index' => 1,
			'typeVersion' => (int)($metadata['version'] ?? 1),
			'data' => $this->dataOf(object: $object),
			'geometry' => ($object['geometry'] ?? null),
			'startAt' => $this->dateOf(value: ($metadata['created'] ?? null)),
			'endAt' => null,
			'registrationAt' => $this->dateOf(value: ($metadata['created'] ?? null)),
			'correctionFor' => null,
			'correctedBy' => null,
		];

		$url = '';
		if ($baseUrl !== '') {
			$url = rtrim($baseUrl, '/') . '/objects/' . $uuid;
		}

		return [
			'url' => $url,
			'uuid' => $uuid,
			'type' => $objecttype,
			'record' => $record,
		];
	}//end toRecord()

	/**
	 * The object's own data, with the metadata envelope removed.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function dataOf(array $object): array {
		$data = $object;
		foreach (self::STRIPPED_FROM_DATA as $key) {
			unset($data[$key]);
		}

		return $data;
	}//end dataOf()

	/**
	 * Every field name this record exposes, for the leak guard.
	 *
	 * 🔑 THE GUARD IS ASSERTABLE, not a claim in a comment. A test walks a
	 * rendered record and refuses any envelope key outside
	 * {@see self::OBJECT_FIELDS} and {@see self::RECORD_FIELDS} — so a field
	 * added to the translator without being added to the vocabulary fails
	 * there rather than in somebody's landscape.
	 *
	 * @param array<string, mixed> $rendered The rendered record.
	 *
	 * @return array<int, string> Field names outside the standard's shape.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function foreignFieldsIn(array $rendered): array {
		$foreign = [];

		foreach (array_keys($rendered) as $key) {
			if (in_array((string)$key, self::OBJECT_FIELDS, true) === false) {
				$foreign[] = (string)$key;
			}
		}

		foreach (array_keys((array)($rendered['record'] ?? [])) as $key) {
			if (in_array((string)$key, self::RECORD_FIELDS, true) === false) {
				$foreign[] = 'record.' . (string)$key;
			}
		}

		foreach (array_keys((array)($rendered['record']['data'] ?? [])) as $key) {
			if (in_array((string)$key, self::STRIPPED_FROM_DATA, true) === true) {
				$foreign[] = 'record.data.' . (string)$key;
			}
		}

		return $foreign;
	}//end foreignFieldsIn()

	/**
	 * A date as the standard writes it, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The date.
	 */
	private function dateOf(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$time = strtotime($value);
		if ($time === false) {
			return null;
		}

		// The standard's record dates are dates, not instants: `startAt` and
		// `registrationAt` are `YYYY-MM-DD`, and handing a consumer a full
		// timestamp is a shape their parser may or may not accept.
		return date('Y-m-d', $time);
	}//end dateOf()
}//end class
