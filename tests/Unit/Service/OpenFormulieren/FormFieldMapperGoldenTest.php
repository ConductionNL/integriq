<?php

/**
 * Characterisation test for FormFieldMapper.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\OpenFormulieren
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

namespace OCA\Integriq\Tests\Unit\Service\OpenFormulieren;

use OCA\Integriq\Service\OpenFormulieren\FormFieldMapper;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden record of the form field mapping.
 */
class FormFieldMapperGoldenTest extends TestCase {

	/**
	 * An outcome as data.
	 *
	 * @param callable $run The call.
	 *
	 * @return mixed
	 */
	private static function outcome(callable $run): mixed {
		try {
			return ['ok' => $run()];
		} catch (Throwable $e) {
			return ['refused' => $e::class . ': ' . $e->getMessage()];
		}
	}//end outcome()

	/**
	 * The recorded validations and mappings.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$mapper = new FormFieldMapper();
		$good = [
			'title' => ['type' => 'template', 'value' => 'Melding {{ naam }} over {{adres.straat}}'],
			'summary' => ['type' => 'from', 'value' => 'omschrijving'],
			'channel' => ['type' => 'const', 'value' => 'web'],
			'priority' => ['type' => 'from', 'value' => 'urgentie'],
		];
		$configs = [
			'good' => $good,
			'missing channel' => array_diff_key($good, ['channel' => 1]),
			'unknown field' => $good + ['requester' => ['type' => 'const', 'value' => 'x']],
			'numeric field' => $good + [0 => ['type' => 'const', 'value' => 'x']],
			'entry not an object' => ['summary' => 'omschrijving'] + $good,
			'unknown type' => ['channel' => ['type' => 'regex', 'value' => 'x']] + $good,
			'no type' => ['channel' => ['value' => 'x']] + $good,
			'value not a string' => ['channel' => ['type' => 'const', 'value' => 3]] + $good,
			'no value' => ['channel' => ['type' => 'const']] + $good,
		];
		$record = ['validateConfig' => [], 'map' => []];
		foreach ($configs as $name => $config) {
			$record['validateConfig'][$name] = self::outcome(static fn (): string => ($mapper->validateConfig($config) ?? 'valid'));
		}

		$submitted = ['naam' => 'Jan', 'adres.straat' => 'Dorpsstraat', 'omschrijving' => 'Losse stoeptegel', 'urgentie' => 2, 'leeg' => null];
		$maps = [
			'good' => [$good, $submitted],
			'from absent' => [$good, array_diff_key($submitted, ['omschrijving' => 1])],
			'template placeholders absent' => [$good, array_diff_key($submitted, ['naam' => 1, 'adres.straat' => 1])],
			'null submitted value' => [['summary' => ['type' => 'from', 'value' => 'leeg'], 'title' => ['type' => 'template', 'value' => '[{{leeg}}]']], $submitted],
			'template without placeholders' => [['title' => ['type' => 'template', 'value' => 'Vast {{ niet-geldig }}']], []],
			'bad entry' => [['title' => 'x'], $submitted],
			'unknown type' => [['title' => ['type' => 'eval', 'value' => 'x']], $submitted],
			'empty mapping' => [[], $submitted],
		];
		foreach ($maps as $name => [$mapping, $values]) {
			$record['map'][$name] = self::outcome(static fn (): array => $mapper->map($mapping, $values));
		}

		$path = __DIR__ . '/../../../fixtures/open-formulieren/form-field-mapper.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
