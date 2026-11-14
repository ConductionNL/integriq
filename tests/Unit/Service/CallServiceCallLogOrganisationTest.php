<?php

/**
 * Unit tests: a call log is filed under the organisation of the source it was made against.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * The logs pages read call logs with OpenRegister's organisation filter on, so
 * a log filed under another organisation than its source's is not listed for
 * the source's organisation.
 *
 * @covers \OCA\Integriq\Service\CallService
 * @uses \OCA\Integriq\Outbound\Call\BodyCapturePolicy
 * @uses \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class CallServiceCallLogOrganisationTest extends TestCase {

	/**
	 * The source's organisation.
	 *
	 * @var string
	 */
	private const ORG = '0a0a0a0a-0000-4000-8000-00000000000a';

	/**
	 * Single saves handed to ObjectService::saveObject().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Bulk saves handed to ObjectService::saveObjects().
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private array $batches = [];

	/**
	 * A CallService over a recording ObjectService and a Guzzle double.
	 *
	 * @return CallService
	 */
	private function service(): CallService {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null) {
				$this->saved[] = ['object' => $object, 'schema' => $schema];
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->saved));
				$entity->setObject($object);
				return $entity;
			}
		);
		$objectService->method('saveObjects')->willReturnCallback(
			function (array $objects = []) {
				$this->batches[] = $objects;
				return [];
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$clock = $this->createMock(originalClassName: ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-13T12:00:00+00:00'));

		$brokered = $this->createMock(originalClassName: BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$service = new CallService(
			objectService: $objectService,
			loader: new ArrayLoader([]),
			authenticationService: $this->createMock(originalClassName: AuthenticationService::class),
			appConfig: $appConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			brokeredCallService: $brokered,
			sensitiveFieldRegistry: new SensitiveFieldRegistry(),
			bodyCapturePolicy: new BodyCapturePolicy(
				appConfig: $appConfig,
				clock: $clock,
				userSession: $this->createMock(originalClassName: IUserSession::class)
			),
		);

		$client = $this->createMock(originalClassName: Client::class);
		$client->method('request')->willReturn(new Response(200, [], '{}'));
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * A source, enabled or not, of the given organisation.
	 *
	 * @param string|null $organisation The owning organisation, or null for none.
	 * @param boolean     $enabled      Whether the source is enabled.
	 *
	 * @return ObjectEntity
	 */
	private function source(?string $organisation, bool $enabled = true): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f');
		$source->setObject(['name' => 'brp', 'isEnabled' => $enabled, 'location' => 'https://brp.example.invalid/api']);
		if ($organisation !== null) {
			$source->setOrganisation($organisation);
		}

		return $source;
	}//end source()

	/**
	 * The call_log records written singly.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function callLogs(): array {
		return array_values(
			array_map(
				static fn (array $row): array => $row['object'],
				array_filter($this->saved, static fn (array $row): bool => $row['schema'] === 'call_log')
			)
		);
	}//end callLogs()

	/**
	 * An answered call's log names the source's organisation.
	 *
	 * @return void
	 */
	public function testAnAnsweredCallIsFiledUnderTheSourcesOrganisation(): void {
		$this->service()->call(source: $this->source(organisation: self::ORG), endpoint: '/personen');

		$this->assertSame(expected: ['organisation' => self::ORG], actual: $this->callLogs()[0]['@self']);
	}//end testAnAnsweredCallIsFiledUnderTheSourcesOrganisation()

	/**
	 * A buffered log names it too.
	 *
	 * @return void
	 */
	public function testABufferedLogIsFiledUnderTheSourcesOrganisation(): void {
		$service = $this->service();
		$service->bufferCallLogs(true);

		$service->call(source: $this->source(organisation: self::ORG), endpoint: '/personen');
		$service->flushCallLogs();

		$this->assertSame(expected: ['organisation' => self::ORG], actual: $this->batches[0][0]['@self']);
	}//end testABufferedLogIsFiledUnderTheSourcesOrganisation()

	/**
	 * The log of a call refused before it was made, here a disabled source, names it too.
	 *
	 * @return void
	 */
	public function testAnEarlyRefusalIsFiledUnderTheSourcesOrganisation(): void {
		$this->service()->call(source: $this->source(organisation: self::ORG, enabled: false), endpoint: '/personen');

		$this->assertSame(expected: 409, actual: $this->callLogs()[0]['statusCode']);
		$this->assertSame(expected: ['organisation' => self::ORG], actual: $this->callLogs()[0]['@self']);
	}//end testAnEarlyRefusalIsFiledUnderTheSourcesOrganisation()

	/**
	 * A source without an organisation leaves the choice to OpenRegister.
	 *
	 * @return void
	 */
	public function testASourceWithoutAnOrganisationNamesNone(): void {
		$this->service()->call(source: $this->source(organisation: null), endpoint: '/personen');

		$this->assertArrayNotHasKey(key: '@self', array: $this->callLogs()[0]);
	}//end testASourceWithoutAnOrganisationNamesNone()

}//end class
