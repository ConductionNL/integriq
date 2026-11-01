<?php

/**
 * CatalogRegistryService status fallbacks and its type vocabularies.
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
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The dormant and available fallbacks of resolveStatus(), and the standards
 * and icon each source type maps to.
 *
 * @covers \OCA\Integriq\Service\CatalogRegistryService
 */
class CatalogRegistryStatusVocabularyTest extends TestCase {

	/**
	 * The service over an empty target environment.
	 *
	 * @param string $flagValue What every app config flag reads as.
	 *
	 * @return CatalogRegistryService
	 */
	private function service(string $flagValue = '0'): CatalogRegistryService {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($flagValue);
		return new CatalogRegistryService(new IntegrationRegistry(), $objects, $appConfig, new NullLogger());
	}//end service()

	/**
	 * Each entry resolves to the status its mechanism and fields give.
	 *
	 * @return void
	 */
	public function testResolveStatusFallbacks(): void {
		$service = $this->service();
		$this->assertSame('dormant', $service->resolveStatus(['mechanism' => 'flag-gated', 'flagKey' => '']));
		$this->assertSame('dormant', $service->resolveStatus(['mechanism' => 'flag-gated']));
		$this->assertSame('dormant', $service->resolveStatus(['mechanism' => 'mock-seeded', 'sourceTemplateSlug' => '']));
		$this->assertSame('available', $service->resolveStatus(['mechanism' => 'always-available', '_fallbackEnabled' => true]));
		$this->assertSame('dormant', $service->resolveStatus(['mechanism' => 'always-available', '_fallbackEnabled' => false]));
		$this->assertSame('dormant', $service->resolveStatus(['_fallbackEnabled' => 'yes']));
		$this->assertSame('available', $service->resolveStatus([]));
		$this->assertSame('available', $this->service(flagValue: 'TRUE')->resolveStatus(['mechanism' => 'flag-gated', 'flagKey' => 'x.flag']));
	}//end testResolveStatusFallbacks()

	/**
	 * Every source type maps to its standards label and icon, unknown types to the REST defaults.
	 *
	 * @return void
	 */
	public function testTypeVocabularies(): void {
		$service = $this->service();
		$standards = new ReflectionMethod(CatalogRegistryService::class, 'standardsForType');
		$icons = new ReflectionMethod(CatalogRegistryService::class, 'iconForType');
		$expected = [
			'sms' => [['SMS Gateway'], 'MessageTextOutline'],
			'soap' => [['SOAP'], 'CogOutline'],
			'dso' => [['STAM'], 'CityVariantOutline'],
			'peppol' => [['Peppol'], 'FileDocumentOutline'],
			'psd2' => [['PSD2 AIS'], 'CreditCardOutline'],
			'payment' => [['Payment API'], 'CreditCardOutline'],
			'rest' => [['REST API'], 'DatabaseArrowLeftOutline'],
		];
		foreach ($expected as $type => [$labels, $icon]) {
			$this->assertSame($labels, $standards->invoke($service, $type), $type);
			$this->assertSame($icon, $icons->invoke($service, $type), $type);
		}
	}//end testTypeVocabularies()
}//end class
