<?php

/**
 * Characterisation test for FormsAnswerResolver::resolve.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Forms
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

namespace OCA\Integriq\Tests\Unit\Service\Forms;

use OCA\Integriq\Service\Forms\FormsAnswerResolver;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden record of the answer resolution.
 */
class FormsAnswerResolverGoldenTest extends TestCase {

	/**
	 * The recorded resolutions.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$questions = [
			['id' => 1, 'text' => 'Naam', 'type' => 'short'],
			['id' => 2, 'text' => 'Hobby', 'type' => 'multiple'],
			['id' => 3, 'text' => 'Dubbel', 'type' => 'short'],
			['id' => 4, 'text' => 'Dubbel', 'type' => 'long'],
			['id' => '5', 'text' => 'Uniek', 'type' => 'multiple_unique'],
			'not a question',
			['text' => 'Zonder id'],
			['id' => 7, 'text' => 'Leeg'],
			['id' => 8, 'text' => 'Array', 'type' => 'short'],
		];
		$answers = [
			['questionId' => 1, 'text' => 'Jan'],
			['questionId' => '1', 'text' => 'Piet'],
			['questionId' => 2, 'text' => 'lezen'],
			['questionId' => 2, 'text' => 'fietsen'],
			['questionId' => 2],
			['questionId' => 5, 'text' => 'a'],
			['questionId' => 0, 'text' => 'nul'],
			['questionId' => 7],
			['questionId' => 8, 'text' => ['x']],
			['text' => 'zonder vraag'],
			['questionId' => 9, 'text' => 12],
		];
		$refs = [1, '1', 2, '2', 3, 'Naam', 'Hobby', 'Dubbel', 'Uniek', 'Zonder id', 'Leeg', 7, 8, 'Array', 9, 99, 'Onbekend', '', '01', 0, '1a'];
		$resolver = new FormsAnswerResolver();
		$record = [];
		foreach ($refs as $ref) {
			try {
				$outcome = $resolver->resolve($questions, $answers, $ref);
			} catch (Throwable $e) {
				$outcome = 'refused: ' . $e::class . ': ' . $e->getMessage();
			}

			$record[] = ['ref' => $ref, 'outcome' => $outcome];
		}

		$record[] = ['ref' => 'no questions', 'outcome' => $resolver->resolve([], $answers, 2)];

		$path = __DIR__ . '/../../../fixtures/forms/answer-resolver.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
