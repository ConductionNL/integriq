<?php

/**
 * Flattens the arguments a find() double receives.
 *
 * ObjectService::find() takes `($id, ?ObjectFindOptions $options, $_rbac,
 * $_multitenancy)`. The doubles and recordings in this suite describe a read
 * as one flat list in the order the former stub declared:
 * id, register, schema, rbac, multitenancy, render, audit, extend.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsAnything;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Flat views of a find() call.
 */
final class FindCallShape {

	/**
	 * Each name's index in the flat list.
	 *
	 * @var array<string, int>
	 */
	public const POSITIONS = [
		'id'            => 0,
		'register'      => 1,
		'schema'        => 2,
		'_rbac'         => 3,
		'_multitenancy' => 4,
		'_render'       => 5,
		'_audit'        => 6,
		'_extend'       => 7,
	];

	/**
	 * The find() arguments as id, register, schema, rbac, multitenancy, render, audit, extend.
	 *
	 * @param array $args The arguments the double received, positional or by name.
	 *
	 * @return array The flat list.
	 */
	public static function find(array $args): array {
		$options = ($args['options'] ?? $args[1] ?? null);
		if ($options instanceof ObjectFindOptions === false) {
			$options = new ObjectFindOptions();
		}

		return [
			($args['id'] ?? $args[0] ?? null),
			$options->register,
			$options->schema,
			($args['_rbac'] ?? $args[2] ?? true),
			($args['_multitenancy'] ?? $args[3] ?? true),
			$options->render,
			$options->audit,
			$options->extend,
		];
	}//end find()

	/**
	 * A willReturnCallback() body for find() that hands the flat list to a closure written for it.
	 *
	 * @param callable $flat Receives id, register, schema, rbac, multitenancy, render, audit, extend.
	 *
	 * @return \Closure The callback.
	 */
	public static function callback(callable $flat): \Closure {
		return static fn (mixed ...$args): mixed => $flat(...self::find(args: $args));
	}//end callback()

	/**
	 * A find() answer per call, as willReturnMap() rows in the flat list.
	 *
	 * A row holds the leading flat values it pins, then the answer; values it
	 * leaves out are not compared, as with willReturnMap().
	 *
	 * @param array $rows Each row: flat values, then the answer.
	 *
	 * @return \Closure The callback; null for a call no row names.
	 */
	public static function returnMap(array $rows): \Closure {
		return static function (mixed ...$args) use ($rows): mixed {
			$flat = self::find(args: $args);
			foreach ($rows as $row) {
				$answer = array_pop($row);
				if (array_slice($flat, 0, count($row)) == $row) {
					return $answer;
				}
			}

			return null;
		};
	}//end returnMap()

	/**
	 * The with() constraints for a find() call, given as the flat list.
	 *
	 * An omitted trailing value is not checked; a value may be a constraint.
	 *
	 * @param mixed ...$flat Id, register, schema, rbac, multitenancy, render, audit, extend.
	 *
	 * @return array<int, Constraint> One constraint per find() parameter.
	 */
	public static function args(mixed ...$flat): array {
		$flat = ($flat + array_fill(0, 8, new IsAnything()));

		return [
			self::constraint(expected: $flat[0]),
			new Callback(
				static function (?ObjectFindOptions $options) use ($flat): bool {
					$options ??= new ObjectFindOptions();
					$actual = [1 => $options->register, 2 => $options->schema, 5 => $options->render, 6 => $options->audit, 7 => $options->extend];
					foreach ($actual as $index => $value) {
						if (self::constraint(expected: $flat[$index])->evaluate(other: $value, returnResult: true) === false) {
							return false;
						}
					}

					return true;
				}
			),
			self::constraint(expected: $flat[3]),
			self::constraint(expected: $flat[4]),
		];
	}//end args()

	/**
	 * A value as a constraint: a constraint stays one, anything else is compared for equality.
	 *
	 * @param mixed $expected The value or constraint.
	 *
	 * @return Constraint The constraint.
	 */
	private static function constraint(mixed $expected): Constraint {
		if ($expected instanceof Constraint) {
			return $expected;
		}

		return new IsEqual(value: $expected);
	}//end constraint()
}//end class
