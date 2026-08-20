<?php

/**
 * Unit tests for the ADR-037 modular register fragment deep-merge.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-register-descriptor-import-via-or-configurationservice-on-install-upgrade-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Repair;

use OCA\OpenConnector\Repair\InitializeRegister;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that disjoint register fragments union cleanly so concurrent
 * OpenSpec change builds never collide on the shared register file (ADR-037).
 */
final class RegisterFragmentMergeTest extends TestCase {
	/**
	 * Invoke the private static InitializeRegister::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Two fragments adding disjoint OpenAPI schemas/paths union by key.
	 *
	 * @return void
	 */
	public function testDisjointFragmentsUnionSchemasAndPaths(): void {
		$base = [
			'components' => ['schemas' => ['Existing' => ['type' => 'object']]],
			'paths' => ['/existing' => ['get' => []]],
		];

		$base = $this->merge(
			$base,
			[
				'components' => ['schemas' => ['AlphaSource' => ['type' => 'object']]],
				'paths' => ['/alpha' => ['get' => []]],
			]
		);
		$base = $this->merge(
			$base,
			[
				'components' => ['schemas' => ['BetaMapping' => ['type' => 'object']]],
				'paths' => ['/beta' => ['post' => []]],
			]
		);

		$this->assertArrayHasKey('Existing', $base['components']['schemas']);
		$this->assertArrayHasKey('AlphaSource', $base['components']['schemas']);
		$this->assertArrayHasKey('BetaMapping', $base['components']['schemas']);
		$this->assertCount(3, $base['components']['schemas']);
		$this->assertArrayHasKey('/existing', $base['paths']);
		$this->assertArrayHasKey('/alpha', $base['paths']);
		$this->assertArrayHasKey('/beta', $base['paths']);
	}//end testDisjointFragmentsUnionSchemasAndPaths()

	/**
	 * List arrays are concatenated; scalars overwrite.
	 *
	 * @return void
	 */
	public function testListsConcatenateAndScalarsOverwrite(): void {
		$merged = $this->merge(
			['required' => ['a', 'b'], 'info' => ['version' => '0.1.0']],
			['required' => ['c'], 'info' => ['version' => '0.2.0']]
		);
		$this->assertSame(['a', 'b', 'c'], $merged['required']);
		$this->assertSame('0.2.0', $merged['info']['version']);
	}//end testListsConcatenateAndScalarsOverwrite()
}//end class
