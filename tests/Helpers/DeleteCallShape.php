<?php

/**
 * Flattens the arguments a deleteObject() double receives.
 *
 * ObjectService::deleteObject() takes `(ObjectDeletion $deletion, $_rbac,
 * $_multitenancy, $_retentionSweep)`. The doubles and recordings in this
 * suite describe a delete as one flat list in the order the former stub
 * declared: uuid, register, schema, rbac, multitenancy, retentionSweep,
 * currentUser, permanent.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\OpenRegister\Service\Object\ObjectDeletion;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsAnything;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Flat views of a deleteObject() call.
 */
final class DeleteCallShape {

	/**
	 * The deleteObject() arguments as uuid, register, schema, rbac, multitenancy, retentionSweep, currentUser, permanent.
	 *
	 * @param array $args The arguments the double received, positional or by name.
	 *
	 * @return array The flat list.
	 */
	public static function delete(array $args): array {
		$deletion = ($args['deletion'] ?? $args[0] ?? null);
		if ($deletion instanceof ObjectDeletion === false) {
			return [$deletion, null, null, ($args[1] ?? true), ($args[2] ?? true), ($args[3] ?? false), null, false];
		}

		return [
			$deletion->uuid,
			$deletion->target->register,
			$deletion->target->schema,
			($args['_rbac'] ?? $args[1] ?? true),
			($args['_multitenancy'] ?? $args[2] ?? true),
			($args['_retentionSweep'] ?? $args[3] ?? false),
			$deletion->currentUser,
			$deletion->permanent,
		];
	}//end delete()

	/**
	 * A willReturnCallback() body for deleteObject() that hands the flat list to a closure written for it.
	 *
	 * @param callable $flat Receives uuid, register, schema, rbac, multitenancy, retentionSweep, currentUser, permanent.
	 *
	 * @return \Closure The callback.
	 */
	public static function callback(callable $flat): \Closure {
		return static fn (mixed ...$args): mixed => $flat(...self::delete(args: $args));
	}//end callback()

	/**
	 * A deleteObject() answer per call, as willReturnMap() rows in the flat list.
	 *
	 * @param array $rows Each row: the flat values it pins, then the answer.
	 *
	 * @return \Closure The callback; null for a call no row names.
	 */
	public static function returnMap(array $rows): \Closure {
		return static function (mixed ...$args) use ($rows): mixed {
			$flat = self::delete(args: $args);
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
	 * The with() constraints for a deleteObject() call, given as the flat list.
	 *
	 * An omitted trailing value is not checked; a value may be a constraint.
	 *
	 * @param mixed ...$flat Uuid, register, schema, rbac, multitenancy, retentionSweep, currentUser, permanent.
	 *
	 * @return array<int, Constraint> One constraint per deleteObject() parameter.
	 */
	public static function args(mixed ...$flat): array {
		$flat = ($flat + array_fill(0, 8, new IsAnything()));

		return [
			new Callback(
				static function (mixed $deletion) use ($flat): bool {
					if ($deletion instanceof ObjectDeletion === false) {
						return false;
					}

					$actual = [
						0 => $deletion->uuid,
						1 => $deletion->target->register,
						2 => $deletion->target->schema,
						6 => $deletion->currentUser,
						7 => $deletion->permanent,
					];
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
			self::constraint(expected: $flat[5]),
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
