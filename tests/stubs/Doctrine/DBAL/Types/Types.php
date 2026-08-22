<?php

/**
 * Doctrine DBAL Types stub for PHPUnit tests.
 *
 * Doctrine\DBAL is a Nextcloud server dependency that is not available in the
 * standalone composer dev-environment. OCP\DB\QueryBuilder\IQueryBuilder
 * references Doctrine\DBAL\Types\Types constants at class-body level (e.g.
 * `PARAM_BOOL = Types::BOOLEAN`), so the class must exist before PHP parses
 * the IQueryBuilder interface — otherwise every test that mocks IQueryBuilder
 * errors with "Class Doctrine\DBAL\Types\Types not found".
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

/**
 * Minimal stub for Doctrine\DBAL\Types\Types. Constant string values mirror the
 * real Doctrine type names so any code that compares against them stays correct.
 */
class Types {
	public const BOOLEAN = 'boolean';
	public const DATE_MUTABLE = 'date';
	public const DATE_IMMUTABLE = 'date_immutable';
	public const DATETIME_MUTABLE = 'datetime';
	public const DATETIME_IMMUTABLE = 'datetime_immutable';
	public const DATETIMETZ_MUTABLE = 'datetimetz';
	public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
	public const TIME_MUTABLE = 'time';
	public const TIME_IMMUTABLE = 'time_immutable';
}
