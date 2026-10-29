<?php

/**
 * Golden characterization of MappingService::executeMapping().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Every branch of executeMapping(), recorded against a golden file: the
 * output (or the exception) for each mapping and input.
 *
 * Every mapping names all of its fields, so the outcome does not depend on
 * the defaults of the installed OpenRegister's Mapping entity.
 *
 * Regenerate the golden file on the code it must describe with
 * EXECUTE_MAPPING_GOLDEN=1.
 */
class MappingServiceExecuteGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/mapping/execute-mapping.json';

	/**
	 * A mapping array with every field set.
	 *
	 * @param array $mapping     The key => source path or template pairs.
	 * @param bool  $passThrough Whether the input passes through.
	 * @param array $unset       The keys to unset.
	 * @param array $cast        The casts.
	 *
	 * @return array
	 */
	private function mapping(array $mapping, bool $passThrough = false, array $unset = [], array $cast = []): array {
		return ['name' => 'golden', 'mapping' => $mapping, 'passThrough' => $passThrough, 'unset' => $unset, 'cast' => $cast];
	}//end mapping()

	/**
	 * The cases: mapping, input and list flag.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$input = ['naam' => 'Jan', 'leeftijd' => '42', 'adres' => ['straat' => 'Laan', 'nr' => 3], 'a.b' => 'dotted', 'leeg' => '', 'lijst' => [1, 2]];

		return [
			'copy-paths' => ['mapping' => $this->mapping(['name' => 'naam', 'street' => 'adres.straat']), 'input' => $input],
			'pass-through' => ['mapping' => $this->mapping(['name' => 'naam'], true), 'input' => $input],
			'dotted-key-in-input' => ['mapping' => $this->mapping(['d' => 'a&#46;b', 'x' => 'a.b']), 'input' => $input],
			'dotted-key-pass-through' => ['mapping' => $this->mapping([], true), 'input' => $input],
			'array-value' => ['mapping' => $this->mapping(['fixed' => ['a' => 1]]), 'input' => $input],
			'twig-template' => ['mapping' => $this->mapping(['greeting' => 'Hallo {{ naam }}', 'num' => '{{ leeftijd + 1 }}']), 'input' => $input],
			'twig-literal' => ['mapping' => $this->mapping(['constant' => 'vast']), 'input' => $input],
			'twig-error' => ['mapping' => $this->mapping(['bad' => '{{ naam | nosuchfilter }}']), 'input' => $input],
			'nested-target' => ['mapping' => $this->mapping(['deep.er.key' => 'naam']), 'input' => $input],
			'unset' => ['mapping' => $this->mapping([], true, ['naam', 'adres.nr', 'missing']), 'input' => $input],
			'cast-int-string' => ['mapping' => $this->mapping(['age' => 'leeftijd', 'n' => 'adres.nr'], false, [], ['age' => 'int', 'n' => 'string', 'missing' => 'int']), 'input' => $input],
			'cast-list-and-csv' => ['mapping' => $this->mapping(['age' => 'leeftijd', 'leeg' => 'leeg'], false, [], ['age' => ['int', 'string'], 'leeg' => 'unsetIfValue==,int']), 'input' => $input],
			'cast-null-if-value' => ['mapping' => $this->mapping(['leeg' => 'leeg'], false, [], ['leeg' => 'setNullIfValue==']), 'input' => $input],
			'root-array' => ['mapping' => $this->mapping(['#' => 'adres']), 'input' => $input],
			'root-scalar' => ['mapping' => $this->mapping(['#' => 'naam']), 'input' => $input],
			'root-null' => ['mapping' => $this->mapping(['#' => 'nothing'], false, [], ['#' => 'setNullIfValue==nothing']), 'input' => $input],
			'root-with-other-key' => ['mapping' => $this->mapping(['#' => 'naam', 'b' => 'leeftijd']), 'input' => $input],
			'empty-mapping' => ['mapping' => $this->mapping([]), 'input' => $input],
			'list' => ['mapping' => $this->mapping(['name' => 'naam']), 'input' => ['x' => ['naam' => 'A'], 'y' => ['naam' => 'B']], 'list' => true],
			'list-scalars' => ['mapping' => $this->mapping(['v' => 'value']), 'input' => ['a', 'b'], 'list' => true],
			'list-input-extra-values' => ['mapping' => $this->mapping(['name' => 'naam', 'v' => 'value', 'extra' => 'org']), 'input' => ['listInput' => [['naam' => 'A'], 'scalar'], 'org' => 'O', 'value' => 'dropped'], 'list' => true],
			'list-input-no-extra' => ['mapping' => $this->mapping(['name' => 'naam']), 'input' => ['listInput' => [['naam' => 'A']]], 'list' => true],
			'list-empty' => ['mapping' => $this->mapping(['name' => 'naam']), 'input' => [], 'list' => true],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$service = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(OrObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);

		$actual = [];
		foreach ($this->cases() as $name => $case) {
			try {
				$outcome = ['return' => $service->executeMapping($case['mapping'], $case['input'], ($case['list'] ?? false))];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = $outcome;
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('EXECUTE_MAPPING_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
