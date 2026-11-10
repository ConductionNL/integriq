<?php

/**
 * Flattens the arguments a saveObjects() double receives.
 *
 * ObjectService::saveObjects() takes `(array $objects, BulkSaveOptions
 * $options, $_rbac, $_multitenancy)`. The doubles and recordings in this
 * suite describe a bulk save as one flat list in the order the former stub
 * declared: objects, register, schema, rbac, multitenancy, validation,
 * events, deduplicateIds, enrich, audit. deduplicateIds and enrich are no
 * longer part of the call and read as their former defaults.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\OpenRegister\Service\Object\BulkSaveOptions;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsAnything;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Flat views of a saveObjects() call.
 */
final class SaveObjectsCallShape {

	/**
	 * The saveObjects() arguments as objects, register, schema, rbac, multitenancy, validation, events, deduplicateIds, enrich, audit.
	 *
	 * @param array $args The arguments the double received, positional or by name.
	 *
	 * @return array The flat list.
	 */
	public static function saveObjects(array $args): array {
		$options = ($args['options'] ?? $args[1] ?? null);
		if ($options instanceof BulkSaveOptions === false) {
			$options = new BulkSaveOptions();
		}

		return [
			($args['objects'] ?? $args[0] ?? []),
			$options->defaults->register,
			$options->defaults->schema,
			($args['_rbac'] ?? $args[2] ?? true),
			($args['_multitenancy'] ?? $args[3] ?? true),
			$options->validation,
			$options->events,
			true,
			true,
			$options->audit,
		];
	}//end saveObjects()

	/**
	 * A willReturnCallback() body for saveObjects() that hands the flat list to a closure written for it.
	 *
	 * @param callable $flat Receives objects, register, schema, rbac, multitenancy, validation, events, deduplicateIds, enrich, audit.
	 *
	 * @return \Closure The callback.
	 */
	public static function callback(callable $flat): \Closure {
		return static fn (mixed ...$args): mixed => $flat(...self::saveObjects(args: $args));
	}//end callback()

	/**
	 * A saveObjects() answer per call, as willReturnMap() rows in the flat list.
	 *
	 * @param array $rows Each row: the flat values it pins, then the answer.
	 *
	 * @return \Closure The callback; null for a call no row names.
	 */
	public static function returnMap(array $rows): \Closure {
		return static function (mixed ...$args) use ($rows): mixed {
			$flat = self::saveObjects(args: $args);
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
	 * The with() constraints for a saveObjects() call, given as the flat list.
	 *
	 * An omitted trailing value is not checked; a value may be a constraint.
	 *
	 * @param mixed ...$flat Objects, register, schema, rbac, multitenancy, validation, events, deduplicateIds, enrich, audit.
	 *
	 * @return array<int, Constraint> One constraint per saveObjects() parameter.
	 */
	public static function args(mixed ...$flat): array {
		$flat = ($flat + array_fill(0, 10, new IsAnything()));

		return [
			self::constraint(expected: $flat[0]),
			new Callback(
				static function (mixed $options) use ($flat): bool {
					if ($options instanceof BulkSaveOptions === false) {
						return false;
					}

					$actual = [
						1 => $options->defaults->register,
						2 => $options->defaults->schema,
						5 => $options->validation,
						6 => $options->events,
						9 => $options->audit,
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
