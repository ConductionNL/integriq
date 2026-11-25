<?php

/**
 * Tests that schema-level seed blocks reach OpenRegister as listed objects.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-register-descriptor-import-via-or-configurationservice-on-install-upgrade-req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\InitializeRegister;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister seeds only `components.objects`; a schema's `x-openregister-seed` is dropped as unknown.
 */
final class InitializeRegisterSeedHoistTest extends TestCase {

	/**
	 * Load the ConfigurationService stand-in when OpenRegister is absent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../stubs/openregister-service-stubs.php';
	}//end setUp()

	/**
	 * Seeds move to components.objects after the objects already listed, and the key is removed.
	 *
	 * @return void
	 */
	public function testSchemaSeedsAreListedAsObjects(): void {
		$listed     = ['@self' => ['register' => 'integriq', 'schema' => 'endpoint', 'slug' => 'ping'], 'name' => 'Ping'];
		$seed       = ['@self' => ['register' => 'integriq', 'schema' => 'api_product', 'slug' => 'p1'], 'endpoints' => ['@ref:endpoint:ping']];
		$descriptor = [
			'components' => [
				'objects' => [$listed],
				'schemas' => [
					'api_product' => ['type' => 'object', 'x-openregister-seed' => [$seed]],
					'endpoint'    => ['type' => 'object'],
				],
			],
		];

		$hoisted = InitializeRegister::hoistSchemaSeeds(descriptor: $descriptor);

		$this->assertSame(expected: [$listed, $seed], actual: $hoisted['components']['objects']);
		$this->assertArrayNotHasKey(key: 'x-openregister-seed', array: $hoisted['components']['schemas']['api_product']);
		$this->assertSame(expected: ['type' => 'object'], actual: $hoisted['components']['schemas']['endpoint']);
	}//end testSchemaSeedsAreListedAsObjects()

	/**
	 * A seed whose identity a fragment already lists is left out: the listed object wins.
	 *
	 * @return void
	 */
	public function testAnAlreadyListedSeedIsNotListedTwice(): void {
		$listed     = ['@self' => ['register' => 'integriq', 'schema' => 'api_product', 'slug' => 'p1'], 'name' => 'Listed'];
		$descriptor = [
			'components' => [
				'objects' => [$listed],
				'schemas' => [
					'api_product' => [
						'x-openregister-seed' => [
							['@self' => ['register' => 'integriq', 'schema' => 'api_product', 'slug' => 'p1'], 'name' => 'Seed'],
							['@self' => ['register' => 'integriq', 'schema' => 'api_product', 'slug' => 'p2'], 'name' => 'Other'],
							['@self' => ['register' => 'integriq', 'schema' => 'api_product', 'slug' => 'p2'], 'name' => 'Again'],
						],
					],
				],
			],
		];

		$hoisted = InitializeRegister::hoistSchemaSeeds(descriptor: $descriptor);

		$this->assertSame(expected: ['Listed', 'Other'], actual: array_column(array: $hoisted['components']['objects'], column_key: 'name'));
	}//end testAnAlreadyListedSeedIsNotListedTwice()

	/**
	 * A seed without a slug is left out: the import could never find it again.
	 *
	 * @return void
	 */
	public function testASeedWithoutSlugIsLeftOut(): void {
		$descriptor = [
			'components' => [
				'schemas' => [
					'notificaties_abonnement' => [
						'x-openregister-seed' => [['@self' => ['register' => 'integriq'], 'callbackUrl' => 'https://example.org'], 'not-an-object'],
					],
				],
			],
		];

		$hoisted = InitializeRegister::hoistSchemaSeeds(descriptor: $descriptor);

		$this->assertArrayNotHasKey(key: 'objects', array: $hoisted['components']);
		$this->assertArrayNotHasKey(key: 'x-openregister-seed', array: $hoisted['components']['schemas']['notificaties_abonnement']);
	}//end testASeedWithoutSlugIsLeftOut()

	/**
	 * A descriptor without schemas is returned unchanged.
	 *
	 * @return void
	 */
	public function testADescriptorWithoutSchemasIsUnchanged(): void {
		$this->assertSame(expected: ['openapi' => '3.0.0'], actual: InitializeRegister::hoistSchemaSeeds(descriptor: ['openapi' => '3.0.0']));
	}//end testADescriptorWithoutSchemasIsUnchanged()

	/**
	 * The shipped descriptor hands every seed reference target to the import before the seed that references it.
	 *
	 * @return void
	 */
	public function testShippedSeedReferencesResolveWithinTheImport(): void {
		$captured = [];
		$service  = $this->createMock(originalClassName: ConfigurationService::class);
		$service->method('importFromApp')->willReturnCallback(
			static function (string $appId, array $data, string $version) use (&$captured): void {
				$captured = $data;
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('1.0.0');

		$step = new InitializeRegister(container: $container, appConfig: $appConfig, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$seen = [];
		foreach ($captured['components']['objects'] as $object) {
			array_walk_recursive(
				$object,
				function (mixed $value) use (&$seen): void {
					if (is_string($value) === true && str_starts_with($value, '@ref:') === true) {
						$this->assertArrayHasKey(key: substr(string: $value, offset: 5), array: $seen, message: $value . ' has no earlier seed');
					}
				}
			);
			$key = $object['@self']['schema'] . ':' . $object['@self']['slug'];
			$this->assertArrayNotHasKey(key: $key, array: $seen, message: $key . ' is listed twice');
			$seen[$key] = true;
		}

		$this->assertArrayHasKey(key: 'api_product:api-product-zaken-api-v1', array: $seen);
		foreach ($captured['components']['schemas'] as $schema) {
			$this->assertArrayNotHasKey(key: 'x-openregister-seed', array: $schema);
		}
	}//end testShippedSeedReferencesResolveWithinTheImport()
}//end class
