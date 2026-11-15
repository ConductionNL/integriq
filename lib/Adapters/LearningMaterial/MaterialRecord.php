<?php

/**
 * Integriq learning material record.
 *
 * One learning material in the shape every learning material source maps
 * to, so a consumer reads Edurep and any later source the same way.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Normalised learning material record.
 *
 * Built from a field map so a source maps its own format once; a field the
 * map leaves out gets its empty value. The fields are set once, by the
 * constructor and its helpers, and never changed after.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 *
 * @SuppressWarnings(PHPMD.TooManyFields) The spec fixes the normalised record at exactly these seventeen fields.
 */
final class MaterialRecord {
	/**
	 * The source id.
	 *
	 * @var string
	 */
	public string $sourceId;

	/**
	 * The title.
	 *
	 * @var string
	 */
	public string $title;

	/**
	 * The description.
	 *
	 * @var string
	 */
	public string $description;

	/**
	 * The url.
	 *
	 * @var string
	 */
	public string $url;

	/**
	 * The embed url.
	 *
	 * @var ?string
	 */
	public ?string $embedUrl;

	/**
	 * The material type.
	 *
	 * @var string
	 */
	public string $materialType;

	/**
	 * The language.
	 *
	 * @var string
	 */
	public string $language;

	/**
	 * The collection.
	 *
	 * @var string
	 */
	public string $collection;

	/**
	 * The published.
	 *
	 * @var ?string
	 */
	public ?string $published;

	/**
	 * The source.
	 *
	 * @var string
	 */
	public string $source;

	/**
	 * The license.
	 *
	 * @var LicenseCode
	 */
	public LicenseCode $license;

	/**
	 * The license url.
	 *
	 * @var ?string
	 */
	public ?string $licenseUrl;

	/**
	 * The access rights.
	 *
	 * @var ?string
	 */
	public ?string $accessRights;

	/**
	 * Files, each with `url`, `format` and `accessRights`.
	 *
	 * @var list<array{url: string, format: string, accessRights: string|null}>
	 */
	public array $files;

	/**
	 * Names of the makers.
	 *
	 * @var list<string>
	 */
	public array $authors;

	/**
	 * NL-LOM educational context codes.
	 *
	 * @var list<string>
	 */
	public array $levels;

	/**
	 * Subject names.
	 *
	 * @var list<string>
	 */
	public array $disciplines;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $fields The record's fields by name.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function __construct(array $fields) {
		$this->sourceId = (string)($fields['sourceId'] ?? '');
		$this->title = (string)($fields['title'] ?? '');
		$this->description = (string)($fields['description'] ?? '');
		$this->url = (string)($fields['url'] ?? '');
		$this->materialType = (string)($fields['materialType'] ?? '');
		$this->language = (string)($fields['language'] ?? '');
		$this->collection = (string)($fields['collection'] ?? '');
		$this->source = (string)($fields['source'] ?? '');
		$this->assignOptional(fields: $fields);
		$this->assignLists(fields: $fields);
	}//end __construct()

	/**
	 * The record as a plain array, the licence as its code.
	 *
	 * @return array<string,mixed> Every field by name.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function toArray(): array {
		$fields = get_object_vars($this);
		$fields['license'] = $this->license->value;

		return $fields;
	}//end toArray()

	/**
	 * Assign the nullable fields and the licence.
	 *
	 * @param array<string,mixed> $fields The record's fields by name.
	 *
	 * @return void
	 */
	private function assignOptional(array $fields): void {
		$this->embedUrl = self::nullable(value: $fields['embedUrl'] ?? null);
		$this->published = self::nullable(value: $fields['published'] ?? null);
		$this->licenseUrl = self::nullable(value: $fields['licenseUrl'] ?? null);
		$this->accessRights = self::nullable(value: $fields['accessRights'] ?? null);
		$this->license = self::license(value: $fields['license'] ?? null);
	}//end assignOptional()

	/**
	 * Assign the list fields.
	 *
	 * @param array<string,mixed> $fields The record's fields by name.
	 *
	 * @return void
	 */
	private function assignLists(array $fields): void {
		$this->files = self::files(values: $fields['files'] ?? []);
		$this->authors = self::strings(values: $fields['authors'] ?? []);
		$this->levels = self::strings(values: $fields['levels'] ?? []);
		$this->disciplines = self::strings(values: $fields['disciplines'] ?? []);
	}//end assignLists()

	/**
	 * The licence code, unknown when the value is none.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return LicenseCode The code.
	 */
	private static function license(mixed $value): LicenseCode {
		if ($value instanceof LicenseCode) {
			return $value;
		}

		return LicenseCode::UNKNOWN;
	}//end license()

	/**
	 * The files, each with a string `url` and `format` and a nullable `accessRights`.
	 *
	 * @param mixed $values The raw list.
	 *
	 * @return list<array{url: string, format: string, accessRights: string|null}> The files.
	 */
	private static function files(mixed $values): array {
		$files = [];
		foreach (array_filter((array)$values, 'is_array') as $file) {
			$files[] = [
				'url' => (string)($file['url'] ?? ''),
				'format' => (string)($file['format'] ?? ''),
				'accessRights' => self::nullable(value: $file['accessRights'] ?? null),
			];
		}

		return $files;
	}//end files()

	/**
	 * A string, or null when it is empty.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The string or null.
	 */
	private static function nullable(mixed $value): ?string {
		if (is_scalar($value) === false || (string)$value === '') {
			return null;
		}

		return (string)$value;
	}//end nullable()

	/**
	 * A list of strings.
	 *
	 * @param mixed $values The raw list.
	 *
	 * @return list<string> The values as strings.
	 */
	private static function strings(mixed $values): array {
		return array_values(array_map('strval', array_filter((array)$values, 'is_scalar')));
	}//end strings()
}//end class
