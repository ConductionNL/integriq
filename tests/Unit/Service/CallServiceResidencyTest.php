<?php

/**
 * Unit tests: EU-only mode refuses a call to a destination outside the EU before any request is sent.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
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
use OCA\Integriq\Service\Residency\EgressResidencyPolicy;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Twig\Loader\ArrayLoader;

/**
 * The EU-only check in CallService, through call().
 */
class CallServiceResidencyTest extends TestCase {

	/**
	 * Objects saved through the object service: [object, schema].
	 *
	 * @var array<int,array{object:array<string,mixed>,schema:string|null}>
	 */
	private array $saved = [];

	/**
	 * The HTTP client double.
	 *
	 * @var Client&MockObject
	 */
	private Client $client;

	/**
	 * A CallService whose EU-only setting reads as given, or throws when null.
	 *
	 * @param bool|null $euOnly The setting; null makes the read throw.
	 *
	 * @return CallService
	 */
	private function service(?bool $euOnly): CallService {
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

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueBool')->willReturnCallback(
			static function (string $app, string $key, bool $default = false) use ($euOnly): bool {
				if ($app === 'integriq' && $key === EgressResidencyPolicy::SETTING) {
					if ($euOnly === null) {
						throw new RuntimeException('config table unreachable');
					}

					return $euOnly;
				}

				return $default;
			}
		);

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

		$this->client = $this->createMock(originalClassName: Client::class);
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setValue($service, $this->client);

		return $service;
	}//end service()

	/**
	 * An enabled source, with a residency when a country is given.
	 *
	 * @param string|null $country The residency country.
	 *
	 * @return ObjectEntity
	 */
	private function source(?string $country): ObjectEntity {
		$data = ['name' => 'zaken', 'isEnabled' => true, 'location' => 'https://zaken.example.invalid/api'];
		if ($country !== null) {
			$data['residency'] = ['country' => $country, 'basis' => 'set-by-admin'];
		}

		$source = new ObjectEntity();
		$source->setUuid('7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f');
		$source->setObject($data);

		return $source;
	}//end source()

	/**
	 * The call logs written, oldest first.
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
	 * With the mode on, a source without residency is refused and no request leaves.
	 *
	 * @return void
	 */
	public function testAnUnknownDestinationIsRefusedBeforeSending(): void {
		$service = $this->service(euOnly: true);
		$this->client->expects($this->never())->method('request');

		$service->call(source: $this->source(country: null), endpoint: '/zaken');

		$this->assertSame(expected: 451, actual: $this->callLogs()[0]['statusCode']);
		$this->assertSame(expected: 'refused-residency: unknown', actual: $this->callLogs()[0]['statusMessage']);
	}//end testAnUnknownDestinationIsRefusedBeforeSending()

	/**
	 * With the mode on, a source outside the EU is refused under its own country.
	 *
	 * @return void
	 */
	public function testANonEuDestinationIsRefusedUnderItsCountry(): void {
		$service = $this->service(euOnly: true);
		$this->client->expects($this->never())->method('request');

		$service->call(source: $this->source(country: 'US'), endpoint: '/zaken');

		$this->assertSame(expected: 'refused-residency: US', actual: $this->callLogs()[0]['statusMessage']);
	}//end testANonEuDestinationIsRefusedUnderItsCountry()

	/**
	 * With the mode on, a source in the EU is called.
	 *
	 * @return void
	 */
	public function testAnEuDestinationIsCalled(): void {
		$service = $this->service(euOnly: true);
		$this->client->expects($this->once())->method('request')->willReturn(new Response(200, [], '{}'));

		$service->call(source: $this->source(country: 'NL'), endpoint: '/zaken');

		$this->assertSame(expected: 200, actual: $this->callLogs()[0]['statusCode']);
	}//end testAnEuDestinationIsCalled()

	/**
	 * A settings read that fails refuses a source without residency.
	 *
	 * @return void
	 */
	public function testABrokenSettingsReadRefuses(): void {
		$service = $this->service(euOnly: null);
		$this->client->expects($this->never())->method('request');

		$service->call(source: $this->source(country: null), endpoint: '/zaken');

		$this->assertSame(expected: 'refused-residency: unknown', actual: $this->callLogs()[0]['statusMessage']);
	}//end testABrokenSettingsReadRefuses()

	/**
	 * With the mode off, a source without residency is called as today.
	 *
	 * @return void
	 */
	public function testModeOffChangesNothing(): void {
		$service = $this->service(euOnly: false);
		$this->client->expects($this->once())->method('request')->willReturn(new Response(200, [], '{}'));

		$service->call(source: $this->source(country: null), endpoint: '/zaken');

		$this->assertSame(expected: 200, actual: $this->callLogs()[0]['statusCode']);
	}//end testModeOffChangesNothing()
}//end class
