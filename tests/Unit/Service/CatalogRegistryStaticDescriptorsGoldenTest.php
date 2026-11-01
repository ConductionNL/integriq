<?php

/**
 * Golden characterization of CatalogRegistryService's static adapter descriptors.
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
 * @spec openspec/specs/connector-catalog/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CatalogRegistryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The built-in adapter descriptors the catalog lists, recorded against a
 * golden file. Regenerate it on the code it must describe with
 * CATALOG_STATIC_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\CatalogRegistryService
 */
class CatalogRegistryStaticDescriptorsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/catalog/static-descriptors.json';

	/**
	 * The static descriptors match the golden file, entry for entry.
	 *
	 * @return void
	 */
	public function testStaticDescriptorsMatchTheGoldenFile(): void {
		$service = (new ReflectionClass(objectOrClass: CatalogRegistryService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(objectOrMethod: CatalogRegistryService::class, method: 'collectStaticDescriptors');
		$encoded = json_encode(value: $method->invoke($service), flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";

		if (getenv(name: 'CATALOG_STATIC_GOLDEN') === '1') {
			if (is_dir(filename: dirname(path: self::GOLDEN)) === false) {
				mkdir(directory: dirname(path: self::GOLDEN), permissions: 0777, recursive: true);
			}

			file_put_contents(filename: self::GOLDEN, data: $encoded);
		}

		$this->assertSame(expected: (string)file_get_contents(filename: self::GOLDEN), actual: $encoded);
	}//end testStaticDescriptorsMatchTheGoldenFile()
}//end class
