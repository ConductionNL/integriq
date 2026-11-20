<?php

/**
 * One statement an administrator declared on a database source.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\Database
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\Database;

/**
 * One statement an administrator declared on a database source.
 *
 * A declaration is `{ name, kind: read|write, sql, parameters, pageKey,
 * pageStart, pageSize, timeout }`. The SQL uses named placeholders only, and
 * every placeholder is declared: a caller names a statement and sends values,
 * never SQL. A read with a `pageKey` pages by keyset, so its SQL binds
 * `:after` (the last key of the previous page) and `:limit`.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
final class DatabaseStatement {

	/**
	 * A statement that returns rows.
	 */
	public const KIND_READ = 'read';

	/**
	 * A statement that changes rows.
	 */
	public const KIND_WRITE = 'write';

	/**
	 * The placeholder a keyset read binds the previous page's last key to.
	 */
	public const AFTER = 'after';

	/**
	 * The placeholder a keyset read binds the page size to.
	 */
	public const LIMIT = 'limit';

	/**
	 * The parameter types a declaration may name.
	 */
	private const TYPES = ['string', 'integer', 'number', 'boolean'];

	/**
	 * Constructor; use {@see fromDeclaration()}.
	 *
	 * @param string               $name           The statement's name.
	 * @param string               $kind           read or write.
	 * @param string               $sql            The SQL with named placeholders.
	 * @param array<string,string> $parameters     The declared parameters and their types.
	 * @param string|null          $pageKey        The keyset column of a paged read.
	 * @param string|int           $pageStart      The key the first page reads after.
	 * @param int|null             $pageSize       The statement's own page size.
	 * @param int|null             $timeoutSeconds The statement's own timeout.
	 * @param array<int,string>    $placeholders   The placeholders the SQL uses.
	 */
	private function __construct(
		public readonly string $name,
		public readonly string $kind,
		public readonly string $sql,
		public readonly array $parameters,
		public readonly ?string $pageKey,
		public readonly string|int $pageStart,
		public readonly ?int $pageSize,
		public readonly ?int $timeoutSeconds,
		public readonly array $placeholders,
	) {
	}//end __construct()

	/**
	 * Read and check one declaration.
	 *
	 * @param array<string,mixed> $declaration The declaration as the source stores it.
	 *
	 * @return self The statement.
	 *
	 * @throws DatabaseSourceException When the declaration could run something it does not declare.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public static function fromDeclaration(array $declaration): self {
		[$name, $kind] = self::declaredNameAndKind(declaration: $declaration);
		[$sql, $code] = self::declaredSql(declaration: $declaration, name: $name);
		$parameters = self::declaredParameters(declaration: $declaration, name: $name);

		$pageKey = null;
		$reserved = [];
		$declaredPageKey = trim((string) ($declaration['pageKey'] ?? ''));
		if ($declaredPageKey !== '' && $kind === self::KIND_READ) {
			$pageKey = $declaredPageKey;
			$reserved = [self::AFTER, self::LIMIT];
		}

		preg_match_all('/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/', $code, $matches);
		$placeholders = array_values(array_unique($matches[1]));

		$missing = array_values(array_diff($reserved, $placeholders));
		if ($missing !== []) {
			throw new DatabaseSourceException(message: sprintf('Statement "%s" pages on %s, so its SQL must bind :%s.', $name, $pageKey, $missing[0]));
		}

		$undeclared = array_values(array_diff($placeholders, $reserved, array_map('strval', array_keys($parameters))));
		if ($undeclared !== []) {
			throw new DatabaseSourceException(message: sprintf('Statement "%s" binds :%s, which it does not declare as a parameter.', $name, $undeclared[0]));
		}

		$pageStart = ($declaration['pageStart'] ?? 0);
		if (is_int($pageStart) === false) {
			$pageStart = (string) $pageStart;
		}

		return new self(
			name: $name,
			kind: $kind,
			sql: $sql,
			parameters: $parameters,
			pageKey: $pageKey,
			pageStart: $pageStart,
			pageSize: self::positiveIntOrNull(value: ($declaration['pageSize'] ?? null)),
			timeoutSeconds: self::positiveIntOrNull(value: ($declaration['timeout'] ?? null)),
			placeholders: $placeholders
		);
	}//end fromDeclaration()

	/**
	 * The declared statement name and kind, checked.
	 *
	 * @param array<string,mixed> $declaration The declaration.
	 *
	 * @return array{0: string, 1: string} The name, and read or write.
	 *
	 * @throws DatabaseSourceException When the name is malformed or the kind is neither read nor write.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	private static function declaredNameAndKind(array $declaration): array {
		$name = trim((string) ($declaration['name'] ?? ''));
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/', $name) !== 1) {
			throw new DatabaseSourceException(message: 'A statement needs a name of letters, digits, dots, dashes or underscores.');
		}

		$kind = (string) ($declaration['kind'] ?? self::KIND_READ);
		if (in_array($kind, [self::KIND_READ, self::KIND_WRITE], true) === false) {
			throw new DatabaseSourceException(message: sprintf('Statement "%s" must be of kind read or write.', $name));
		}

		return [$name, $kind];
	}//end declaredNameAndKind()

	/**
	 * The declared SQL, trimmed of trailing semicolons and whitespace, and its code
	 * part (without literals and comments), checked to hold one statement with named
	 * placeholders.
	 *
	 * @param array<string,mixed> $declaration The declaration.
	 * @param string              $name        The statement name, for the message.
	 *
	 * @return array{0: string, 1: string} The SQL and its code part.
	 *
	 * @throws DatabaseSourceException When there is no SQL, more than one statement or a ? placeholder.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	private static function declaredSql(array $declaration, string $name): array {
		$sql = trim((string) ($declaration['sql'] ?? ''));
		$sql = rtrim($sql, "; \t\n\r");
		if ($sql === '') {
			throw new DatabaseSourceException(message: sprintf('Statement "%s" has no SQL.', $name));
		}

		$code = self::withoutLiteralsAndComments(sql: $sql);
		if (str_contains($code, ';') === true) {
			throw new DatabaseSourceException(message: sprintf('Statement "%s" holds more than one statement.', $name));
		}

		if (str_contains($code, '?') === true) {
			throw new DatabaseSourceException(message: sprintf('Statement "%s" uses a ? placeholder; name every placeholder (:name).', $name));
		}

		return [$sql, $code];
	}//end declaredSql()

	/**
	 * The values and types to bind: one per placeholder, nothing else.
	 *
	 * A value the statement does not declare is never bound and never reaches
	 * the SQL. A declared parameter without a value is bound as null.
	 *
	 * @param array<string,mixed> $values The caller's values, by name.
	 * @param string|int|null     $after  The previous page's last key, or null for the first page.
	 * @param int                 $limit  The page size.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,string>} The values and their types.
	 *
	 * @throws DatabaseSourceException When a value does not fit its declared type.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function bind(array $values, string|int|null $after, int $limit): array {
		$bound = [];
		$types = [];
		foreach ($this->placeholders as $placeholder) {
			if ($this->pageKey !== null && $placeholder === self::LIMIT) {
				$bound[$placeholder] = $limit;
				$types[$placeholder] = 'integer';
				continue;
			}

			if ($this->pageKey !== null && $placeholder === self::AFTER) {
				$cursor = ($after ?? $this->pageStart);
				$bound[$placeholder] = $cursor;
				$types[$placeholder] = 'string';
				if (is_int($cursor) === true) {
					$types[$placeholder] = 'integer';
				}
				continue;
			}

			[$bound[$placeholder], $types[$placeholder]] = $this->typed(
				name: $placeholder,
				type: $this->parameters[$placeholder],
				value: ($values[$placeholder] ?? null)
			);
		}//end foreach

		return [$bound, $types];
	}//end bind()

	/**
	 * Whether this statement returns rows.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function isRead(): bool {
		return $this->kind === self::KIND_READ;
	}//end isRead()

	/**
	 * One value in its declared type.
	 *
	 * @param string $name  The parameter.
	 * @param string $type  Its declared type.
	 * @param mixed  $value The caller's value.
	 *
	 * @return array{0:mixed,1:string} The value and its bind type.
	 *
	 * @throws DatabaseSourceException When the value does not fit.
	 */
	private function typed(string $name, string $type, mixed $value): array {
		if ($value === null) {
			return [null, 'null'];
		}

		if (is_array($value) === true || is_object($value) === true) {
			throw new DatabaseSourceException(message: sprintf('Parameter "%s" of statement "%s" takes one value, not a list.', $name, $this->name));
		}

		switch ($type) {
			case 'integer':
				if (filter_var($value, FILTER_VALIDATE_INT) === false) {
					throw new DatabaseSourceException(message: sprintf('Parameter "%s" of statement "%s" must be a whole number.', $name, $this->name));
				}
				return [(int) $value, 'integer'];
			case 'boolean':
				return [filter_var($value, FILTER_VALIDATE_BOOLEAN), 'boolean'];
			case 'number':
				if (is_numeric($value) === false) {
					throw new DatabaseSourceException(message: sprintf('Parameter "%s" of statement "%s" must be a number.', $name, $this->name));
				}
				return [(string) $value, 'string'];
			default:
				return [(string) $value, 'string'];
		}
	}//end typed()

	/**
	 * The declared parameters: a list of names, or of `{name, type}`.
	 *
	 * @param array<string,mixed> $declaration The declaration.
	 * @param string              $name        The statement's name, for messages.
	 *
	 * @return array<string,string> Parameter name to type.
	 *
	 * @throws DatabaseSourceException When a parameter is unnamed, reserved or of an unknown type.
	 */
	private static function declaredParameters(array $declaration, string $name): array {
		$declared = ($declaration['parameters'] ?? []);
		if (is_array($declared) === false) {
			$declared = [];
		}

		$parameters = [];
		foreach ($declared as $parameter) {
			if (is_string($parameter) === true) {
				$parameter = ['name' => $parameter];
			}

			if (is_array($parameter) === false) {
				continue;
			}

			$parameterName = trim((string) ($parameter['name'] ?? ''));
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parameterName) !== 1) {
				throw new DatabaseSourceException(message: sprintf('Statement "%s" declares a parameter without a usable name.', $name));
			}

			if (in_array($parameterName, [self::AFTER, self::LIMIT], true) === true && trim((string) ($declaration['pageKey'] ?? '')) !== '') {
				throw new DatabaseSourceException(message: sprintf('Statement "%s" pages by keyset, so :%s is bound by integriq.', $name, $parameterName));
			}

			$type = (string) ($parameter['type'] ?? 'string');
			if (in_array($type, self::TYPES, true) === false) {
				throw new DatabaseSourceException(message: sprintf('Parameter "%s" of statement "%s" has the unknown type "%s".', $parameterName, $name, $type));
			}

			$parameters[$parameterName] = $type;
		}//end foreach

		return $parameters;
	}//end declaredParameters()

	/**
	 * The SQL with string literals, quoted identifiers and comments blanked, so
	 * a `;`, `?` or `:word` inside them is not read as code.
	 *
	 * @param string $sql The SQL.
	 *
	 * @return string The code alone.
	 */
	private static function withoutLiteralsAndComments(string $sql): string {
		return (string) preg_replace(
			['/\'(?:[^\']|\'\')*\'/s', '/"(?:[^"]|"")*"/s', '/`[^`]*`/s', '/\[[^\]]*\]/s', '/--[^\n]*/', '#/\*.*?\*/#s'],
			' ',
			$sql
		);
	}//end withoutLiteralsAndComments()

	/**
	 * A positive whole number, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private static function positiveIntOrNull(mixed $value): ?int {
		if ($value === null || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
			return null;
		}

		return (int) $value;
	}//end positiveIntOrNull()
}//end class
