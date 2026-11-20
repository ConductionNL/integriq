<?php

/**
 * A database statement declaration is checked before anything runs.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseSourceException;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only named, declared placeholders; one statement; values bound by type.
 */
#[CoversClass(DatabaseStatement::class)]
class DatabaseStatementTest extends TestCase {

	/**
	 * The seed's keyset read is a valid declaration that binds after and limit itself.
	 *
	 * @return void
	 */
	public function testAKeysetReadBindsTheCursorAndThePageSize(): void {
		$statement = DatabaseStatement::fromDeclaration(declaration: [
			'name' => 'zaken-sinds',
			'kind' => 'read',
			'sql' => 'SELECT id, zaaknummer FROM zaken WHERE id > :after AND status = :status ORDER BY id LIMIT :limit;',
			'parameters' => [['name' => 'status']],
			'pageKey' => 'id',
		]);

		self::assertSame('SELECT id, zaaknummer FROM zaken WHERE id > :after AND status = :status ORDER BY id LIMIT :limit', $statement->sql);
		self::assertSame(['after', 'status', 'limit'], $statement->placeholders);

		[$values, $types] = $statement->bind(values: ['status' => 'open', 'unrelated' => 'x; DROP TABLE zaken'], after: null, limit: 500);
		self::assertSame(['after' => 0, 'status' => 'open', 'limit' => 500], $values);
		self::assertSame(['after' => 'integer', 'status' => 'string', 'limit' => 'integer'], $types);

		[$values] = $statement->bind(values: [], after: 'Z-0042', limit: 10);
		self::assertSame(['after' => 'Z-0042', 'status' => null, 'limit' => 10], $values);
	}//end testAKeysetReadBindsTheCursorAndThePageSize()

	/**
	 * Placeholders inside literals, quoted names and comments are not code; casts are not placeholders.
	 *
	 * @return void
	 */
	public function testLiteralsCommentsAndCastsAreNotPlaceholders(): void {
		$statement = DatabaseStatement::fromDeclaration(declaration: [
			'name' => 'naam',
			'sql' => "SELECT id::text, ':nope; ?' AS x, \"col:y\" FROM t -- :comment ?\nWHERE code = :code /* :block; */",
			'parameters' => ['code'],
		]);

		self::assertSame(['code'], $statement->placeholders);
		self::assertNull($statement->pageKey);
	}//end testLiteralsCommentsAndCastsAreNotPlaceholders()

	/**
	 * Values are bound in their declared type, and a value that does not fit is refused.
	 *
	 * @return void
	 */
	public function testValuesAreBoundInTheirDeclaredType(): void {
		$statement = DatabaseStatement::fromDeclaration(declaration: [
			'name' => 'w',
			'kind' => 'write',
			'sql' => 'UPDATE t SET actief = :actief, bedrag = :bedrag WHERE id = :id',
			'parameters' => [['name' => 'id', 'type' => 'integer'], ['name' => 'actief', 'type' => 'boolean'], ['name' => 'bedrag', 'type' => 'number']],
		]);

		[$values, $types] = $statement->bind(values: ['id' => '7', 'actief' => 'true', 'bedrag' => 12.5], after: null, limit: 0);
		self::assertSame(['actief' => true, 'bedrag' => '12.5', 'id' => 7], $values);
		self::assertSame(['actief' => 'boolean', 'bedrag' => 'string', 'id' => 'integer'], $types);

		$this->expectException(DatabaseSourceException::class);
		$this->expectExceptionMessage('Parameter "id" of statement "w" must be a whole number.');
		$statement->bind(values: ['id' => '7 OR 1=1'], after: null, limit: 0);
	}//end testValuesAreBoundInTheirDeclaredType()

	/**
	 * Declarations that could run something they do not declare.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function refusedDeclarations(): array {
		return [
			'no name' => [['sql' => 'SELECT 1'], 'A statement needs a name'],
			'unknown kind' => [['name' => 'a', 'kind' => 'ddl', 'sql' => 'SELECT 1'], 'must be of kind read or write'],
			'no sql' => [['name' => 'a', 'sql' => '  ;'], 'has no SQL'],
			'two statements' => [['name' => 'a', 'sql' => 'SELECT 1; DELETE FROM t'], 'holds more than one statement'],
			'positional placeholder' => [['name' => 'a', 'sql' => 'SELECT * FROM t WHERE id = ?'], 'uses a ? placeholder'],
			'undeclared placeholder' => [['name' => 'a', 'sql' => 'SELECT * FROM t WHERE id = :id'], 'binds :id, which it does not declare'],
			'paged without after' => [['name' => 'a', 'sql' => 'SELECT * FROM t LIMIT :limit', 'pageKey' => 'id'], 'must bind :after'],
			'reserved parameter' => [['name' => 'a', 'sql' => 'SELECT * FROM t WHERE id > :after LIMIT :limit', 'pageKey' => 'id', 'parameters' => ['after']], ':after is bound by integriq'],
			'unknown type' => [['name' => 'a', 'sql' => 'SELECT :x', 'parameters' => [['name' => 'x', 'type' => 'blob']]], 'has the unknown type "blob"'],
		];
	}//end refusedDeclarations()

	/**
	 * Each such declaration is refused, naming what is wrong.
	 *
	 * @param array<string,mixed> $declaration The declaration.
	 * @param string              $message     Part of the refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusedDeclarations')]
	public function testAnUnsafeDeclarationIsRefused(array $declaration, string $message): void {
		$this->expectException(DatabaseSourceException::class);
		$this->expectExceptionMessage($message);

		DatabaseStatement::fromDeclaration(declaration: $declaration);
	}//end testAnUnsafeDeclarationIsRefused()
}//end class
