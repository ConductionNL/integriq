<?php

/**
 * Unit tests for the eudi-wallet-credential-issuance register fragment.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-adapter-ships-as-a-catalogue-entry-backed-by-three-register-fragment-schemas-req-eudi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Resolves design.md's DEFERRED_QUESTION with evidence: confirms
 * `InitializeRegister::deepMergeConfig()` merges the fragment's
 * `components.schemas` AND `components.registers.openconnector.schemas`
 * cleanly onto a representative base descriptor, and that the fragment file
 * itself is well-formed and declares exactly the three schemas REQ-EUDI-001
 * names.
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-adapter-ships-as-a-catalogue-entry-backed-by-three-register-fragment-schemas-req-eudi-001
 */
class EudiRegisterFragmentTest extends TestCase {

	/**
	 * Path to the fragment under test.
	 *
	 * @var string
	 */
	private const FRAGMENT_PATH = __DIR__ . '/../../../lib/Settings/register.d/eudi-wallet-credential-issuance.json';

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
	 * The fragment file is well-formed JSON and declares exactly the three
	 * REQ-EUDI-001 schemas, each backed by a non-empty properties block.
	 *
	 * @return void
	 */
	public function testFragmentIsWellFormedAndDeclaresExactlyThreeSchemas(): void {
		$this->assertFileExists(self::FRAGMENT_PATH);

		$raw = file_get_contents(self::FRAGMENT_PATH);
		$this->assertNotFalse($raw);

		$fragment = json_decode($raw, true);
		$this->assertSame(JSON_ERROR_NONE, json_last_error(), 'fragment MUST be valid JSON: ' . json_last_error_msg());

		$schemas = ($fragment['components']['schemas'] ?? []);
		$this->assertSame(
			['eudi_credential_offer', 'eudi_issuance_session', 'eudi_status_list'],
			array_keys($schemas)
		);

		foreach ($schemas as $slug => $schema) {
			$this->assertNotEmpty($schema['properties'] ?? [], "schema '$slug' must declare a non-empty properties block");
			$this->assertArrayHasKey('title', $schema, "schema '$slug' must declare a title");
			$this->assertArrayHasKey('description', $schema, "schema '$slug' must declare a description");
		}

	}//end testFragmentIsWellFormedAndDeclaresExactlyThreeSchemas()

	/**
	 * DEFERRED_QUESTION resolution: merging the fragment onto a
	 * representative base descriptor attaches all three new schemas to
	 * `components.registers.openconnector.schemas[]` (concatenated, base
	 * entries preserved) AND makes them resolvable under
	 * `components.schemas` — the two things OpenRegister's ImportHandler
	 * requires (traced in ImportHandler.php:1602-1803) for a schema to
	 * actually end up attached to the register, not just imported as an
	 * orphan schema.
	 *
	 * @return void
	 */
	public function testMergingFragmentAttachesSchemasToTheRegistersList(): void {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT_PATH), true);

		$base = [
			'components' => [
				'registers' => [
					'openconnector' => [
						'slug' => 'openconnector',
						'schemas' => ['source', 'consumer', 'endpoint'],
					],
				],
				'schemas' => [
					'source' => ['type' => 'object'],
				],
			],
		];

		$merged = $this->merge($base, $fragment);

		$registerSchemas = $merged['components']['registers']['openconnector']['schemas'];
		$this->assertSame(
			['source', 'consumer', 'endpoint', 'eudi_credential_offer', 'eudi_issuance_session', 'eudi_status_list'],
			$registerSchemas,
			'the base register schema list must be preserved and the three new slugs appended'
		);

		foreach (['eudi_credential_offer', 'eudi_issuance_session', 'eudi_status_list'] as $slug) {
			$this->assertArrayHasKey($slug, $merged['components']['schemas']);
		}

		// A disjoint fragment must never disturb a pre-existing schema (union by key, ADR-037).
		$this->assertSame(['type' => 'object'], $merged['components']['schemas']['source']);

	}//end testMergingFragmentAttachesSchemasToTheRegistersList()

	/**
	 * The fragment does not touch `integriq_register.json` itself —
	 * that file's own schema declarations are unaffected (verified
	 * independently by RegisterDescriptorTest, which parses ONLY that
	 * file and never this fragment).
	 *
	 * @return void
	 */
	public function testFragmentDoesNotModifyTheDescriptorFile(): void {
		$descriptorPath = __DIR__ . '/../../../lib/Settings/integriq_register.json';
		$descriptor = json_decode((string)file_get_contents($descriptorPath), true);

		$this->assertArrayNotHasKey(
			'eudi_credential_offer',
			$descriptor['components']['schemas'],
			'the coverage-gated descriptor file must not declare eudi_* schemas directly'
		);

	}//end testFragmentDoesNotModifyTheDescriptorFile()
}//end class
