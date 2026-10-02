<?php

/**
 * The zaken set, installed and run against a recorded 1.6 store, lands three objects in the bound schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-six-packaged-slug-referenced-zgw-consumer-sets-req-zgwc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Zgw;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\NotificatiesSubscriberService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\Integriq\Service\Zgw\ZgwSetInstallGuard;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;
use OCA\Integriq\Tests\Helpers\CatalogueL10n;

/**
 * The installer and the REAL synchronization and mapping engines; only HTTP and OpenRegister storage are faked.
 *
 * The store answers a recorded Zaken API 1.6 page: the spec's "mock-mode
 * source" (design D1, decided 2 Oct 2026: the fixture is a test double of the
 * transport, not a product mode a tenant can switch on).
 */
class ZgwSetPullFixtureTest extends TestCase {

	/**
	 * OpenRegister, in memory: schema => uuid|slug => object.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * App values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Calls per endpoint.
	 *
	 * @var array<string, int>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$path     = dirname(__DIR__, 4) . '/lib/Settings/register.d/zgw-consumer-sets.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$this->store[$self['schema']][$self['slug']] = $object + ['id' => $self['slug']];
		}

		// The operator's step before installing: the source on (the seeded address stands in for the store's).
		foreach (array_keys($this->store['source']) as $slug) {
			$this->store['source'][$slug]['isEnabled'] = true;
		}
	}//end setUp()

	/**
	 * The five data sets, each with the list endpoint its pull reads and a fixture of three resources.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function dataSets(): array {
		return [
			'zaken'      => ['zgw-zaken', '/zaken'],
			'documenten' => ['zgw-documenten', '/enkelvoudiginformatieobjecten'],
			'catalogi'   => ['zgw-catalogi', '/zaaktypen'],
			'besluiten'  => ['zgw-besluiten', '/besluiten'],
			'objecten'   => ['zgw-objecten', '/objects'],
		];
	}//end dataSets()

	/**
	 * Three zaken as a Zaken API 1.6 list answers them.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fixture(string $set='zgw-zaken', string $path='/zaken'): array {
		if ($set !== 'zgw-zaken') {
			$location = $this->store['source']['zgw-set-' . substr($set, 4)]['location'];
			$items    = [];
			foreach (['0001', '0002', '0003'] as $n) {
				$items[] = ['url' => $location . $path . '/6a1d0c2e-0000-4000-8000-00000000' . $n, 'uuid' => '6a1d0c2e-0000-4000-8000-00000000' . $n];
			}

			return $items;
		}

		$zaken = [];
		foreach (['0001', '0002', '0003'] as $n) {
			$zaken[] = [
				'url'               => $this->store['source']['zgw-set-zaken']['location'] . '/zaken/5f3b0c1e-0000-4000-8000-00000000' . $n,
				'uuid'              => '5f3b0c1e-0000-4000-8000-00000000' . $n,
				'identificatie'     => 'ZAAK-2026-' . $n,
				'bronorganisatie'   => '002220647',
				'omschrijving'      => 'Aanvraag omgevingsvergunning ' . $n,
				'zaaktype'          => 'https://open-zaak.example.nl/catalogi/api/v1/zaaktypen/7f2c',
				'registratiedatum'  => '2026-09-30',
				'startdatum'        => '2026-09-30',
				'verantwoordelijkeOrganisatie' => '002220647',
				'status'            => null,
				'archiefnominatie'  => null,
			];
		}

		return $zaken;
	}//end fixture()

	private function positions(string $class, string $method): array {
		$positions = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $parameter) {
			$positions[$parameter->getName()] = $i;
		}

		return $positions;
	}//end positions()

	private function or(): ORObjectService {
		$or      = ObjectServiceMockBuilder::make($this);
		$findPos = $this->positions(ORObjectService::class, 'find');
		$or->method('find')->willReturnCallback(
			function (...$args) use ($findPos) {
				$id     = (string)($args[$findPos['id']] ?? '');
				$schema = (string)($args[$findPos['schema']] ?? '');
				if (isset($this->store[$schema][$id]) === false) {
					return null;
				}

				return ObjectServiceMockBuilder::objectEntity($this, $this->store[$schema][$id], $id);
			}
		);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, ...$rest) {
				$key                                = (string)($uuid ?? ($object['uuid'] ?? ($schema . '-' . (count($this->store[(string)$schema] ?? []) + 1))));
				$this->store[(string)$schema][$key] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, is_array($object) ? $object : [], $key);
			}
		);
		$or->method('saveObjects')->willReturnCallback(
			function (array $objects, $register=null, $schema=null, ...$rest): array {
				$saved = [];
				foreach ($objects as $object) {
					$key                                = (string)($object['uuid'] ?? ($object['id'] ?? ($schema . '-' . (count($this->store[(string)$schema] ?? []) + 1))));
					$this->store[(string)$schema][$key] = $object;
					$saved[]                            = ObjectServiceMockBuilder::objectEntity($this, $object, $key);
				}

				return ['saved' => $saved, 'errors' => [], 'statistics' => ['saved' => count($saved)]];
			}
		);
		$or->method('findAll')->willReturnCallback(
			function (array $config=[], ...$rest) {
				$filters = ($config['filters'] ?? []);
				if (isset($filters['originId']) === false && isset($filters['synchronizationId']) === false) {
					return ['results' => [], 'total' => 0];
				}

				$wanted  = ($filters['originId'] ?? null);
				$wanted  = ($wanted === null || is_array($wanted) === true) ? $wanted : [$wanted];
				$matches = [];
				foreach (($this->store['synchronization_contract'] ?? []) as $uuid => $contract) {
					if ($wanted !== null && in_array(($contract['originId'] ?? null), $wanted, true) === false) {
						continue;
					}

					if (isset($filters['synchronizationId']) === true && ($contract['synchronizationId'] ?? null) !== $filters['synchronizationId']) {
						continue;
					}

					$matches[] = ObjectServiceMockBuilder::objectEntity($this, $contract, (string)$uuid);
				}

				return ['results' => $matches, 'total' => count($matches)];
			}
		);

		return $or;
	}//end or()

	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default='') => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->config[$key] = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	private function engine(ORObjectService $or, string $set='zgw-zaken', string $path='/zaken'): SynchronizationService {
		$calls   = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$callPos = $this->positions(CallService::class, 'call');
		$calls->method('call')->willReturnCallback(
			function (...$args) use ($callPos, $set, $path) {
				$endpoint                  = (string)($args[$callPos['endpoint']] ?? '');
				$this->calls[$endpoint]    = (($this->calls[$endpoint] ?? 0) + 1);
				$page                      = ['count' => 3, 'next' => null, 'previous' => null, 'results' => []];
				if ($this->calls[$endpoint] === 1) {
					$page['results'] = $this->fixture($set, $path);
				}

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					['response' => ['statusCode' => 200, 'body' => json_encode($page), 'encoding' => 'UTF-8', 'headers' => []]],
					'call-' . $endpoint . '-' . $this->calls[$endpoint]
				);
			}
		);

		$sourceMapping = $this->createMock(ObjectService::class);
		$mapping       = new MappingService(
			new ArrayLoader([]),
			$calls,
			$this->createMock(FileService::class),
			$sourceMapping,
			$or,
			$this->createMock(SynchronizationContractService::class),
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(null);

		return new SynchronizationService(
			$calls,
			$mapping,
			$container,
			$or,
			$sourceMapping,
			$this->createMock(LoggerInterface::class),
			new SynchronizationLogService(ObjectServiceMockBuilder::make($this), $this->createMock(\OCP\IUserSession::class), $this->createMock(\OCP\ISession::class)),
			$this->appConfig(),
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end engine()

	/**
	 * Installed against cases/<schema> and run, each data set lands its three resources there, keyed by remote url.
	 *
	 * @param string $set  The set slug.
	 * @param string $path The list endpoint its pull reads.
	 *
	 * @return void
	 *
	 * @dataProvider dataSets
	 */
	public function testEachDataSetPullsThreeFixtureResourcesIntoTheBoundSchema(string $set, string $path): void {
		$or = $this->or();
		$this->installer($or)->install(slug: $set, register: 'cases', schema: 'bound');

		$syncSlug = $set . '-pull';
		$this->assertSame('cases/bound', $this->store['synchronization'][$syncSlug]['targetId']);

		$this->engine($or, $set, $path)->synchronize(synchronization: $this->store['synchronization'][$syncSlug]);

		$urls = array_column($this->fixture($set, $path), 'url');
		$this->assertSame(1, ($this->calls[$path] ?? 0), 'The pull read the set\'s list endpoint once.');
		$this->assertSame($urls, array_values(array_column($this->store['bound'] ?? [], 'url')), 'Three objects carrying the remote urls in the bound schema.');
		$this->assertEqualsCanonicalizing($urls, array_values(array_column($this->store['synchronization_contract'] ?? [], 'originId')));

		// Design D5: the bound schema holds the store's own shape, every field as the store sent it.
		$bound = array_column($this->store['bound'], null, 'url');
		foreach ($this->fixture($set, $path) as $resource) {
			$this->assertSame($resource, array_intersect_key($bound[$resource['url']], $resource), 'The store\'s own fields, untranslated.');
		}

		// Run again, as a caller reads the synchronization back: the url-keyed contracts update the same three.
		$this->engine($or, $set, $path)->synchronize(synchronization: $this->store['synchronization'][$syncSlug] + ['id' => $syncSlug]);
		$this->assertCount(3, $this->store['bound']);
		$this->assertCount(3, $this->store['synchronization_contract']);
	}//end testEachDataSetPullsThreeFixtureResourcesIntoTheBoundSchema()

	private function installer(ORObjectService $or): ZgwSetInstaller {
		return new ZgwSetInstaller(
			objectService: $or,
			appConfig: $this->appConfig(),
			guard: new ZgwSetInstallGuard(CatalogueL10n::make($this)),
			subscriber: $this->createMock(NotificatiesSubscriberService::class),
			l10n: CatalogueL10n::make($this)
		);
	}//end installer()
}//end class
