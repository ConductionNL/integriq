<?php

/**
 * Unit tests for the mapping Twig extension.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Twig;

use OCA\OpenConnector\Twig\MappingExtension;
use PHPUnit\Framework\TestCase;

/**
 * Tests the mapping Twig function surface.
 */
class MappingExtensionTest extends TestCase {

	/**
	 * Test that executeMapping is available to mapping templates.
	 *
	 * @return void
	 */
	public function testRegistersExecuteMappingFunction(): void {
		$extension = new MappingExtension();

		$functionNames = array_map(
			static fn ($function): string => $function->getName(),
			$extension->getFunctions()
		);

		self::assertContains('executeMapping', $functionNames);

	}//end testRegistersExecuteMappingFunction()
}//end class
