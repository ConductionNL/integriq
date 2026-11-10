<?php

/**
 * Flattens the arguments a saveObject() double receives.
 *
 * ObjectService::saveObject() takes `($object, ObjectSaveOptions $options,
 * $_rbac, $_multitenancy)`. The doubles and recordings in this suite describe
 * a save as one flat list in the order the former stub declared: object,
 * register, schema, uuid, rbac, multitenancy, silent, validation. The values
 * that stub never declared follow: extend, uploadedFiles, currentUser,
 * failIfExists, unowned, dedupOverride, owner.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsAnything;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Flat views of a saveObject() call.
 */
final class SaveCallShape {

	/**
	 * Each name's index in the flat list.
	 *
	 * @var array<string, int>
	 */
	public const POSITIONS = [
		'object'         => 0,
		'register'       => 1,
		'schema'         => 2,
		'uuid'           => 3,
		'_rbac'          => 4,
		'_multitenancy'  => 5,
		'silent'         => 6,
		'_validation'    => 7,
		'extend'         => 8,
		'uploadedFiles'  => 9,
		'currentUser'    => 10,
		'failIfExists'   => 11,
		'_unowned'       => 12,
		'_dedupOverride' => 13,
		'_owner'         => 14,
	];

	/**
	 * The saveObject() arguments as the flat list (see POSITIONS).
	 *
	 * @param array $args The arguments the double received, positional or by name.
	 *
	 * @return array The flat list.
	 */
	public static function save(array $args): array {
		$options = ($args['options'] ?? $args[1] ?? null);
		if ($options instanceof ObjectSaveOptions === false) {
			$options = new ObjectSaveOptions();
		}

		return [
			($args['object'] ?? $args[0] ?? null),
			$options->target->register,
			$options->target->schema,
			$options->uuid,
			($args['_rbac'] ?? $args[2] ?? true),
			($args['_multitenancy'] ?? $args[3] ?? true),
			$options->policy->silent,
			$options->policy->validation,
			$options->extend,
			$options->uploadedFiles,
			$options->actor->currentUser,
			$options->policy->failIfExists,
			$options->actor->unowned,
			$options->policy->dedupOverride,
			$options->actor->owner,
		];
	}//end save()

	/**
	 * A willReturnCallback() body for saveObject() that hands the flat list to a closure written for it.
	 *
	 * @param callable $flat Receives the flat list (see POSITIONS).
	 *
	 * @return \Closure The callback.
	 */
	public static function callback(callable $flat): \Closure {
		return static fn (mixed ...$args): mixed => $flat(...self::save(args: $args));
	}//end callback()

	/**
	 * A saveObject() answer per call, as willReturnMap() rows in the flat list.
	 *
	 * @param array $rows Each row: the flat values it pins, then the answer.
	 *
	 * @return \Closure The callback; null for a call no row names.
	 */
	public static function returnMap(array $rows): \Closure {
		return static function (mixed ...$args) use ($rows): mixed {
			$flat = self::save(args: $args);
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
	 * The with() constraints for a saveObject() call, given as the flat list.
	 *
	 * An omitted trailing value is not checked; a value may be a constraint.
	 *
	 * @param mixed ...$flat The flat values (see POSITIONS).
	 *
	 * @return array<int, Constraint> One constraint per saveObject() parameter.
	 */
	public static function args(mixed ...$flat): array {
		$flat = ($flat + array_fill(0, 15, new IsAnything()));

		return [
			self::constraint(expected: $flat[0]),
			new Callback(
				static function (mixed $options) use ($flat): bool {
					if ($options instanceof ObjectSaveOptions === false) {
						return false;
					}

					$actual = self::save(args: [null, $options]);
					foreach ([1, 2, 3, 6, 7, 8, 9, 10, 11, 12, 13, 14] as $index) {
						if (self::constraint(expected: $flat[$index])->evaluate(other: $actual[$index], returnResult: true) === false) {
							return false;
						}
					}

					return true;
				}
			),
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
