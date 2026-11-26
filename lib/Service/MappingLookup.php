<?php

/**
 * Integriq MappingLookup.
 *
 * Backs the `lookup(schema, filters, field, default)` mapping function: the
 * `field` of the single object in an allowed register schema that matches the
 * filters, or the default. A schema off the allowlist fails the mapping and
 * reads nothing; more than one match returns the default and records a
 * warning, because picking the first would make a code translation depend on
 * insertion order. It never calls out over HTTP. Answers are cached for the
 * life of this object, one mapping run, so a thousand items with the same code
 * make one query (mapping-formats-and-lookups D4).
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use Adbar\Dot;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One register lookup per distinct question, behind the lookup allowlist.
 *
 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
 */
class MappingLookup implements MappingLookupInterface {

	/**
	 * Answers already found: question => the matches (0, 1 or 2).
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $answers = [];

	/**
	 * The warnings of this run, oldest first.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * The most objects `lookupAll()` answers with.
	 *
	 * @var integer
	 */
	public const MAX_ALL = 200;

	/**
	 * Constructor.
	 *
	 * @param LookupAllowlist               $allowlist     The schemas that may be read.
	 * @param SourceMappingServiceInterface $objectService Gives OpenRegister's object service.
	 * @param LoggerInterface               $logger        Where a warning is recorded.
	 *
	 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
	 */
	public function __construct(
		private readonly LookupAllowlist $allowlist,
		private readonly SourceMappingServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The field of the one object matching the filters, else the default.
	 *
	 * @param string              $schema  The schema, as `register/schema`.
	 * @param array<string,mixed> $filters Property filters; values must be scalar.
	 * @param string              $field   The field to return, a dot path.
	 * @param mixed               $default What none or several matches return.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When the schema is not allowed, a filter is not scalar, or OpenRegister is missing.
	 *
	 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
	 */
	public function lookup(string $schema, array $filters, string $field, mixed $default = null): mixed {
		$filters = $this->guard(function: 'lookup', schema: $schema, filters: $filters);

		$question = $schema . '|' . json_encode($filters);
		if (array_key_exists($question, $this->answers) === false) {
			$this->answers[$question] = $this->find(schema: $schema, filters: $filters, limit: 2);
		}

		$matches = $this->answers[$question];
		if (count($matches) > 1) {
			$warning = sprintf('lookup() on "%s" found more than one object for %s; the default is used.', $schema, (string)json_encode($filters));
			$this->warnings[] = $warning;
			$this->logger->warning('[MappingLookup] ' . $warning);
			return $default;
		}

		if ($matches === []) {
			return $default;
		}

		return (new Dot($matches[0]))->get($field, $default);
	}//end lookup()

	/**
	 * The field of every object matching the filters, in the order OpenRegister returns them.
	 *
	 * For a question about a set rather than one row: "has this employee any
	 * assignment that has not ended". An object without the field contributes
	 * null, so a missing end date stays visible as "no end". More
	 * matches than the limit refuse the mapping instead of answering from a
	 * partial list, because a set read short could make an open row look
	 * absent (staff-timetable-sync REQ-HRS-004: never act on a partial read).
	 *
	 * @param string              $schema  The schema, as `register/schema`.
	 * @param array<string,mixed> $filters Property filters; values must be scalar.
	 * @param string              $field   The field to return per object, a dot path.
	 * @param integer             $limit   The most objects answered, at most MAX_ALL.
	 *
	 * @return list<mixed>
	 *
	 * @throws RuntimeException When the schema is not allowed, a filter is not scalar, more objects match than the limit, or OpenRegister is missing.
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
	 */
	public function lookupAll(string $schema, array $filters, string $field, int $limit = self::MAX_ALL): array {
		$filters = $this->guard(function: 'lookupAll', schema: $schema, filters: $filters);
		$limit   = max(1, min($limit, self::MAX_ALL));

		$question = 'all|' . $limit . '|' . $schema . '|' . json_encode($filters);
		if (array_key_exists($question, $this->answers) === false) {
			$this->answers[$question] = $this->find(schema: $schema, filters: $filters, limit: ($limit + 1));
		}

		$matches = $this->answers[$question];
		if (count($matches) > $limit) {
			$asked = (string)json_encode($filters);
			throw new RuntimeException(
				message: sprintf('lookupAll() on "%s" found more than %d objects for %s; it does not answer from a partial list.', $schema, $limit, $asked)
			);
		}

		return array_map(static fn (array $match): mixed => (new Dot($match))->get($field), $matches);
	}//end lookupAll()

	/**
	 * Refuse a schema off the allowlist or a filter that is not one value; the filters sorted.
	 *
	 * @param string              $function The mapping function asking, for the message.
	 * @param string              $schema   The schema, as `register/schema`.
	 * @param array<string,mixed> $filters  The filters.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RuntimeException When the schema is not allowed or a filter is not scalar.
	 *
	 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
	 */
	private function guard(string $function, string $schema, array $filters): array {
		if ($this->allowlist->allows(schema: $schema) === false) {
			throw new RuntimeException(
				message: sprintf('%s() may not read "%s": it is not on the lookup allowlist an administrator keeps.', $function, $schema)
			);
		}

		ksort($filters);
		foreach ($filters as $name => $value) {
			if (is_scalar($value) === false && $value !== null) {
				throw new RuntimeException(message: sprintf('%s() on "%s": the filter "%s" must be a single value.', $function, $schema, (string)$name));
			}
		}

		return $filters;
	}//end guard()

	/**
	 * The warnings recorded in this run.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
	 */
	public function warnings(): array {
		return $this->warnings;
	}//end warnings()

	/**
	 * At most `$limit` matches: two tell one from several, limit + 1 tells a whole list from a cut one.
	 *
	 * Read in system context: the allowlist above is the gate.
	 *
	 * @param string              $schema  The schema, as `register/schema`.
	 * @param array<string,mixed> $filters The filters.
	 * @param integer             $limit   The most rows read.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function find(string $schema, array $filters, int $limit): array {
		$openRegister = $this->objectService->getOpenRegisters();
		if ($openRegister === null) {
			throw new RuntimeException(message: 'lookup() needs OpenRegister, which is not installed.');
		}

		[$register, $slug] = explode('/', $schema, 2);
		$found = $openRegister->findAll(
			config: ['filters' => (['register' => $register, 'schema' => $slug] + $filters), 'limit' => $limit],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$row = $row->getObject();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return array_slice($rows, 0, $limit);
	}//end find()
}//end class
