<?php

/**
 * Golden characterization of SynchronizationService::resolveParentObjectForRelatedObjectTrigger().
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
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every branch of resolveParentObjectForRelatedObjectTrigger(), recorded
 * against a golden file: the parent object it returns and every config,
 * OpenRegister and logger call.
 *
 * Regenerate the golden file on the code it must describe with
 * RELATED_PARENT_GOLDEN=1.
 */
class SynchronizationRelatedParentTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/resolve-related-parent.json';

	private const UUID = '0b6f1c62-3c55-4b8e-9d0a-6a3c1f1e2d4b';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * The engine over recording collaborators.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnCallback(
			function (array $config) {
				$this->log[] = ['callService.applyConfigDot', json_encode($config)];
				return $config;
			}
		);

		$openRegisters = $this->createMock(ORObjectService::class);
		$openRegisters->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) {
				$this->log[] = ['openRegisters.find', $id, $register, $schema];
				if (($this->case['find'] ?? 'object') === 'throws') {
					throw new RuntimeException('not found');
				}

				if (($this->case['find'] ?? 'object') === 'null') {
					return null;
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['title' => 'parent'], $id);
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getOpenRegisters')->willReturnCallback(
			function () use ($openRegisters) {
				$this->log[] = ['objectService.getOpenRegisters'];
				return (($this->case['noOpenRegisters'] ?? false) === true ? null : $openRegisters);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function ($message, array $context = []) {
				$this->log[] = ['logger.debug', $message, $context];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$callService,
			$this->createMock(MappingService::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(ORObjectService::class),
			$objectService,
			$logger,
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * The cases: synchronization, trigger object and trigger source.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$uuid = self::UUID;
		$sync = fn (array $relation) => ['id' => 9, 'sourceType' => 'register/schema', 'sourceId' => '1/2', 'sourceConfig' => ['triggerFromRelatedObjects' => ['3/7' => $relation]]];
		$byZaak = $sync(['zaak' => true, 'other' => true]);

		return [
			'other-source-type' => ['sync' => ['sourceType' => 'api', 'sourceId' => '1/2'], 'object' => ['zaak' => $uuid]],
			'no-source-type' => ['sync' => [], 'object' => []],
			'empty-source-id' => ['sync' => ['sourceType' => 'register/schema', 'sourceId' => ''], 'object' => []],
			'source-id-without-slash' => ['sync' => ['sourceType' => 'register/schema', 'sourceId' => '12'], 'object' => []],
			'other-trigger-source' => ['sync' => $byZaak, 'object' => ['zaak' => $uuid], 'register' => 4],
			'relation-config-not-array' => ['sync' => ['sourceType' => 'register/schema', 'sourceId' => '1/2', 'sourceConfig' => ['triggerFromRelatedObjects' => ['3/7' => 'zaak']]], 'object' => ['zaak' => $uuid]],
			'empty-relation' => ['sync' => $sync([]), 'object' => ['zaak' => $uuid]],
			'numeric-relation-key' => ['sync' => $sync(['zaak']), 'object' => ['zaak' => $uuid]],
			'numeric-relation-key-in-object' => ['sync' => $sync(['zaak']), 'object' => ['0' => $uuid]],
			'relative-path-reference' => ['sync' => $byZaak, 'object' => ['zaak' => "zaken/$uuid"]],
			'blank-relation-key' => ['sync' => $sync(['  ' => true]), 'object' => ['zaak' => $uuid]],
			'padded-relation-key' => ['sync' => $sync([' zaak ' => true]), 'object' => ['zaak' => $uuid]],
			'only-first-key-counts' => ['sync' => $byZaak, 'object' => ['other' => $uuid]],
			'dotted-relation-key' => ['sync' => $sync(['meta.zaak' => true]), 'object' => ['meta' => ['zaak' => $uuid]]],
			'reference-missing' => ['sync' => $byZaak, 'object' => []],
			'reference-not-string' => ['sync' => $byZaak, 'object' => ['zaak' => ['id' => $uuid]]],
			'reference-blank' => ['sync' => $byZaak, 'object' => ['zaak' => '   ']],
			'uuid' => ['sync' => $byZaak, 'object' => ['zaak' => $uuid]],
			'padded-uuid' => ['sync' => $byZaak, 'object' => ['zaak' => " $uuid "]],
			'url' => ['sync' => $byZaak, 'object' => ['zaak' => "https://x.invalid/api/zaken/$uuid"]],
			'url-trailing-slash' => ['sync' => $byZaak, 'object' => ['zaak' => "https://x.invalid/api/zaken/$uuid/"]],
			'url-without-path' => ['sync' => $byZaak, 'object' => ['zaak' => 'https://x.invalid']],
			'url-last-segment-not-uuid' => ['sync' => $byZaak, 'object' => ['zaak' => "https://x.invalid/$uuid/zaken"]],
			'not-uuid-not-url' => ['sync' => $byZaak, 'object' => ['zaak' => 'zaak-1']],
			'no-open-registers' => ['sync' => $byZaak, 'object' => ['zaak' => $uuid], 'noOpenRegisters' => true],
			'find-null' => ['sync' => $byZaak, 'object' => ['zaak' => $uuid], 'find' => 'null'],
			'find-throws' => ['sync' => $byZaak, 'object' => ['zaak' => $uuid], 'find' => 'throws'],
			'source-id-extra-slash' => ['sync' => ['sourceId' => '1/2/3'] + $byZaak, 'object' => ['zaak' => $uuid]],
			'string-register-schema' => ['sync' => $sync(['zaak' => true]), 'object' => ['zaak' => $uuid], 'register' => '3', 'schema' => '7'],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->case = $case;
			$service = $this->service();
			$this->log = [];
			try {
				$outcome = ['return' => $service->resolveParentObjectForRelatedObjectTrigger($case['sync'], $case['object'], ($case['register'] ?? 3), ($case['schema'] ?? 7), 'update')];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['outcome' => $outcome, 'log' => $this->log];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('RELATED_PARENT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
