<?php

/**
 * Characterisation test for JsonPathLiteEvaluator::evaluate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Datasource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Datasource;

use OCA\Integriq\Service\Datasource\JsonPathLiteEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the JSONPath-lite evaluation.
 */
class JsonPathLiteEvaluatorGoldenTest extends TestCase {

	/**
	 * The recorded evaluations.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$data = ['a' => ['b' => ['c' => 1, 'n' => null], 'list' => [['x' => 'first'], ['x' => 'second']]], '0' => 'zero', 'dotted.key' => 'd', 'e' => ''];
		$expressions = ['', '  ', '$', ' $ ', '$.a', 'a', '.a', '$..a', '$.a.b.c', '$.a.b.n', '$.a.b.missing', '$.a.list[1].x', '$.a.list.1.x', 'a.list[0]',
			'$[0]', '0', '$.a[0]', '$.a.list[5]', '$.a.b.c.d', '$.e', '$.e.f', 'a..b', '$.a[x]', 'a.list[-1]', '$.dotted.key', '$$.a', '.', '$.'];
		$evaluator = new JsonPathLiteEvaluator();
		$record = [];
		foreach ($expressions as $expr) {
			$record[] = ['expr' => $expr, 'value' => $evaluator->evaluate($data, $expr)];
		}

		$record[] = ['expr' => 'scalar root $.a', 'value' => $evaluator->evaluate('text', '$.a')];
		$record[] = ['expr' => 'scalar root $', 'value' => $evaluator->evaluate('text', '$')];

		$path = __DIR__ . '/../../../fixtures/datasource/jsonpath-lite.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
