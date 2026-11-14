<?php

/**
 * Integriq — feed declarations synced into endpoints and an API product.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Feed\FeedDeclarationValidator;
use OCA\Integriq\Service\Feed\FeedSchemaFieldsInterface;
use OCA\Integriq\Service\FeedDeclarationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * shillinq's fixture declaration (tests/fixtures/feeds/shillinq) against an in-memory object store.
 */
#[CoversClass(FeedDeclarationService::class)]
#[UsesClass(FeedDeclarationValidator::class)]
class FeedDeclarationServiceTest extends TestCase {

	/**
	 * The fields each schema declares.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $schemas = [
		'ledger-line' => ['id', 'period', 'account', 'amount', 'administrationId', 'status', 'iban'],
		'account' => ['id', 'code', 'name', 'administrationId'],
		'period' => ['id', 'start', 'end', 'administrationId'],
		'relation' => ['id', 'name', 'kvkNumber', 'administrationId'],
	];

	/**
	 * The stored objects, per schema and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = ['endpoint' => [], 'api_product' => []];

	/**
	 * The enabled apps and where they live.
	 *
	 * @var array<string, string>
	 */
	private array $apps = [];

	/**
	 * A temporary app directory, removed after the test.
	 *
	 * @var string|null
	 */
	private ?string $tmp = null;

	/**
	 * Remove the temporary app.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->tmp !== null) {
			@unlink($this->tmp . '/lib/Settings/feeds.json');
			@rmdir($this->tmp . '/lib/Settings');
			@rmdir($this->tmp . '/lib');
			@rmdir($this->tmp);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The service over the in-memory store.
	 *
	 * @return FeedDeclarationService
	 */
	private function service(): FeedDeclarationService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturnCallback(fn (): array => array_keys($this->apps));
		$appManager->method('getAppPath')->willReturnCallback(fn (string $app): string => $this->apps[$app] ?? throw new \RuntimeException('no app ' . $app));

		$fields = $this->createMock(FeedSchemaFieldsInterface::class);
		$fields->method('fieldsOf')->willReturnCallback(fn (string $register, string $schema): ?array => $this->schemas[$schema] ?? null);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$found = [];
				foreach (($this->store[$schema] ?? []) as $uuid => $data) {
					foreach ($filters as $field => $value) {
						if (($data[$field] ?? null) !== $value) {
							continue 2;
						}
					}

					$found[] = $this->entity(uuid: $uuid, data: $data);
				}

				return ['results' => $found];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				$uuid = ($uuid ?? $schema . '-' . (count($this->store[$schema]) + 1));
				$this->store[$schema][$uuid] = json_decode((string)json_encode($object), true);
				return $this->entity(uuid: $uuid, data: $object);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, ?string $register = null, ?string $schema = null): bool {
				unset($this->store[$schema][$uuid]);
				return true;
			}
		);

		return new FeedDeclarationService(
			$appManager,
			new FeedDeclarationValidator(),
			$fields,
			$objects,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * An entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Enable shillinq from the fixture, or from a declaration written for the test.
	 *
	 * @param array<string, mixed>|null $declaration The declaration; the fixture when null.
	 *
	 * @return void
	 */
	private function shillinq(?array $declaration = null): void {
		if ($declaration === null) {
			$this->apps['shillinq'] = dirname(__DIR__, 2) . '/fixtures/feeds/shillinq';
			return;
		}

		$this->tmp = sys_get_temp_dir() . '/integriq-feeds-' . bin2hex(random_bytes(4));
		mkdir($this->tmp . '/lib/Settings', 0777, true);
		file_put_contents($this->tmp . '/lib/Settings/feeds.json', (string)json_encode($declaration));
		$this->apps['shillinq'] = $this->tmp;
	}//end shillinq()

	/**
	 * The fixture declaration, decoded.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private static function fixture(): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/feeds/shillinq/lib/Settings/feeds.json'), true);
	}//end fixture()

	/**
	 * The stored feed endpoints by path.
	 *
	 * @return array<string, array<string, mixed>> The endpoints.
	 */
	private function endpointsByPath(): array {
		$byPath = [];
		foreach ($this->store['endpoint'] as $uuid => $data) {
			$byPath[$data['endpoint']] = $data + ['uuid' => $uuid];
		}

		ksort($byPath);
		return $byPath;
	}//end endpointsByPath()

	/**
	 * Four datasets become four GET endpoints and one API product holding them.
	 *
	 * @return void
	 */
	public function testFourDatasetsBecomeFourEndpointsAndOneProduct(): void {
		$this->shillinq();

		$summary = $this->service()->sync();

		$this->assertSame(['created' => 4, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'refused' => []], $summary);
		$endpoints = $this->endpointsByPath();
		$this->assertSame(
			['feeds/shillinq/accounts', 'feeds/shillinq/ledger-lines', 'feeds/shillinq/periods', 'feeds/shillinq/relations'],
			array_keys($endpoints)
		);

		$ledger = $endpoints['feeds/shillinq/ledger-lines'];
		$this->assertSame('GET', $ledger['method']);
		$this->assertSame('feed', $ledger['targetType']);
		$this->assertSame('shillinq/ledger-line', $ledger['targetId']);
		$this->assertSame('feed:shillinq:ledger-lines', $ledger['reference']);
		$this->assertSame(['id', 'period', 'account', 'amount', 'administrationId'], $ledger['feed']['fields']);
		$this->assertSame(['status' => 'posted'], $ledger['feed']['filter']);
		$this->assertSame(['parameter' => 'administrationId', 'field' => 'administrationId'], $ledger['feed']['scope']);
		$this->assertSame(1000, $ledger['feed']['pageSize']);

		$this->assertCount(1, $this->store['api_product']);
		$product = array_values($this->store['api_product'])[0];
		$this->assertSame('shillinq data feeds', $product['name']);
		$this->assertSame('shillinq-data-feeds', $product['productSlug']);
		$this->assertEqualsCanonicalizing(array_column($endpoints, 'uuid'), $product['endpoints']);
	}//end testFourDatasetsBecomeFourEndpointsAndOneProduct()

	/**
	 * A second sync of the same declaration changes nothing.
	 *
	 * @return void
	 */
	public function testASecondSyncIsIdempotent(): void {
		$this->shillinq();
		$this->service()->sync();
		$before = $this->store;

		$summary = $this->service()->sync();

		$this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 4, 'refused' => []], $summary);
		$this->assertSame($before, $this->store);
	}//end testASecondSyncIsIdempotent()

	/**
	 * A dataset naming a field its schema lacks is refused with the field named; the others are served.
	 *
	 * @return void
	 */
	public function testADatasetWithAMissingFieldIsRefusedAndTheOthersAreKept(): void {
		$declaration = self::fixture();
		$declaration['feeds'][1]['fields'][] = 'balance';
		$this->shillinq(declaration: $declaration);

		$summary = $this->service()->sync();

		$this->assertSame(3, $summary['created']);
		$this->assertSame(['shillinq/accounts' => 'the schema "account" does not declare balance'], $summary['refused']);
		$this->assertArrayNotHasKey('feeds/shillinq/accounts', $this->endpointsByPath());
		$this->assertCount(3, array_values($this->store['api_product'])[0]['endpoints']);
	}//end testADatasetWithAMissingFieldIsRefusedAndTheOthersAreKept()

	/**
	 * A refused dataset that was served before keeps its endpoint until the declaration is fixed.
	 *
	 * @return void
	 */
	public function testARefusedDatasetKeepsItsEarlierEndpoint(): void {
		$this->shillinq();
		$this->service()->sync();
		$served = $this->endpointsByPath()['feeds/shillinq/accounts'];

		$this->schemas['account'] = ['id', 'code', 'administrationId'];
		$summary = $this->service()->sync();

		$this->assertSame('the schema "account" does not declare name', $summary['refused']['shillinq/accounts']);
		$this->assertSame($served, $this->endpointsByPath()['feeds/shillinq/accounts']);
		$this->assertContains($served['uuid'], array_values($this->store['api_product'])[0]['endpoints']);
	}//end testARefusedDatasetKeepsItsEarlierEndpoint()

	/**
	 * A dataset no declaration names any more is removed, and so are a disabled app's feeds.
	 *
	 * @return void
	 */
	public function testUndeclaredDatasetsAreRemoved(): void {
		$declaration = self::fixture();
		$this->shillinq(declaration: $declaration);
		$this->service()->sync();

		array_pop($declaration['feeds']);
		file_put_contents($this->tmp . '/lib/Settings/feeds.json', (string)json_encode($declaration));
		$summary = $this->service()->sync();
		$this->assertSame(1, $summary['deleted']);
		$this->assertArrayNotHasKey('feeds/shillinq/relations', $this->endpointsByPath());
		$this->assertCount(3, array_values($this->store['api_product'])[0]['endpoints']);

		unset($this->apps['shillinq']);
		$summary = $this->service()->sync();
		$this->assertSame(3, $summary['deleted']);
		$this->assertSame([], $this->store['endpoint']);
		$this->assertSame([], array_values($this->store['api_product'])[0]['endpoints']);
	}//end testUndeclaredDatasetsAreRemoved()

	/**
	 * A changed dataset updates its endpoint.
	 *
	 * @return void
	 */
	public function testAChangedDatasetIsUpdated(): void {
		$declaration = self::fixture();
		$this->shillinq(declaration: $declaration);
		$this->service()->sync();

		$declaration['feeds'][0]['pageSize'] = 250;
		file_put_contents($this->tmp . '/lib/Settings/feeds.json', (string)json_encode($declaration));
		$summary = $this->service()->sync(app: 'shillinq');

		$this->assertSame(['created' => 0, 'updated' => 1, 'deleted' => 0, 'unchanged' => 3, 'refused' => []], $summary);
		$this->assertSame(250, $this->endpointsByPath()['feeds/shillinq/ledger-lines']['feed']['pageSize']);
	}//end testAChangedDatasetIsUpdated()

	/**
	 * A file that cannot be read changes nothing, and an app that claims another id is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileChangesNothing(): void {
		$this->shillinq(declaration: ['app' => 'hermiq', 'feeds' => []]);

		$summary = $this->service()->sync();

		$this->assertSame(['shillinq' => 'it claims the app id "hermiq"'], $summary['refused']);
		$this->assertSame(['endpoint' => [], 'api_product' => []], $this->store);

		file_put_contents($this->tmp . '/lib/Settings/feeds.json', '{not json');
		$this->assertStringStartsWith('it is not valid JSON', $this->service()->sync()['refused']['shillinq']);
	}//end testAnUnreadableFileChangesNothing()

	/**
	 * A dataset over a schema that does not exist is refused, naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaIsRefused(): void {
		$declaration = self::fixture();
		$declaration['feeds'] = [$declaration['feeds'][0]];
		$declaration['feeds'][0]['schema'] = 'journal';
		$this->shillinq(declaration: $declaration);

		$summary = $this->service()->sync();

		$this->assertSame(['shillinq/ledger-lines' => 'the register "shillinq" has no schema "journal"'], $summary['refused']);
		$this->assertSame([], $this->store['endpoint']);
	}//end testAnUnknownSchemaIsRefused()

	/**
	 * An app without a declaration gets no feeds and no product.
	 *
	 * @return void
	 */
	public function testAnAppWithoutADeclarationGetsNothing(): void {
		$this->apps['planninq'] = sys_get_temp_dir() . '/integriq-no-such-app';

		$summary = $this->service()->sync();

		$this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'refused' => []], $summary);
		$this->assertSame(['endpoint' => [], 'api_product' => []], $this->store);
		$this->assertSame('feeds/planninq/x', FeedDeclarationService::endpointPath(app: 'planninq', key: 'x'));
	}//end testAnAppWithoutADeclarationGetsNothing()
}//end class
