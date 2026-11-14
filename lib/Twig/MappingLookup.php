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
 * @category Twig
 * @package  OCA\Integriq\Twig
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
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Twig;

use Adbar\Dot;
use OCA\Integriq\Service\SourceMappingServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One register lookup per distinct question, behind the lookup allowlist.
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
 */
class MappingLookup {

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
	 * Constructor.
	 *
	 * @param LookupAllowlist               $allowlist     The schemas that may be read.
	 * @param SourceMappingServiceInterface $objectService Gives OpenRegister's object service.
	 * @param LoggerInterface               $logger        Where a warning is recorded.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
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
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
	 */
	public function lookup(string $schema, array $filters, string $field, mixed $default = null): mixed {
		if ($this->allowlist->allows(schema: $schema) === false) {
			throw new RuntimeException(
				message: sprintf('lookup() may not read "%s": it is not on the lookup allowlist an administrator keeps.', $schema)
			);
		}

		ksort($filters);
		foreach ($filters as $name => $value) {
			if (is_scalar($value) === false && $value !== null) {
				throw new RuntimeException(message: sprintf('lookup() on "%s": the filter "%s" must be a single value.', $schema, (string)$name));
			}
		}

		$question = $schema . '|' . json_encode($filters);
		if (array_key_exists($question, $this->answers) === false) {
			$this->answers[$question] = $this->find(schema: $schema, filters: $filters);
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
	 * The warnings recorded in this run.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
	 */
	public function warnings(): array {
		return $this->warnings;
	}//end warnings()

	/**
	 * At most two matches: enough to tell one from several.
	 *
	 * Read in system context: the allowlist above is the gate.
	 *
	 * @param string              $schema  The schema, as `register/schema`.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function find(string $schema, array $filters): array {
		$openRegister = $this->objectService->getOpenRegisters();
		if ($openRegister === null) {
			throw new RuntimeException(message: 'lookup() needs OpenRegister, which is not installed.');
		}

		[$register, $slug] = explode('/', $schema, 2);
		$found = $openRegister->findAll(
			config: ['filters' => (['register' => $register, 'schema' => $slug] + $filters), 'limit' => 2],
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

		return array_slice($rows, 0, 2);
	}//end find()
}//end class
