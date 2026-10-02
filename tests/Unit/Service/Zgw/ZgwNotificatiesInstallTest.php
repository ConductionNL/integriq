<?php

/**
 * Installing zgw-notificaties subscribes the installed data sets, with no data schema of its own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Zgw;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\NotificatiesSubscriberService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\Integriq\Service\Zgw\ZgwSetInstallGuard;
use OCA\Integriq\Service\Zgw\ZgwSetInstallRefusedException;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Integriq\Tests\Helpers\CatalogueL10n;

/**
 * The installer with the real NotificatiesSubscriberService, every save validated against the register.
 */
class ZgwNotificatiesInstallTest extends TestCase {

	private const SOURCE_UUID = '3f1e2d4c-5b6a-4789-9abc-def012345678';

	/**
	 * Seeded objects by schema and slug.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $seeds = [];

	/**
	 * Every saveObject call: schema and payload.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Remote abonnement registrations: endpoint and json body.
	 *
	 * @var list<array{endpoint: string, json: mixed}>
	 */
	private array $remote = [];

	/**
	 * App values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	private int $remoteStatus = 201;

	protected function setUp(): void {
		$path     = dirname(__DIR__, 4) . '/lib/Settings/register.d/zgw-consumer-sets.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$this->seeds[$self['schema']][$self['slug']] = $object;
		}

		$this->seeds['source']['zgw-set-notificaties']['location'] = 'https://open-notificaties.example.nl/api/v1';
	}//end setUp()

	private function installer(): ZgwSetInstaller {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register=null, ?string $schema=null) {
				if ($schema === 'source' && $id === self::SOURCE_UUID) {
					$id = 'zgw-set-notificaties';
				}

				if (isset($this->seeds[$schema][$id]) === false) {
					throw new \RuntimeException('Object not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($schema === 'source' ? self::SOURCE_UUID : 'uuid-' . $id);
				$entity->setObject($this->seeds[$schema][$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null) {
				$this->saved[] = ['schema' => (string)$schema, 'object' => $object];
				$uuid          = ($uuid ?? sprintf('5a0c%04x-0000-4000-8000-%012x', count($this->saved), count($this->saved)));
				$entity        = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				$this->seeds[(string)$schema][$uuid] = $object;
				return $entity;
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint='', string $method='GET', array $config=[]) {
				$this->remote[] = ['endpoint' => $endpoint, 'json' => ($config['json'] ?? null)];
				$log            = new ObjectEntity();
				$log->setObject(
					[
						'statusCode' => $this->remoteStatus,
						'response'   => ['body' => (string)json_encode(['url' => 'https://open-notificaties.example.nl/api/v1/abonnement/' . count($this->remote)])],
					]
				);
				return $log;
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.nl/apps/integriq/api/notificaties/callback/x');
		$logger     = $this->createMock(LoggerInterface::class);
		$subscriber = new NotificatiesSubscriberService(
			$objects,
			$calls,
			$this->createMock(EventService::class),
			new WebhookSignatureService($logger),
			$urls,
			$logger
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default='') => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new ZgwSetInstaller(
			objectService: $objects,
			appConfig: $appConfig,
			guard: new ZgwSetInstallGuard(CatalogueL10n::make($this)),
			subscriber: $subscriber,
			l10n: CatalogueL10n::make($this)
		);
	}//end installer()

	/**
	 * With nothing installed there is nothing to subscribe to, so nothing is registered remotely.
	 *
	 * @return void
	 */
	public function testNotificatiesBeforeAnyDataSetIsRefused(): void {
		try {
			$this->installer()->install(slug: 'zgw-notificaties', register: '', schema: '');
			$this->fail('Installing notificaties with no data set installed must be refused.');
		} catch (ZgwSetInstallRefusedException $e) {
			$this->assertStringContainsString('first', $e->getMessage());
		}

		$this->assertSame([], $this->remote);
		$this->assertSame([], $this->saved);
	}//end testNotificatiesBeforeAnyDataSetIsRefused()

	/**
	 * Notificaties installs with no register and schema, one abonnement per installed set, each a valid register object.
	 *
	 * @return void
	 */
	public function testNotificatiesSubscribesEachInstalledSetWithoutASchema(): void {
		$this->config[ZgwSetInstaller::BINDINGS_KEY] = (string)json_encode(['cases/case' => 'zgw-zaken', 'cases/zaaktype' => 'zgw-catalogi']);

		$result = $this->installer()->install(slug: 'zgw-notificaties', register: '', schema: '');

		$this->assertNull($result['binding']);
		$this->assertSame([], $result['synchronizations']);
		$this->assertSame(['zgw-zaken', 'zgw-catalogi'], array_keys($result['subscriptions']));
		$this->assertCount(2, $this->remote, 'One abonnement per installed component.');
		$this->assertSame('/abonnement', $this->remote[0]['endpoint']);
		$this->assertSame([['naam' => 'zaken', 'filters' => []]], json_decode((string)json_encode($this->remote[0]['json']['kanalen']), true));
		$this->assertSame(
			['zaaktypen', 'informatieobjecttypen', 'besluittypen'],
			array_column((array)json_decode((string)json_encode($this->remote[1]['json']['kanalen']), true), 'naam')
		);
		$this->assertSame('{}', (string)json_encode($this->remote[0]['json']['kanalen'][0]['filters']), 'The Notificaties API takes filters as an object.');

		foreach ($this->saved as $save) {
			$this->assertSame([], RegisterSchemaValidator::errors($save['schema'], $save['object']), $save['schema']);
		}

		$this->assertSame($result['subscriptions'], json_decode($this->config[ZgwSetInstaller::SUBSCRIPTIONS_KEY], true));
		$this->assertSame(
			['cases/case' => 'zgw-zaken', 'cases/zaaktype' => 'zgw-catalogi'],
			$this->installer()->bindings(),
			'Notificaties holds no schema, so it takes no binding.'
		);
	}//end testNotificatiesSubscribesEachInstalledSetWithoutASchema()

	/**
	 * Installing notificaties again after a new data set adds only that set's abonnement.
	 *
	 * @return void
	 */
	public function testReinstallingAddsOnlyTheNewSet(): void {
		$this->config[ZgwSetInstaller::BINDINGS_KEY] = (string)json_encode(['cases/case' => 'zgw-zaken']);
		$this->installer()->install(slug: 'zgw-notificaties', register: '', schema: '');

		$this->config[ZgwSetInstaller::BINDINGS_KEY] = (string)json_encode(['cases/case' => 'zgw-zaken', 'cases/besluit' => 'zgw-besluiten']);
		$result = $this->installer()->install(slug: 'zgw-notificaties', register: '', schema: '');

		$this->assertCount(2, $this->remote);
		$this->assertSame('besluiten', $this->remote[1]['json']['kanalen'][0]['naam']);
		$this->assertSame(['zgw-zaken', 'zgw-besluiten'], array_keys($result['subscriptions']));
	}//end testReinstallingAddsOnlyTheNewSet()

	/**
	 * A registration the store refuses is reported and not recorded, so installing again retries it.
	 *
	 * @return void
	 */
	public function testARefusedRegistrationIsReportedAndRetriedNextTime(): void {
		$this->config[ZgwSetInstaller::BINDINGS_KEY] = (string)json_encode(['cases/case' => 'zgw-zaken']);
		$this->remoteStatus = 403;

		$result = $this->installer()->install(slug: 'zgw-notificaties', register: '', schema: '');

		$this->assertSame([], $result['subscriptions']);
		$this->assertStringContainsString('403', $result['refused']['zgw-zaken']);

		$this->remoteStatus = 201;
		$result = $this->installer()->install(slug: 'zgw-notificaties', register: '', schema: '');
		$this->assertSame(['zgw-zaken'], array_keys($result['subscriptions']));
	}//end testARefusedRegistrationIsReportedAndRetriedNextTime()

	/**
	 * Without its seeded source the set is refused before anything is registered.
	 *
	 * @return void
	 */
	public function testAMissingSourceIsRefusedBeforeAnyRegistration(): void {
		$this->config[ZgwSetInstaller::BINDINGS_KEY] = (string)json_encode(['cases/case' => 'zgw-zaken']);
		unset($this->seeds['source']['zgw-set-notificaties']);

		try {
			$this->installer()->install(slug: 'zgw-notificaties', register: '', schema: '');
			$this->fail('A missing source must refuse the install.');
		} catch (ZgwSetInstallRefusedException $e) {
			$this->assertStringContainsString('"zgw-set-notificaties"', $e->getMessage());
		}

		$this->assertSame([], $this->remote);
		$this->assertSame([], $this->saved);
	}//end testAMissingSourceIsRefusedBeforeAnyRegistration()

	/**
	 * A data set still needs its register and schema: the guard stays strict for sets that carry data.
	 *
	 * @return void
	 */
	public function testADataSetStillNeedsATarget(): void {
		$this->expectException(ZgwSetInstallRefusedException::class);
		$this->expectExceptionMessage('register and a schema');

		$this->installer()->install(slug: 'zgw-zaken', register: '', schema: '');
	}//end testADataSetStillNeedsATarget()
}//end class
