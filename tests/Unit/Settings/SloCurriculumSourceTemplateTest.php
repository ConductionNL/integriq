<?php

/**
 * Contract tests for the seeded SLO curriculum source template.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The fragment is dormant, credential-free, attributed, and names learniq's fields.
 */
class SloCurriculumSourceTemplateTest extends TestCase {
	private const FRAGMENT_PATH = __DIR__ . '/../../../lib/Settings/register.d/slo-curriculum-source.json';

	private const FIXTURE_PATH = __DIR__ . '/../../../lib/Adapters/Slo/slo-curriculum-recorded.json';

	/**
	 * @return array<string,array<string,mixed>> Objects keyed by slug.
	 */
	private function objects(): array {
		$decoded = json_decode((string)file_get_contents(self::FRAGMENT_PATH), true);
		$this->assertIsArray($decoded);

		$objects = [];
		foreach ($decoded['components']['objects'] as $object) {
			$objects[$object['@self']['slug']] = $object;
		}

		return $objects;
	}//end objects()

	/**
	 * @return void
	 */
	public function testTheSeededSourceIsDormantAndCredentialFree(): void {
		$source = $this->objects()['slo-curriculum'];

		$this->assertSame(['register' => 'integriq', 'schema' => 'source', 'slug' => 'slo-curriculum'], $source['@self']);
		$this->assertFalse($source['isEnabled']);
		$this->assertSame('basic', $source['auth']);
		$this->assertSame('api', $source['type']);
		$this->assertSame('https://opendata.slo.nl/curriculum/api/v1', $source['location']);

		foreach (['username', 'password', 'apikey', 'secret', 'jwt', 'authenticationConfig'] as $key) {
			$this->assertEmpty($source[$key] ?? null, $key . ' must not be seeded');
		}

		$this->assertArrayNotHasKey('authentication', $source['configuration']);
	}//end testTheSeededSourceIsDormantAndCredentialFree()

	/**
	 * @return void
	 */
	public function testTheAttributionNamesSloTheLicenceAndTheChange(): void {
		$attribution = $this->objects()['slo-curriculum']['configuration']['attribution'];

		$this->assertSame('CC BY 4.0', $attribution['licence']);
		$this->assertSame('https://creativecommons.org/licenses/by/4.0/deed.nl', $attribution['licenceUrl']);
		$this->assertStringContainsString('SLO', $attribution['text']);
		$this->assertStringContainsString('CC BY 4.0', $attribution['text']);
		$this->assertStringContainsString($attribution['licenceUrl'], $attribution['text']);
		$this->assertStringContainsString('omgezet', $attribution['text']);
	}//end testTheAttributionNamesSloTheLicenceAndTheChange()

	/**
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
	 *
	 * @return void
	 */
	public function testTheMappingPresetsNameTheContractFields(): void {
		$objects = $this->objects();

		$this->assertSame(
			['name', 'sourceAuthority', 'sourceRef', 'edition', 'level', 'description', 'proficiencyLevels', 'tenant_id'],
			array_keys($objects['slo-curriculum-framework-mapping']['mapping'])
		);
		$this->assertSame(
			['frameworkId', 'parentId', 'code', 'title', 'description', 'order', 'applicableYears', 'subjectId', 'tenant_id'],
			array_keys($objects['slo-curriculum-competency-mapping']['mapping'])
		);

		foreach (['slo-curriculum-framework-mapping', 'slo-curriculum-competency-mapping'] as $slug) {
			$this->assertSame('mapping', $objects[$slug]['@self']['schema']);
			$this->assertFalse($objects[$slug]['passThrough']);
		}
	}//end testTheMappingPresetsNameTheContractFields()

	/**
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-no-personal-data-and-no-secrets-req-009
	 *
	 * @return void
	 */
	public function testNoPersonalDataOrSecretsInTheFragmentOrTheFixture(): void {
		foreach ([self::FRAGMENT_PATH, self::FIXTURE_PATH] as $path) {
			$text = (string)file_get_contents($path);

			$this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, basename($path) . ': e-mail address');
			$this->assertDoesNotMatchRegularExpression('/Basic\s+[A-Za-z0-9+\/=]{16,}/', $text, basename($path) . ': Basic token');
			$this->assertDoesNotMatchRegularExpression('/"password"\s*:\s*"[^"]+"/', $text, basename($path) . ': password value');
		}
	}//end testNoPersonalDataOrSecretsInTheFragmentOrTheFixture()

	/**
	 * @return void
	 */
	public function testTheFixtureStatesItsProvenance(): void {
		$fixture = json_decode((string)file_get_contents(self::FIXTURE_PATH), true);

		$this->assertStringContainsString('NOT a captured HTTP exchange', $fixture['$comment']);
		$this->assertStringContainsString('curriculum-fo@2026.8', $fixture['$comment']);
		$this->assertStringContainsString('CC BY 4.0', $fixture['$comment']);
	}//end testTheFixtureStatesItsProvenance()
}//end class
