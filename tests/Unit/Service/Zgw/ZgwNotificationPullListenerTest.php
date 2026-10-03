<?php

/**
 * An inbound notification pulls the one main object it names, through the installed set.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Zgw;

use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\Zgw\ZgwNotificationPullListener;
use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener against the real seeded pull synchronizations and sources.
 */
class ZgwNotificationPullListenerTest extends TestCase {

	private const STORE = 'https://open-zaak.example.nl/zaken/api/v1';

	/**
	 * Seeded objects by schema and slug.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $seeds = [];

	/**
	 * The bindings app value, as the installer writes it.
	 *
	 * @var array<string, string>
	 */
	private array $bindings = ['cases/case' => 'zgw-zaken'];

	/**
	 * What the synchronization engine was asked to do.
	 *
	 * @var list<array{0: string, 1: mixed, 2: mixed}>
	 */
	private array $calls = [];

	private ?\Throwable $fetchFails = null;

	protected function setUp(): void {
		$path     = dirname(__DIR__, 4) . '/lib/Settings/register.d/zgw-consumer-sets.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$this->seeds[$self['schema']][$self['slug']] = $object;
		}

		// An operator set the address of the store before installing the set.
		$this->seeds['source']['zgw-set-zaken']['location'] = self::STORE;
	}//end setUp()

	/**
	 * A recorded Open Notificaties delivery: the status of one zaak changed.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function statusNotification(array $override=[]): array {
		return array_merge(
			[
				'kanaal'       => 'zaken',
				'hoofdObject'  => self::STORE . '/zaken/d4d5d0d6-2f3c-4f0b-9b5c-1d3a1c6e7f80',
				'resource'     => 'status',
				'resourceUrl'  => self::STORE . '/statussen/0b6d6a4c-8f8e-4e2b-a1e3-5c2f0a9b7d11',
				'actie'        => 'create',
				'aanmaakdatum' => '2026-10-02T09:12:44.123456Z',
				'kenmerken'    => [
					'bronorganisatie'          => '002220647',
					'zaaktype'                 => self::STORE . '/../catalogi/api/v1/zaaktypen/7f2c',
					'vertrouwelijkheidaanduiding' => 'openbaar',
				],
			],
			$override
		);
	}//end statusNotification()

	private function listener(): ZgwNotificationPullListener {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register=null, ?string $schema=null) {
				if ($register !== 'integriq' || isset($this->seeds[$schema][$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('uuid-' . $id);
				$entity->setObject($this->seeds[$schema][$id]);
				return $entity;
			}
		);

		$engine = $this->createMock(SynchronizationService::class);
		$engine->method('getObjectFromSource')->willReturnCallback(
			function (array $synchronization, string $endpoint) {
				$this->calls[] = ['fetch', $synchronization, $endpoint];
				if ($this->fetchFails !== null) {
					throw $this->fetchFails;
				}

				return ['url' => $endpoint, 'status' => self::STORE . '/statussen/0b6d6a4c-8f8e-4e2b-a1e3-5c2f0a9b7d11'];
			}
		);
		$engine->method('replaySynchronizationItem')->willReturnCallback(
			function (array $synchronization, array $payload) {
				$this->calls[] = ['item', $synchronization, $payload];
				return ['result' => [], 'targetId' => 'local-1'];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default='') => match ($key) {
				ZgwSetInstaller::BINDINGS_KEY => (string)json_encode($this->bindings),
				default => $default,
			}
		);

		return new ZgwNotificationPullListener($objects, $engine, $appConfig, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * A status change pulls the zaak it belongs to, once, through the zaken pull synchronization.
	 *
	 * @return void
	 */
	public function testAStatusChangePullsOnlyItsZaak(): void {
		$zaak = self::STORE . '/zaken/d4d5d0d6-2f3c-4f0b-9b5c-1d3a1c6e7f80';

		$this->assertSame($zaak, $this->listener()->handle($this->statusNotification()));

		$this->assertCount(2, $this->calls, 'One read of one resource, one item run: no list fetch.');
		[$fetch, $item] = $this->calls;
		$this->assertSame('fetch', $fetch[0]);
		$this->assertSame('zgw-zaken-pull', $fetch[1]['slug']);
		$this->assertSame($zaak, $fetch[2]);
		$this->assertSame('item', $item[0]);
		$this->assertSame('zgw-zaken-pull', $item[1]['slug']);
		$this->assertSame($zaak, $item[2]['url'], 'The item carries the remote url the contract is keyed by.');
	}//end testAStatusChangePullsOnlyItsZaak()

	/**
	 * A component nobody installed pulls nothing.
	 *
	 * @return void
	 */
	public function testANotificationForAnUninstalledSetPullsNothing(): void {
		$this->bindings = ['cases/document' => 'zgw-documenten'];

		$this->assertNull($this->listener()->handle($this->statusNotification()));
		$this->assertSame([], $this->calls);
	}//end testANotificationForAnUninstalledSetPullsNothing()

	/**
	 * A url on another host is refused before any call: the pull carries the set's credentials.
	 *
	 * @return void
	 */
	public function testAUrlOffTheSetsStoreIsRefusedBeforeAnyCall(): void {
		$notification = $this->statusNotification(['hoofdObject' => 'https://attacker.example/zaken/api/v1/zaken/1']);

		$this->assertNull($this->listener()->handle($notification));
		$this->assertSame([], $this->calls);
	}//end testAUrlOffTheSetsStoreIsRefusedBeforeAnyCall()

	/**
	 * A host that merely starts with the store's address is another host.
	 *
	 * @return void
	 */
	public function testALookalikeHostIsAnotherHost(): void {
		$notification = $this->statusNotification(['hoofdObject' => self::STORE . '.attacker.example/zaken/1']);

		$this->assertNull($this->listener()->handle($notification));
		$this->assertSame([], $this->calls);
	}//end testALookalikeHostIsAnotherHost()

	/**
	 * A destroyed main object has nothing to read; the scheduled sync removes it.
	 *
	 * @return void
	 */
	public function testADestroyedZaakIsNotPulled(): void {
		$zaak         = self::STORE . '/zaken/d4d5d0d6-2f3c-4f0b-9b5c-1d3a1c6e7f80';
		$notification = $this->statusNotification(['resource' => 'zaak', 'resourceUrl' => $zaak, 'actie' => 'destroy']);

		$this->assertNull($this->listener()->handle($notification));
		$this->assertSame([], $this->calls);
	}//end testADestroyedZaakIsNotPulled()

	/**
	 * A destroyed status still changes its zaak, so the zaak is pulled.
	 *
	 * @return void
	 */
	public function testADestroyedStatusStillPullsItsZaak(): void {
		$this->assertNotNull($this->listener()->handle($this->statusNotification(['actie' => 'destroy'])));
		$this->assertCount(2, $this->calls);
	}//end testADestroyedStatusStillPullsItsZaak()

	/**
	 * A catalogue kanaal routes to the catalogi set.
	 *
	 * @return void
	 */
	public function testAZaaktypeNotificationRoutesToTheCatalogiSet(): void {
		$catalogi = 'https://open-zaak.example.nl/catalogi/api/v1';
		$this->seeds['source']['zgw-set-catalogi']['location'] = $catalogi;
		$this->bindings = ['cases/zaaktype' => 'zgw-catalogi'];

		$url = $catalogi . '/zaaktypen/7f2c';
		$this->assertSame(
			$url,
			$this->listener()->handle(['kanaal' => 'zaaktypen', 'hoofdObject' => $url, 'resource' => 'zaaktype', 'resourceUrl' => $url, 'actie' => 'update'])
		);
		$this->assertSame('zgw-catalogi-pull', $this->calls[0][1]['slug']);
	}//end testAZaaktypeNotificationRoutesToTheCatalogiSet()

	/**
	 * An unknown kanaal pulls nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownKanaalPullsNothing(): void {
		$this->assertNull($this->listener()->handle($this->statusNotification(['kanaal' => 'klantinteracties'])));
		$this->assertSame([], $this->calls);
	}//end testAnUnknownKanaalPullsNothing()

	/**
	 * A failing pull never fails the callback: it answers null and the scheduled sync catches up.
	 *
	 * @return void
	 */
	public function testAFailingPullDoesNotThrow(): void {
		$this->fetchFails = new \RuntimeException('Connection refused');

		$this->assertNull($this->listener()->handle($this->statusNotification()));
		$this->assertCount(1, $this->calls, 'Nothing is written after a failed read.');
	}//end testAFailingPullDoesNotThrow()
}//end class
