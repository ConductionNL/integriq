<?php

/**
 * Contract tests for the demo Sources the flow-node demo calls.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md#requirement-the-synchronization-run-node-emits-one-item-per-synchronised-object
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The three demo Sources land on install through the register import, pass the
 * real `source` schema, and carry no real host or token.
 */
class FlowNodeDemoSourcesTest extends TestCase {

	private const FRAGMENT = 'flow-node-demo-sources.json';

	/**
	 * The register as InitializeRegister imports it: base plus every fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedRegister(): array {
		$root = dirname(__DIR__, 3);
		$descriptor = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true, flags: JSON_THROW_ON_ERROR);

		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === false) {
				continue;
			}

			$descriptor = $merge->invoke(null, $descriptor, $fragment);
		}

		return $descriptor;
	}//end mergedRegister()

	/**
	 * The seeded demo Sources, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function demoSources(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/' . self::FRAGMENT;
		self::assertFileExists($path);
		$decoded = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

		$bySlug = [];
		foreach ($decoded['components']['objects'] as $object) {
			$bySlug[$object['@self']['slug']] = $object;
		}

		return $bySlug;
	}//end demoSources()

	/**
	 * Exactly the three Sources the design names are seeded, as integriq sources.
	 *
	 * @return void
	 */
	public function testTheThreeDemoSourcesAreSeeded(): void {
		$sources = self::demoSources();

		$this->assertSame(['demo-echo-api', 'demo-forge-api', 'demo-registry-api'], array_keys($sources));
		foreach ($sources as $slug => $source) {
			$this->assertSame(['register' => 'integriq', 'schema' => 'source', 'slug' => $slug], $source['@self']);
		}
	}//end testTheThreeDemoSourcesAreSeeded()

	/**
	 * Every seeded Source passes the merged `source` schema with the validator OpenRegister uses.
	 *
	 * @return void
	 */
	public function testEveryDemoSourceValidatesAgainstTheSourceSchema(): void {
		$schema = json_encode(self::mergedRegister()['components']['schemas']['source'], JSON_THROW_ON_ERROR);

		foreach (self::demoSources() as $slug => $object) {
			unset($object['@self']);
			$result = (new Validator())->validate(json_decode(json_encode($object, JSON_THROW_ON_ERROR)), $schema);
			$errors = [];
			if ($result->isValid() === false) {
				$errors = (new ErrorFormatter())->format($result->error());
			}

			$this->assertSame([], $errors, $slug);
		}
	}//end testEveryDemoSourceValidatesAgainstTheSourceSchema()

	/**
	 * The forge Source ships disabled and holds a credential reference by name, never a token.
	 *
	 * @return void
	 */
	public function testTheForgeSourceIsDisabledAndHoldsOnlyACredentialReference(): void {
		$sources = self::demoSources();

		$this->assertTrue($sources['demo-echo-api']['isEnabled']);
		$this->assertTrue($sources['demo-registry-api']['isEnabled']);
		$this->assertFalse($sources['demo-forge-api']['isEnabled']);
		$this->assertSame(
			['credentialRef' => ['credentialName' => 'demo-forge-token']],
			$sources['demo-forge-api']['configuration']['authentication']
		);
	}//end testTheForgeSourceIsDisabledAndHoldsOnlyACredentialReference()

	/**
	 * No seed value is a real host or a secret.
	 *
	 * @return void
	 */
	public function testNoSeedValueIsARealHostOrASecret(): void {
		foreach (self::demoSources() as $slug => $source) {
			$host = (string)parse_url((string)$source['location'], PHP_URL_HOST);
			$this->assertStringEndsWith('.example.org', $host, $slug);
			$this->assertSame('none', $source['auth'], $slug);
			$this->assertStringContainsString('safe to delete', $source['description'], $slug);

			$encoded = json_encode($source, JSON_THROW_ON_ERROR);
			$this->assertDoesNotMatchRegularExpression('/"(password|secret|token|apikey|apiKey)"\s*:/', $encoded, $slug);
		}
	}//end testNoSeedValueIsARealHostOrASecret()
}//end class
