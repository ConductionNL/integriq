<?php

/**
 * Doctrine DBAL ExpressionBuilder stub for PHPUnit tests.
 *
 * Required by OCP\DB\QueryBuilder\IExpressionBuilder which references its
 * constants at class-body level.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

/**
 * Minimal stub for Doctrine\DBAL\Query\Expression\ExpressionBuilder.
 */
class ExpressionBuilder {
	public const EQ = '=';
	public const NEQ = '<>';
	public const LT = '<';
	public const LTE = '<=';
	public const GT = '>';
	public const GTE = '>=';
}
