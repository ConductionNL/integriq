<?php

/**
 * Golden characterization of SynchronizationService::fetchExtraDataForObject().
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
 * @spec openspec/specs/synchronization-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every branch of fetchExtraDataForObject(), recorded against a golden file:
 * each source fetch and template render with its arguments, and the object
 * returned (or the exception). getObjectFromSource() is stubbed.
 *
 * Regenerate the golden file on the code it must describe with
 * EXTRA_DATA_GOLDEN=1.
 */
class SynchronizationExtraDataTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/fetch-extra-data.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The engine with getObjectFromSource() and renderTemplateString() recorded.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$mapping = $this->createMock(MappingService::class);
		$mapping->method('renderTemplateString')->willReturnCallback(
			function (string $template, array $context) {
				$this->log[] = ['mapping.renderTemplateString', $template, $context];
				return 'rendered:' . $template;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$this->createMock(CallService::class),
					$mapping,
					$this->createMock(ContainerInterface::class),
					$this->createMock(ORObjectService::class),
					$this->createMock(ObjectService::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SynchronizationLogService::class),
					$appConfig,
					$this->createMock(SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['getObjectFromSource'])
			->getMock();
		$service->method('getObjectFromSource')->willReturnCallback(
			function (array $synchronization, string $endpoint, $source = null) {
				$this->log[] = ['getObjectFromSource', $synchronization['sourceConfig'] ?? null, $endpoint, $source];
				if (str_contains($endpoint, 'list') === true) {
					return ['items' => [['id' => 'i1', 'ref' => '/r/1'], ['id' => 'i2', 'ref' => '/r/2']], 'count' => 2];
				}

				return ['fetched' => $endpoint];
			}
		);

		return $service;
	}//end service()

	/**
	 * The cases: extra-data config, object, origin id and synchronization overrides.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$object = ['id' => 'o-1', 'links' => ['detail' => '/detail/o-1'], 'sub' => ['id' => 's-9'], 'alt' => ['id' => 'a-3'], 'naam' => 'x'];

		return [
			'no-endpoint-config' => ['config' => ['keyToSetExtraData' => 'k']],
			'dynamic' => ['config' => ['dynamicEndpointLocation' => 'links.detail']],
			'dynamic-missing' => ['config' => ['dynamicEndpointLocation' => 'links.none']],
			'static-origin-from-object' => ['config' => ['staticEndpoint' => '/x/{{ originId }}/{{originId}}']],
			'static-origin-given' => ['config' => ['staticEndpoint' => '/x/{{ originId }}'], 'originId' => 'given'],
			'static-id-location' => ['config' => ['staticEndpoint' => '/x/{{originId}}', 'endpointIdLocation' => 'alt.id'], 'originId' => 'given'],
			'static-id-location-missing' => ['config' => ['staticEndpoint' => '/x/{{originId}}', 'endpointIdLocation' => 'alt.none']],
			'static-id-position' => ['config' => ['staticEndpoint' => '/x/{{ originId }}'], 'sync' => ['sourceConfig' => ['idPosition' => 'sub.id']]],
			'static-no-origin' => ['config' => ['staticEndpoint' => '/x/{{ originId }}'], 'object' => ['naam' => 'no id']],
			'static-sub-object' => ['config' => ['staticEndpoint' => '/x/{{ originId }}/s/{{ subObjectId }}/{{subObjectId}}', 'subObjectId' => 'sub.id']],
			'static-sub-object-missing' => ['config' => ['staticEndpoint' => '/x/{{ subObjectId }}', 'subObjectId' => 'nope']],
			'dynamic-and-static' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'staticEndpoint' => '/static/{{ originId }}']],
			'template' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'endpointTemplate' => '{{ endpoint }}?x=1']],
			'template-empty' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'endpointTemplate' => '']],
			'template-not-string' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'endpointTemplate' => ['a']]],
			'template-only-static' => ['config' => ['staticEndpoint' => '/s/{{ originId }}', 'endpointTemplate' => 't']],
			'unset-config-key' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'unsetConfigKey' => 'headers'], 'sync' => ['sourceConfig' => ['headers' => ['a' => 1], 'keep' => true]]],
			'unset-config-key-absent' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'unsetConfigKey' => 'nothing'], 'sync' => ['sourceConfig' => ['keep' => true]]],
			'source-scalar' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'source' => 'src-2']],
			'source-not-scalar' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'source' => ['src-2']]],
			'per-result' => ['config' => ['staticEndpoint' => '/list/{{ originId }}', 'resultsLocation' => 'items', 'extraDataConfigPerResult' => ['dynamicEndpointLocation' => 'ref', 'keyToSetExtraData' => 'more']]],
			'static-id-location-no-origin' => ['config' => ['staticEndpoint' => '/x/{{originId}}', 'endpointIdLocation' => 'alt.id'], 'object' => ['alt' => ['id' => 'a-3']]],
			'per-result-keeps-origin' => ['config' => ['staticEndpoint' => '/list/{{ originId }}', 'resultsLocation' => 'items', 'extraDataConfigPerResult' => ['staticEndpoint' => '/per/{{ originId }}']]],
			'per-result-no-location' => ['config' => ['staticEndpoint' => '/one', 'extraDataConfigPerResult' => ['dynamicEndpointLocation' => 'nope']]],
			'key' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'keyToSetExtraData' => 'extra']],
			'merge-true' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'mergeExtraData' => true]],
			'merge-string' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'mergeExtraData' => 'true', 'keyToSetExtraData' => 'extra']],
			'merge-other' => ['config' => ['dynamicEndpointLocation' => 'links.detail', 'mergeExtraData' => 1]],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$base = ['id' => 'o-1', 'links' => ['detail' => '/detail/o-1'], 'sub' => ['id' => 's-9'], 'alt' => ['id' => 'a-3'], 'naam' => 'x'];
		$method = new ReflectionMethod(SynchronizationService::class, 'fetchExtraDataForObject');

		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$service = $this->service();
			$this->log = [];
			$synchronization = array_merge(['id' => 'sync-1', 'sourceConfig' => ['headers' => ['h' => 1]]], ($case['sync'] ?? []));
			try {
				$outcome = ['return' => $method->invoke($service, $synchronization, $case['config'], ($case['object'] ?? $base), ($case['originId'] ?? null))];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => preg_replace('/, called in .*$/', '', $exception->getMessage())];
			}

			$actual[$name] = ['outcome' => $outcome, 'log' => $this->log];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('EXTRA_DATA_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
