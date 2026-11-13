<?php

/**
 * Unit tests: the destinations list, its verdict, EU-only mode and an administrator's residency.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Residency
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Residency;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\Service\ConnectionStoreInterface;
use OCA\Integriq\Service\Residency\EgressDestinationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The destinations page's data.
 */
class EgressDestinationServiceTest extends TestCase {

	/**
	 * Stored sources: uuid => data.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $sources = [];

	/**
	 * Stored call logs, newest first.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $callLogs = [];

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The connection store double.
	 *
	 * @var ConnectionStoreInterface&MockObject
	 */
	private ConnectionStoreInterface $store;

	/**
	 * The service over the stored sources and call logs.
	 *
	 * @return EgressDestinationService
	 */
	private function service(): EgressDestinationService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$schema = $config['filters']['schema'] ?? '';
				$entities = [];
				if ($schema === 'source') {
					foreach ($this->sources as $uuid => $data) {
						$entities[] = $this->entity(uuid: (string)$uuid, data: $data);
					}
				}

				if ($schema === 'call_log') {
					$page = array_slice($this->callLogs, (int)($config['offset'] ?? 0), (int)$config['limit']);
					foreach ($page as $index => $data) {
						$entities[] = $this->entity(uuid: 'log-' . $index, data: $data);
					}
				}

				return ['results' => $entities];
			}
		);

		$this->appConfig ??= $this->createMock(IAppConfig::class);
		$this->store ??= $this->createMock(ConnectionStoreInterface::class);

		return new EgressDestinationService(objectService: $objectService, store: $this->store, appConfig: $this->appConfig);
	}//end service()

	/**
	 * An object entity.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Store an enabled source with a residency country, or none.
	 *
	 * @param string      $uuid    The uuid.
	 * @param string      $host    The host of its location.
	 * @param string|null $country The residency country.
	 *
	 * @return void
	 */
	private function addSource(string $uuid, string $host, ?string $country): void {
		$data = ['name' => 'Source ' . $uuid, 'isEnabled' => true, 'location' => 'https://' . $host . '/api'];
		if ($country !== null) {
			$data['residency'] = ['country' => $country, 'basis' => 'set-by-admin', 'evidence' => 'contract'];
		}

		$this->sources[$uuid] = $data;
	}//end addSource()

	/**
	 * Sources in NL, DE and FR and no other host give EU only.
	 *
	 * @return void
	 */
	public function testAllEuGivesEuOnly(): void {
		$this->addSource(uuid: 's1', host: 'nl.example.org', country: 'NL');
		$this->addSource(uuid: 's2', host: 'de.example.org', country: 'DE');
		$this->addSource(uuid: 's3', host: 'fr.example.org', country: 'FR');
		$this->callLogs = [['created' => (new DateTimeImmutable('-1 day'))->format('c'), 'request' => ['url' => 'https://nl.example.org/api/x']]];

		$list = $this->service()->list();

		$this->assertSame(expected: 'eu-only', actual: $list['verdict']);
		$this->assertSame(expected: ['eu' => 3, 'eea' => 0, 'outside' => 0, 'unknown' => 0], actual: $list['counts']);
		$this->assertSame(
			expected: [
				'host' => 'nl.example.org',
				'name' => 'Source s1',
				'sourceId' => 's1',
				'country' => 'NL',
				'basis' => 'set-by-admin',
				'evidence' => 'contract',
				'zone' => 'eu',
				'lastCallAt' => null,
			],
			actual: $list['destinations'][0]
		);
		$this->assertFalse($list['euOnly']);
	}//end testAllEuGivesEuOnly()

	/**
	 * A source without residency makes the verdict not proven and is counted unknown.
	 *
	 * @return void
	 */
	public function testAnUnknownDestinationBlocksTheVerdict(): void {
		$this->addSource(uuid: 's1', host: 'a.example.org', country: 'NL');
		$this->addSource(uuid: 's2', host: 'b.example.org', country: 'NL');
		$this->addSource(uuid: 's3', host: 'c.example.org', country: null);

		$list = $this->service()->list();

		$this->assertSame(expected: 'not-proven', actual: $list['verdict']);
		$this->assertSame(expected: 1, actual: $list['counts']['unknown']);
		$this->assertSame(expected: 'Source s3', actual: $list['destinations'][2]['name']);
		$this->assertNull($list['destinations'][2]['country']);
	}//end testAnUnknownDestinationBlocksTheVerdict()

	/**
	 * A call log host no source covers is an undeclared destination with zone unknown.
	 *
	 * @return void
	 */
	public function testACallLogHostWithoutASourceIsListed(): void {
		$this->addSource(uuid: 's1', host: 'nl.example.org', country: 'NL');
		$recent = (new DateTimeImmutable('-2 days'))->format('c');
		$this->callLogs = [
			['created' => $recent, 'target' => 'https://API.example.com/hook'],
			['created' => (new DateTimeImmutable('-3 days'))->format('c'), 'request' => ['url' => 'https://api.example.com/other']],
			['created' => (new DateTimeImmutable('-40 days'))->format('c'), 'target' => 'https://old.example.net/x'],
		];

		$list = $this->service()->list();

		$this->assertCount(expectedCount: 2, haystack: $list['destinations']);
		$this->assertSame(
			expected: [
				'host' => 'api.example.com',
				'name' => '',
				'sourceId' => null,
				'country' => null,
				'basis' => '',
				'evidence' => '',
				'zone' => 'unknown',
				'lastCallAt' => $recent,
			],
			actual: $list['destinations'][1]
		);
		$this->assertSame(expected: 'not-proven', actual: $list['verdict']);
	}//end testACallLogHostWithoutASourceIsListed()

	/**
	 * Norway is EEA, which is not EU.
	 *
	 * @return void
	 */
	public function testAnEeaCountryIsNotCountedAsEu(): void {
		$this->addSource(uuid: 's1', host: 'no.example.org', country: 'NO');

		$list = $this->service()->list();

		$this->assertSame(expected: 'eea', actual: $list['destinations'][0]['zone']);
		$this->assertSame(expected: 'not-proven', actual: $list['verdict']);
	}//end testAnEeaCountryIsNotCountedAsEu()

	/**
	 * A disabled source is not a destination.
	 *
	 * @return void
	 */
	public function testADisabledSourceIsNotListed(): void {
		$this->addSource(uuid: 's1', host: 'nl.example.org', country: 'NL');
		$this->sources['s2'] = ['name' => 'Off', 'isEnabled' => false, 'location' => 'https://us.example.org'];

		$this->assertCount(expectedCount: 1, haystack: $this->service()->list()['destinations']);
	}//end testADisabledSourceIsNotListed()

	/**
	 * Switching EU-only mode on over a gap is refused and names the gap; nothing is stored.
	 *
	 * @return void
	 */
	public function testEuOnlyCannotBeSwitchedOnOverAGap(): void {
		$this->addSource(uuid: 's1', host: 'nl.example.org', country: 'NL');
		$this->addSource(uuid: 's2', host: 'x.example.org', country: null);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->expects($this->never())->method('setValueBool');

		$result = $this->service()->setEuOnly(enabled: true);

		$this->assertFalse($result['euOnly']);
		$this->assertSame(expected: ['Source s2'], actual: array_column($result['blocking'], 'name'));
	}//end testEuOnlyCannotBeSwitchedOnOverAGap()

	/**
	 * Switching on with every destination in the EU, and switching off, are stored.
	 *
	 * @return void
	 */
	public function testEuOnlyIsStoredWhenProvenAndOffAlways(): void {
		$this->addSource(uuid: 's1', host: 'nl.example.org', country: 'NL');
		$this->appConfig = $this->createMock(IAppConfig::class);
		$stored = [];
		$this->appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value) use (&$stored): bool {
				$stored[] = [$app, $key, $value];
				return true;
			}
		);
		$service = $this->service();

		$this->assertSame(expected: ['euOnly' => true, 'blocking' => []], actual: $service->setEuOnly(enabled: true));
		$this->assertSame(expected: ['euOnly' => false, 'blocking' => []], actual: $service->setEuOnly(enabled: false));
		$this->assertSame(expected: [['integriq', 'egress_eu_only', true], ['integriq', 'egress_eu_only', false]], actual: $stored);
	}//end testEuOnlyIsStoredWhenProvenAndOffAlways()

	/**
	 * An administrator's residency is stored with basis set-by-admin and her user id.
	 *
	 * @return void
	 */
	public function testAnAdminSetsResidency(): void {
		$source = $this->entity(uuid: 's1', data: ['name' => 'zgw-zaken']);
		$this->store = $this->createMock(ConnectionStoreInterface::class);
		$this->store->method('findSourceRaw')->with('s1')->willReturn($source);
		$saved = null;
		$this->store->expects($this->once())->method('saveSourceResidency')->willReturnCallback(
			function (ObjectEntity $entity, array $residency) use (&$saved, $source): void {
				$this->assertSame(expected: $source, actual: $entity);
				$saved = $residency;
			}
		);

		$residency = $this->service()->setSourceResidency(sourceId: 's1', country: 'NL', evidence: 'verwerkersovereenkomst 2026-03, art. 4', userId: 'alice');

		$this->assertSame(expected: $saved, actual: $residency);
		$this->assertSame(expected: 'NL', actual: $residency['country']);
		$this->assertSame(expected: 'set-by-admin', actual: $residency['basis']);
		$this->assertSame(expected: 'verwerkersovereenkomst 2026-03, art. 4', actual: $residency['evidence']);
		$this->assertSame(expected: 'alice', actual: $residency['recordedBy']);
		$this->assertNotFalse(strtotime($residency['recordedAt']));
	}//end testAnAdminSetsResidency()

	/**
	 * An invalid country is refused, and a missing source answers null.
	 *
	 * @return void
	 */
	public function testAnInvalidCountryOrMissingSourceIsNotStored(): void {
		$this->store = $this->createMock(ConnectionStoreInterface::class);
		$this->store->method('findSourceRaw')->willReturn(null);
		$this->store->expects($this->never())->method('saveSourceResidency');
		$service = $this->service();

		$this->assertNull($service->setSourceResidency(sourceId: 'gone', country: 'NL', evidence: '', userId: 'alice'));

		$this->expectException(InvalidArgumentException::class);
		$service->setSourceResidency(sourceId: 's1', country: 'XX', evidence: '', userId: 'alice');
	}//end testAnInvalidCountryOrMissingSourceIsNotStored()
}//end class
