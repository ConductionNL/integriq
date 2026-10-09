<?php

/**
 * The seeded zgw-zaken case-system source and its mock fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The zgw-zaken seed links a connection at once.
 */
class CaseSystemSeedTest extends TestCase {

	/**
	 * The seed as linkTemplate() reads it.
	 *
	 * @return array|null
	 */
	private function seed(): ?array {
		$service = new CatalogRegistryService(
			new IntegrationRegistry(),
			ObjectServiceMockBuilder::make($this),
			$this->createMock(IAppConfig::class),
			new NullLogger()
		);

		return $service->findSeedSourcePayload('zgw-zaken');
	}//end seed()

	/**
	 * The seed exists, is a case-system source, disabled and unconfigured.
	 *
	 * @return void
	 */
	public function testTheSeedIsADisabledUnconfiguredCaseSystemSource(): void {
		$seed = $this->seed();

		$this->assertIsArray($seed, 'linkTemplate() must find a seed for sourceTemplate zgw-zaken.');
		$this->assertSame('zgw-zaken', $seed['slug']);
		$this->assertSame('case-system', $seed['type']);
		$this->assertFalse($seed['isEnabled']);
		$this->assertSame([], (array)($seed['configuration'] ?? []));
		$this->assertNotSame('', (string)($seed['location'] ?? ''), 'CallService refuses a source without a location.');
	}//end testTheSeedIsADisabledUnconfiguredCaseSystemSource()

	/**
	 * The seed validates against the merged register's source schema, type enum included.
	 *
	 * @return void
	 */
	public function testTheSeedValidatesAgainstTheSourceSchema(): void {
		$seed = $this->seed();
		$this->assertIsArray($seed);

		$this->assertSame([], RegisterSchemaValidator::errors('source', $seed));
	}//end testTheSeedValidatesAgainstTheSourceSchema()

	/**
	 * The mock fixture holds two cases, each with documents whose content is base64.
	 *
	 * @return void
	 */
	public function testTheMockFixtureHoldsTwoCasesWithDocuments(): void {
		$path = dirname(__DIR__, 3) . '/lib/Settings/case-system-mock.json';
		$this->assertFileExists($path);
		$fixture = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

		$this->assertCount(2, $fixture['cases']);
		foreach ($fixture['cases'] as $case) {
			$this->assertNotSame('', $case['url']);
			$this->assertNotSame('', $case['identification']);
			$this->assertNotEmpty($case['documents']);
			foreach ($case['documents'] as $document) {
				$this->assertNotFalse(base64_decode($document['content'], true));
			}
		}
	}//end testTheMockFixtureHoldsTwoCasesWithDocuments()
}//end class
