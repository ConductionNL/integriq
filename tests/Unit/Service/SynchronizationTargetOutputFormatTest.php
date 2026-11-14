<?php

/**
 * A synchronization sends an XML or CSV mapping's output as that text to an API target.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-sends-the-mappings-output-format-to-an-api-target-req-mfl-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Util\MappingOutput;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\Integriq\Service\SynchronizationService
 * @uses   \OCA\Integriq\Util\MappingOutput
 * @uses   \OCA\Integriq\Util\ArrayToXml
 * @uses   \OCA\Integriq\Util\CsvCodec
 */
class SynchronizationTargetOutputFormatTest extends TestCase {

	/**
	 * The configs CallService received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * How often each mapping reference was read.
	 *
	 * @var array<string, int>
	 */
	private array $reads = [];

	/**
	 * The stored mappings by reference.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const MAPPINGS = [
		'person-to-xml' => ['outputFormat' => 'xml', 'outputOptions' => ['rootElement' => 'persoon']],
		'rows-to-csv' => ['outputFormat' => 'csv', 'outputOptions' => ['csvDelimiter' => ';']],
		'plain' => [],
	];

	/**
	 * The engine over recording collaborators.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$or = $this->createMock(ORObjectService::class);
		$or->method('find')->willReturnCallback(
			static function ($id, ?string $register = null, ?string $schema = null) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(['uuid' => (string)$id, 'name' => 'Target', 'location' => 'https://api.example.invalid/v1']);
				return $entity;
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint = '', string $method = 'GET', array $config = []) {
				$this->calls[] = ['method' => $method, 'config' => $config];
				$callLog = new ObjectEntity();
				$callLog->setObject(['statusCode' => 201, 'response' => ['statusCode' => 201, 'body' => '{"id":"remote-1"}']]);
				return $callLog;
			}
		);

		$mapping = $this->createMock(MappingService::class);
		$mapping->method('findMapping')->willReturnCallback(
			function (string $reference): ?ObjectEntity {
				$this->reads[$reference] = (($this->reads[$reference] ?? 0) + 1);
				if (isset(self::MAPPINGS[$reference]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($reference);
				$entity->setObject(self::MAPPINGS[$reference]);
				return $entity;
			}
		);
		$mapping->method('renderOutput')->willReturnCallback(
			static fn (ObjectEntity $map, array $result): array => (new MappingOutput())->render(mapping: $map->getObject(), result: $result)
		);
		$mapping->method('getMapping')->willReturnCallback(static fn (string $id): Mapping => (new Mapping())->hydrate(['name' => $id]));
		$mapping->method('executeMapping')->willReturnCallback(static fn ($map, array $input): array => $input);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$calls,
			$mapping,
			$this->createMock(ContainerInterface::class),
			$or,
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * Write one object to the target.
	 *
	 * @param SynchronizationService $service      The engine.
	 * @param array<string, mixed>   $sync         Synchronization fields over the base.
	 * @param string|null            $targetId     The contract's target id; null creates.
	 * @param array<string, mixed>   $targetConfig The target config.
	 *
	 * @return void
	 */
	private function write(SynchronizationService $service, array $sync, ?string $targetId = null, array $targetConfig = []): void {
		$synchronization = array_merge(['id' => 'sync-1', 'sourceType' => 'api', 'sourceId' => 'src-1', 'targetType' => 'api', 'targetId' => 'target-1', 'targetConfig' => $targetConfig], $sync);
		$contract = ['synchronizationId' => 'sync-1', 'originId' => 'origin-1', 'targetId' => $targetId];
		$object = ['naam' => 'Jan', 'woonplaats' => 'Utrecht'];
		$args = [$synchronization, $contract, '/personen', &$object, null, null];
		(new ReflectionMethod(SynchronizationService::class, 'writeObjectToTarget'))->invokeArgs($service, $args);
	}//end write()

	/**
	 * An XML mapping sends XML with its content type, and no JSON.
	 *
	 * @return void
	 */
	public function testAnXmlMappingIsSentAsXml(): void {
		$this->write(service: $this->service(), sync: ['sourceTargetMapping' => 'person-to-xml'], targetConfig: ['headers' => ['Accept' => 'application/xml']]);

		$config = $this->calls[0]['config'];
		self::assertSame('POST', $this->calls[0]['method']);
		self::assertArrayNotHasKey('json', $config);
		self::assertSame(['Accept' => 'application/xml', 'Content-Type' => 'application/xml'], $config['headers']);
		self::assertSame("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<persoon>\n  <naam>Jan</naam>\n  <woonplaats>Utrecht</woonplaats>\n</persoon>\n", $config['body']);
	}//end testAnXmlMappingIsSentAsXml()

	/**
	 * A CSV update mapping is used on an update, over the synchronization's mapping.
	 *
	 * @return void
	 */
	public function testAnUpdateUsesTheUpdateMappingsFormat(): void {
		$this->write(service: $this->service(), sync: ['sourceTargetMapping' => 'person-to-xml'], targetId: 'remote-9', targetConfig: ['updateMapping' => 'rows-to-csv']);

		$config = $this->calls[0]['config'];
		self::assertSame('PUT', $this->calls[0]['method']);
		self::assertArrayNotHasKey('json', $config);
		self::assertSame('text/csv', $config['headers']['Content-Type']);
		self::assertSame("naam;woonplaats\nJan;Utrecht\n", $config['body']);
	}//end testAnUpdateUsesTheUpdateMappingsFormat()

	/**
	 * A mapping without an output format, an unknown one and no mapping all send JSON as before.
	 *
	 * @return void
	 */
	public function testWithoutAnOutputFormatJsonIsSentAsBefore(): void {
		$service = $this->service();
		foreach ([['sourceTargetMapping' => 'plain'], ['sourceTargetMapping' => 'missing'], []] as $sync) {
			$this->write(service: $service, sync: $sync);
		}

		self::assertCount(3, $this->calls);
		foreach ($this->calls as $call) {
			self::assertSame(['naam' => 'Jan', 'woonplaats' => 'Utrecht'], $call['config']['json']);
			self::assertArrayNotHasKey('body', $call['config']);
		}
	}//end testWithoutAnOutputFormatJsonIsSentAsBefore()

	/**
	 * A mapping is read once per engine, however many objects are written.
	 *
	 * @return void
	 */
	public function testAMappingIsReadOncePerRun(): void {
		$service = $this->service();
		for ($i = 0; $i < 3; $i++) {
			$this->write(service: $service, sync: ['sourceTargetMapping' => 'person-to-xml']);
		}

		self::assertSame(['person-to-xml' => 1], $this->reads);
		self::assertCount(3, $this->calls);
	}//end testAMappingIsReadOncePerRun()
}//end class
