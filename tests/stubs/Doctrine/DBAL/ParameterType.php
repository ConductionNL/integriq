<?php

/**
 * Doctrine DBAL ParameterType stub for PHPUnit tests.
 *
 * Doctrine\DBAL is a Nextcloud server dependency that is not available in the
 * standalone composer dev-environment. This stub satisfies the `use` statements
 * in OCP\DB\QueryBuilder\IQueryBuilder so tests that mock IQueryBuilder can be
 * instantiated without the full Nextcloud server present.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace Doctrine\DBAL;

/**
 * Minimal stub for Doctrine\DBAL\ParameterType.
 */
class ParameterType {
	public const NULL = 0;
	public const INTEGER = 1;
	public const STRING = 2;
	public const LARGE_OBJECT = 3;
	public const BOOLEAN = 5;
	public const BINARY = 16;
}
