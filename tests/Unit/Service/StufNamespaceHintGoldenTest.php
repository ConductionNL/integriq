<?php

/**
 * The StUF-BG and StUF-ZKN services find a namespace by hint the same way.
 *
 * Pins the private resolveNamespace() of both services: the first entry whose
 * prefix, then whose URI, contains the hint (case-insensitively for the
 * prefix and the URI), in map order, and '' when none does, so the shared
 * lookup can move without changing which namespace a StUF message is read in.
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
 * @spec openspec/changes/stuf-adapter/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\StUFBGService;
use OCA\Integriq\Service\StUFZKNService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for the StUF namespace lookup.
 */
final class StufNamespaceHintGoldenTest extends TestCase {

	/**
	 * Namespace maps, hints and the URI each yields, for both services.
	 *
	 * @return array<string, array{0: class-string, 1: array<string, string>, 2: string, 3: string}>
	 */
	public static function lookups(): array {
		$map = [
			'SOAP-ENV' => 'http://schemas.xmlsoap.org/soap/envelope/',
			'ns1' => 'http://www.egem.nl/StUF/sector/zkn/0310',
			'StUF' => 'http://www.egem.nl/StUF/StUF0301',
			'BG' => 'http://www.egem.nl/StUF/sector/bg/0310',
		];
		$cases = [
			'the first entry in map order wins' => [$map, 'stuf', 'http://www.egem.nl/StUF/sector/zkn/0310'],
			'uri match' => [$map, 'zkn', 'http://www.egem.nl/StUF/sector/zkn/0310'],
			'upper-case prefix' => [$map, 'soap', 'http://schemas.xmlsoap.org/soap/envelope/'],
			'prefix bg' => [$map, 'bg', 'http://www.egem.nl/StUF/sector/bg/0310'],
			'no match' => [$map, 'gml', ''],
			'empty map' => [[], 'zkn', ''],
		];
		$rows = [];
		foreach ([StUFBGService::class, StUFZKNService::class] as $class) {
			foreach ($cases as $name => [$namespaces, $hint, $uri]) {
				$rows[$class . ' ' . $name] = [$class, $namespaces, $hint, $uri];
			}
		}

		return $rows;

	}//end lookups()

	/**
	 * The URI a hint resolves to.
	 *
	 * @param class-string          $class      The service.
	 * @param array<string, string> $namespaces The namespace map.
	 * @param string                $hint       The hint.
	 * @param string                $uri        The URI it yields.
	 *
	 * @return void
	 */
	#[DataProvider('lookups')]
	public function testTheUriAHintResolvesTo(string $class, array $namespaces, string $hint, string $uri): void {
		$reflection = new ReflectionClass($class);

		$this->assertSame($uri, $reflection->getMethod('resolveNamespace')->invoke($reflection->newInstanceWithoutConstructor(), $namespaces, $hint));

	}//end testTheUriAHintResolvesTo()
}//end class
