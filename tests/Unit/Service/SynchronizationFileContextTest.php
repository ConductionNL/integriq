<?php

/**
 * Golden characterization of SynchronizationService::getFileContext().
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
 * @spec openspec/specs/synchronization-files/spec.md
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
 * Every branch of getFileContext(), recorded against a golden file: the
 * endpoint it returns and the filename, tags, object id, published status and
 * register id it writes back by reference.
 *
 * Regenerate the golden file on the code it must describe with
 * FILE_CONTEXT_GOLDEN=1.
 */
class SynchronizationFileContextTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/get-file-context.json';

	/**
	 * The cases: config, endpoint data and the tags passed in.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$file = ['endpoint' => '/f/1', 'filename' => 'a.pdf', 'published' => '2026-01-01', 'registerId' => 4, 'labels' => ['b', 'a', '', 7, 'b'], 'label' => 'c', 'tag' => '', 'tags' => ['d']];

		return [
			'string-endpoint' => ['config' => [], 'endpoint' => '/plain'],
			'object-id-path' => ['config' => ['objectIdPath' => 'meta.id'], 'endpoint' => ['meta' => ['id' => 'o-1'], 'endpoint' => '/e']],
			'empty-object-id-path' => ['config' => ['objectIdPath' => ''], 'endpoint' => ['endpoint' => '/e']],
			'sub-object-path' => ['config' => ['subObjectFilepath' => 'file'], 'endpoint' => ['file' => $file]],
			'sub-object-path-string' => ['config' => ['subObjectFilepath' => 'file'], 'endpoint' => ['file' => '/s']],
			'sub-object-missing' => ['config' => ['subObjectFilepath' => 'nope'], 'endpoint' => ['file' => $file]],
			'all-tags-default' => ['config' => [], 'endpoint' => $file],
			'all-tags-appended' => ['config' => [], 'endpoint' => $file, 'tags' => ['existing']],
			'all-tags-null-start' => ['config' => [], 'endpoint' => $file, 'tags' => null],
			'use-labels-as-tags' => ['config' => ['useLabelsAsTags' => true, 'allowedLabels' => ['a']], 'endpoint' => $file],
			'use-labels-truthy-not-true' => ['config' => ['useLabelsAsTags' => 1, 'allowedLabels' => ['a']], 'endpoint' => $file],
			'allowed-labels' => ['config' => ['allowedLabels' => ['a', 'c', 'zz']], 'endpoint' => $file],
			'allowed-labels-empty' => ['config' => ['allowedLabels' => []], 'endpoint' => $file],
			'legacy-tags' => ['config' => ['tags' => ['d', 'b']], 'endpoint' => $file],
			'allowed-and-legacy' => ['config' => ['allowedLabels' => ['a'], 'tags' => ['d']], 'endpoint' => $file],
			'legacy-tags-not-array' => ['config' => ['tags' => 'd'], 'endpoint' => $file],
			'no-labels' => ['config' => [], 'endpoint' => ['endpoint' => '/e', 'filename' => '', 'published' => null]],
			'no-endpoint-key' => ['config' => [], 'endpoint' => ['filename' => 'x.txt', 'published' => false, 'registerId' => 'r']],
			'single-tag' => ['config' => [], 'endpoint' => ['endpoint' => '/e', 'tag' => 'solo']],
			'nested-label-arrays' => ['config' => [], 'endpoint' => ['endpoint' => '/e', 'tags' => [['x'], 'y'], 'label' => ['z']]],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$service = new SynchronizationService(
			$this->createMock(CallService::class),
			$this->createMock(MappingService::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
		$method = new ReflectionMethod(SynchronizationService::class, 'getFileContext');

		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$filename = 'start.bin';
			$tags = (array_key_exists('tags', $case) === true ? $case['tags'] : []);
			$objectId = 'start-object';
			$published = null;
			$registerId = null;
			$return = $method->invokeArgs($service, [$case['config'], $case['endpoint'], &$filename, &$tags, &$objectId, &$published, &$registerId]);
			$actual[$name] = ['return' => $return, 'filename' => $filename, 'tags' => $tags, 'objectId' => $objectId, 'published' => $published, 'registerId' => $registerId];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('FILE_CONTEXT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
