<?php

/**
 * A query builder double that records the query a mapper builds.
 *
 * Each query the mapper asks the connection for is answered by the next
 * scripted answer (rows for a select, an affected count or an exception for a
 * statement) and recorded as a flat list of calls, so a test can assert the
 * table, the conditions, the bound values and the order the mapper asked for
 * without a database. Expressions render as readable strings such as
 * `eq(address, :p0)`.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use Throwable;

/**
 * Scripted answers in, recorded queries out.
 */
final class RecordingQueryBuilder {

	/**
	 * The recorded queries, one list of [method, ...args] per query.
	 *
	 * @var array<int,array<int,array<int,mixed>>>
	 */
	public array $queries = [];

	/**
	 * The bound values per query, keyed by placeholder.
	 *
	 * @var array<int,array<string,array{value:mixed,type:mixed}>>
	 */
	public array $parameters = [];

	/**
	 * Build a connection whose queries answer from the scripted list, in order.
	 *
	 * Each answer is an array with any of: `rows` (list of rows for a select),
	 * `affected` (int for a statement), `throw` (a Throwable a statement
	 * raises), `insertId` (int), `fetchOne` (the scalar a count reads).
	 *
	 * @param callable(class-string): object $mock    The test's createMock.
	 * @param array<int,array<string,mixed>> $answers The answers, one per query.
	 *
	 * @return IDBConnection
	 */
	public function connection(callable $mock, array $answers): IDBConnection {
		$connection = $mock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturnCallback(
			function () use ($mock, &$answers): IQueryBuilder {
				$index = count($this->queries);
				$this->queries[$index] = [];
				$this->parameters[$index] = [];
				return $this->builder(mock: $mock, index: $index, answer: (array)(array_shift($answers) ?? []));
			}
		);

		return $connection;
	}//end connection()

	/**
	 * One recording query builder.
	 *
	 * @param callable            $mock   The test's createMock.
	 * @param int                 $index  The query index.
	 * @param array<string,mixed> $answer The scripted answer.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(callable $mock, int $index, array $answer): IQueryBuilder {
		$qb = $mock(IQueryBuilder::class);

		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults', 'setFirstResult', 'insert', 'update', 'set', 'setValue', 'delete'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$arguments) use ($index, $method, $qb) {
					// A double receives the defaults too (`from($table, null)`); drop trailing nulls.
					while ($arguments !== [] && end($arguments) === null) {
						array_pop($arguments);
					}

					$this->queries[$index][] = array_merge([$method], $arguments);
					return $qb;
				}
			);
		}

		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = IQueryBuilder::PARAM_STR) use ($index): string {
				$placeholder = ':p' . count($this->parameters[$index]);
				$this->parameters[$index][$placeholder] = ['value' => $value, 'type' => $type];
				return $placeholder;
			}
		);

		$expr = $mock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'in'] as $method) {
			$expr->method($method)->willReturnCallback(static fn ($x, $y) => $method . '(' . $x . ', ' . $y . ')');
		}

		foreach (['isNull', 'isNotNull'] as $method) {
			$expr->method($method)->willReturnCallback(static fn ($x) => $method . '(' . $x . ')');
		}

		$qb->method('expr')->willReturn($expr);

		$function = $mock(IQueryFunction::class);
		$function->method('__toString')->willReturn('COUNT(*) AS total');
		$functions = $mock(IFunctionBuilder::class);
		$functions->method('count')->willReturn($function);
		$qb->method('func')->willReturn($functions);

		$rows = (array)($answer['rows'] ?? []);
		$result = $mock(IResult::class);
		$result->method('fetchAssociative')->willReturnCallback(static function () use (&$rows) {
			return (array_shift($rows) ?? false);
		});
		$result->method('fetchOne')->willReturn($answer['fetchOne'] ?? false);
		$qb->method('executeQuery')->willReturn($result);

		$qb->method('executeStatement')->willReturnCallback(
			static function () use ($answer): int {
				if (($answer['throw'] ?? null) instanceof Throwable) {
					throw $answer['throw'];
				}

				return (int)($answer['affected'] ?? 1);
			}
		);
		$qb->method('getLastInsertId')->willReturn((int)($answer['insertId'] ?? 1));
		$qb->method('getSQL')->willReturn('recorded query');

		return $qb;
	}//end builder()
}//end class
