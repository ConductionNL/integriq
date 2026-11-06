<?php

/**
 * Integriq Edurep CQL builder.
 *
 * Turns the user's words and the normalised filters into the CQL query
 * Edurep's SRU endpoint takes, escaping every value so the words cannot
 * change the query's structure.
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Builds Edurep CQL queries.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */
final class EdurepCqlBuilder {
	/**
	 * Filter key to the Edurep index it narrows.
	 *
	 * @var array<string,string>
	 */
	public const FILTER_INDEXES = [
		'levels' => 'lom.educational.context',
		'licenses' => 'lom.rights.copyrightandotherrestrictions',
		'formats' => 'lom.technical.format',
		'collection' => 'meta.repository.id',
	];

	/**
	 * Exclusion filter key to the index it leaves out. Edurep answers a NOT on its
	 * licence index with no results, so `excludeLicenses` narrows `licenses` instead.
	 *
	 * @var array<string,string>
	 */
	public const EXCLUDE_INDEXES = [
		'excludeFormats' => 'lom.technical.format',
		'excludeCollections' => 'meta.repository.id',
	];

	public const COLLECTION_INDEX = 'meta.repository.id';
	public const LANGUAGE_INDEX = 'lom.general.language';
	public const DEFAULT_LANGUAGE = 'nl';

	/**
	 * CQL boolean keywords, quoted when they appear as a word.
	 *
	 * @var list<string>
	 */
	private const KEYWORDS = ['and', 'or', 'not', 'prox'];

	/**
	 * Rights values Edurep indexes that have no LicenseCode.
	 *
	 * @var list<string>
	 */
	private const UNVERSIONED_LICENSES = ['cc-by-nd'];

	/**
	 * The search query: words and filters joined with AND, excluded collections with NOT.
	 *
	 * @param string $query The user's words.
	 * @param array<string,mixed> $filters The normalised filters.
	 *
	 * @return string The CQL query.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function search(string $query, array $filters): string {
		$clauses = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
		$clauses = array_map(fn (string $word): string => $this->escape(value: $word), (array)$clauses);
		foreach (self::FILTER_INDEXES as $key => $index) {
			$clauses[] = $this->anyOf(index: $index, values: $this->filterValues(filters: $filters, key: $key));
		}

		$clauses[] = $this->anyOf(index: self::LANGUAGE_INDEX, values: [(string)($filters['language'] ?? self::DEFAULT_LANGUAGE)]);

		return implode(' AND ', array_filter($clauses)) . $this->exclusions(filters: $filters);
	}//end search()

	/**
	 * The NOT clauses of the exclusion filters.
	 *
	 * @param array<string,mixed> $filters The normalised filters.
	 *
	 * @return string The clauses, each with its leading NOT.
	 */
	private function exclusions(array $filters): string {
		$cql = '';
		foreach (self::EXCLUDE_INDEXES as $key => $index) {
			foreach ($this->listOf(filters: $filters, key: $key) as $value) {
				$cql .= ' NOT ' . $index . '=' . $this->escape(value: $value);
			}
		}

		return $cql;
	}//end exclusions()

	/**
	 * The lookup query for one record: its quoted identifier, or the last path segment of one.
	 *
	 * @param string $id The record identifier, such as `urn:wikiwijsmaken:194413` or `/NTR/POMS/WO_NTR_20306689`.
	 *
	 * @return string The CQL query.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-find-returns-one-record-by-its-identifier
	 */
	public function find(string $id): string {
		$segments = array_values(array_filter(explode('/', trim($id)), static fn (string $segment): bool => $segment !== ''));
		$term = (string)($segments[array_key_last($segments) ?? 0] ?? '');

		return $this->quote(value: $term);
	}//end find()

	/**
	 * A value as a CQL term: bare when it is a plain word, quoted otherwise.
	 *
	 * @param string $value The value.
	 *
	 * @return string The term.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function escape(string $value): string {
		$plain = preg_match('/^[\p{L}\p{N}._:-]+$/u', $value) === 1;
		if ($plain === true && in_array(strtolower($value), self::KEYWORDS, true) === false) {
			return $value;
		}

		return $this->quote(value: $value);
	}//end escape()

	/**
	 * A value in double quotes with quotes, backslashes and masking characters escaped.
	 *
	 * @param string $value The value.
	 *
	 * @return string The quoted term.
	 */
	private function quote(string $value): string {
		return '"' . addcslashes($value, '"\\*?^') . '"';
	}//end quote()

	/**
	 * One index clause matching any of the values, empty for none.
	 *
	 * @param string $index The index.
	 * @param list<string> $values The values.
	 *
	 * @return string The clause.
	 */
	private function anyOf(string $index, array $values): string {
		$terms = [];
		foreach (array_filter($values, static fn (string $value): bool => $value !== '') as $value) {
			$terms[] = $index . '=' . $this->escape(value: $value);
		}

		if (count($terms) > 1) {
			return '(' . implode(' OR ', $terms) . ')';
		}

		return implode('', $terms);
	}//end anyOf()

	/**
	 * The values of one filter, licence codes translated to Edurep's codes.
	 *
	 * @param array<string,mixed> $filters The normalised filters.
	 * @param string $key The filter key.
	 *
	 * @return list<string> The values.
	 */
	private function filterValues(array $filters, string $key): array {
		if ($key === 'licenses') {
			return $this->licenseValues(filters: $filters);
		}

		return $this->listOf(filters: $filters, key: $key);
	}//end filterValues()

	/**
	 * The licence values to match: the chosen ones, or every known one, without the excluded ones.
	 *
	 * @param array<string,mixed> $filters The normalised filters.
	 *
	 * @return list<string> Edurep's rights values.
	 */
	private function licenseValues(array $filters): array {
		$chosen = $this->edurepCodes(codes: $this->listOf(filters: $filters, key: 'licenses'));
		$excluded = $this->edurepCodes(codes: $this->listOf(filters: $filters, key: 'excludeLicenses'));
		if ($excluded === []) {
			return $chosen;
		}

		if ($chosen === []) {
			$chosen = $this->allLicenses();
		}

		return array_values(array_diff($chosen, $excluded));
	}//end licenseValues()

	/**
	 * Every rights value Edurep indexes.
	 *
	 * @return list<string> The values.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) LicenseCode::cases is the enum's own list.
	 */
	private function allLicenses(): array {
		$known = array_map(static fn (LicenseCode $code): string => $code->toEdurep(), LicenseCode::cases());

		return array_merge($known, self::UNVERSIONED_LICENSES);
	}//end allLicenses()

	/**
	 * Normalised licence codes as Edurep's values; an unknown code is dropped.
	 *
	 * @param list<string> $codes The normalised codes.
	 *
	 * @return list<string> Edurep's values.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) LicenseCode::tryFrom is the enum's pure named constructor.
	 */
	private function edurepCodes(array $codes): array {
		$known = array_filter(array_map(static fn (string $code): ?LicenseCode => LicenseCode::tryFrom(value: $code), $codes));

		return array_values(array_map(static fn (LicenseCode $code): string => $code->toEdurep(), $known));
	}//end edurepCodes()

	/**
	 * The scalar values of one filter key as strings.
	 *
	 * @param array<string,mixed> $filters The normalised filters.
	 * @param string $key The filter key.
	 *
	 * @return list<string> The values.
	 */
	private function listOf(array $filters, string $key): array {
		return array_values(array_map('strval', array_filter((array)($filters[$key] ?? []), 'is_scalar')));
	}//end listOf()
}//end class
