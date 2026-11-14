<?php

/**
 * Unit tests for the mapping lookup, its allowlist and the runtime function.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Twig
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
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Twig;

use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\SourceMappingServiceInterface;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Twig\LookupAllowlist;
use OCA\Integriq\Twig\MappingLookup;
use OCA\Integriq\Twig\MappingRuntime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A lookup reads one allowed schema, refuses the rest, and does not pick between two matches.
 */
#[CoversClass(MappingLookup::class)]
#[CoversClass(LookupAllowlist::class)]
#[UsesClass(MappingRuntime::class)]
class MappingLookupTest extends TestCase {

	/**
	 * The queries that reached OpenRegister.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The stored app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Logged warnings.
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * An allowlist over in-memory app config.
	 *
	 * @return LookupAllowlist
	 */
	private function allowlist(): LookupAllowlist {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new LookupAllowlist(appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class));
	}//end allowlist()

	/**
	 * The lookup over a register holding the given gemeenten.
	 *
	 * @param list<array<string, mixed>> $gemeenten The objects.
	 *
	 * @return MappingLookup
	 */
	private function lookup(array $gemeenten): MappingLookup {
		$allowlist = $this->allowlist();
		$allowlist->add(schema: 'codelijsten/gemeenten', principal: 'admin');

		$openRegister = $this->createMock(ObjectService::class);
		$openRegister->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($gemeenten): array {
				$this->queries[] = ['config' => $config, 'rbac' => $_rbac];
				$filters = $config['filters'];
				unset($filters['register'], $filters['schema']);
				$results = [];
				foreach ($gemeenten as $gemeente) {
					if (array_intersect_assoc($filters, $gemeente) === $filters) {
						$entity = new ObjectEntity();
						$entity->setObject($gemeente);
						$results[] = $entity;
					}
				}

				return ['results' => array_slice($results, 0, $config['limit'])];
			}
		);
		$objects = $this->createMock(SourceMappingServiceInterface::class);
		$objects->method('getOpenRegisters')->willReturn($openRegister);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->logged[] = $message;
		});

		return new MappingLookup(allowlist: $allowlist, objectService: $objects, logger: $logger);
	}//end lookup()

	/**
	 * A gemeentecode becomes a name; an unknown code is the default; the answer is cached.
	 *
	 * @return void
	 */
	public function testACodeBecomesItsName(): void {
		$lookup = $this->lookup(gemeenten: [['code' => '0363', 'naam' => 'Amsterdam', 'provincie' => ['naam' => 'Noord-Holland']], ['code' => '0599', 'naam' => 'Rotterdam']]);

		self::assertSame('Amsterdam', $lookup->lookup(schema: 'codelijsten/gemeenten', filters: ['code' => '0363'], field: 'naam', default: ''));
		self::assertSame('Amsterdam', $lookup->lookup(schema: 'codelijsten/gemeenten', filters: ['code' => '0363'], field: 'naam', default: ''));
		self::assertSame('Noord-Holland', $lookup->lookup(schema: 'codelijsten/gemeenten', filters: ['code' => '0363'], field: 'provincie.naam'));
		self::assertSame('onbekend', $lookup->lookup(schema: 'codelijsten/gemeenten', filters: ['code' => '9999'], field: 'naam', default: 'onbekend'));

		self::assertCount(2, $this->queries, 'one query per distinct question');
		self::assertSame(['register' => 'codelijsten', 'schema' => 'gemeenten', 'code' => '0363'], $this->queries[0]['config']['filters']);
		self::assertSame(2, $this->queries[0]['config']['limit']);
		self::assertFalse($this->queries[0]['rbac']);
	}//end testACodeBecomesItsName()

	/**
	 * A schema off the allowlist fails naming it, and nothing is read.
	 *
	 * @return void
	 */
	public function testASchemaOffTheAllowlistIsRefused(): void {
		$lookup = $this->lookup(gemeenten: [['code' => '0363', 'naam' => 'Amsterdam']]);

		try {
			$lookup->lookup(schema: 'hr/medewerkers', filters: ['code' => '0363'], field: 'naam');
			self::fail('the lookup was not refused');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('"hr/medewerkers"', $e->getMessage());
		}

		self::assertSame([], $this->queries);
	}//end testASchemaOffTheAllowlistIsRefused()

	/**
	 * Two matches give the default and a warning naming the filters.
	 *
	 * @return void
	 */
	public function testTwoMatchesDoNotPickOne(): void {
		$lookup = $this->lookup(gemeenten: [['code' => '0363', 'naam' => 'Amsterdam'], ['code' => '0363', 'naam' => 'Amsterdam (oud)']]);

		self::assertSame('', $lookup->lookup(schema: 'codelijsten/gemeenten', filters: ['code' => '0363'], field: 'naam', default: ''));
		self::assertCount(1, $lookup->warnings());
		self::assertStringContainsString('{"code":"0363"}', $lookup->warnings()[0]);
		self::assertCount(1, $this->logged);
	}//end testTwoMatchesDoNotPickOne()

	/**
	 * A filter that is not a single value is refused before anything is read.
	 *
	 * @return void
	 */
	public function testANestedFilterIsRefused(): void {
		$lookup = $this->lookup(gemeenten: []);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('the filter "code" must be a single value');
		$lookup->lookup(schema: 'codelijsten/gemeenten', filters: ['code' => ['$ne' => '']], field: 'naam');
	}//end testANestedFilterIsRefused()

	/**
	 * The allowlist takes one exact register/schema, records who added it, and refuses patterns.
	 *
	 * @return void
	 */
	public function testTheAllowlistRecordsWhoAddedASchema(): void {
		$allowlist = $this->allowlist();

		self::assertSame(['added' => true, 'reason' => ''], $allowlist->add(schema: ' codelijsten/gemeenten ', principal: 'admin'));
		self::assertFalse($allowlist->add(schema: 'codelijsten/gemeenten', principal: 'admin')['added']);
		self::assertFalse($allowlist->add(schema: 'codelijsten/*', principal: 'admin')['added']);
		self::assertFalse($allowlist->add(schema: 'gemeenten', principal: 'admin')['added']);
		self::assertFalse($allowlist->add(schema: 'codelijsten/landen', principal: ' ')['added']);

		$entries = $allowlist->entries();
		self::assertSame(['codelijsten/gemeenten'], array_keys($entries));
		self::assertSame('admin', $entries['codelijsten/gemeenten']['addedBy']);
		self::assertNotSame('', $entries['codelijsten/gemeenten']['addedAt']);
		self::assertTrue($allowlist->allows(schema: 'codelijsten/gemeenten'));
		self::assertFalse($allowlist->allows(schema: 'Codelijsten/gemeenten'));

		self::assertTrue($allowlist->remove(schema: 'codelijsten/gemeenten'));
		self::assertFalse($allowlist->remove(schema: 'codelijsten/gemeenten'));
		self::assertSame([], $allowlist->entries());

		$this->config[LookupAllowlist::CONFIG_KEY] = '{broken';
		self::assertSame([], $allowlist->entries());
	}//end testTheAllowlistRecordsWhoAddedASchema()

	/**
	 * The runtime function delegates, and refuses when it has no lookup.
	 *
	 * @return void
	 */
	public function testTheRuntimeFunction(): void {
		$args = [
			'mappingService' => $this->createMock(MappingServiceInterface::class),
			'callService' => $this->createMock(CallServiceInterface::class),
			'fileService' => $this->createMock(FileService::class),
			'objectService' => $this->createMock(SourceMappingServiceInterface::class),
			'synchronizationContractService' => $this->createMock(SynchronizationContractServiceInterface::class),
		];

		$runtime = new MappingRuntime(...$args, lookup: $this->lookup(gemeenten: [['code' => '0363', 'naam' => 'Amsterdam']]));
		self::assertSame('Amsterdam', $runtime->lookup(schema: 'codelijsten/gemeenten', filters: ['code' => '0363'], field: 'naam', default: ''));

		$this->expectException(RuntimeException::class);
		(new MappingRuntime(...$args))->lookup(schema: 'codelijsten/gemeenten', filters: [], field: 'naam');
	}//end testTheRuntimeFunction()
}//end class
