<?php

/**
 * Unit tests for FscCallService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/fsc-connectivity/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\FscConnectivityException;
use OCA\OpenConnector\Exception\FscDirectoryException;
use OCA\OpenConnector\Service\Fsc\FscDirectoryClient;
use OCA\OpenConnector\Service\Fsc\LogFscConnectivityProvider;
use OCA\OpenConnector\Service\FscCallService;
use OCA\OpenConnector\Service\Security\RawSourceResolver;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FSC directory-resolve-then-call routing (provider selection,
 * directory cache upsert, per-call persistence, per-call isolation, not-configured behaviour).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md
 */
class FscCallServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var LogFscConnectivityProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logProvider;

	/**
	 * @var FscDirectoryClient|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $restProvider;

	/**
	 * @var FscCallService
	 */
	private FscCallService $service;

	/**
	 * Every saveObject invocation captured as [schema => list of {object, register, uuid}].
	 *
	 * @var array<string, array<int, array{object: array, register: string|null, uuid: string|null}>>
	 */
	private array $saved = [];

	/**
	 * Pre-seeded openconnector `source` rows returned for schema=source lookups.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $sources = [];

	/**
	 * Pre-seeded `fsc_service` cache rows, keyed by "organisation:service".
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $cachedServices = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->getMock();
		$this->logProvider = $this->createMock(LogFscConnectivityProvider::class);
		$this->restProvider = $this->createMock(FscDirectoryClient::class);

		$this->saved = [];
		$this->sources = [];
		$this->cachedServices = [];

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? null);

				if ($schema === FscCallService::SCHEMA_SOURCE) {
					return ['results' => $this->sources];
				}

				if ($schema === FscCallService::SCHEMA_SERVICE) {
					$organisation = ($filters['organisation'] ?? null);
					$service = ($filters['service'] ?? null);
					if ($organisation !== null && $service !== null) {
						$key = $organisation . ':' . $service;
						$entry = ($this->cachedServices[$key] ?? null);
						return ['results' => ($entry === null) ? [] : [$entry]];
					}

					return ['results' => array_values($this->cachedServices)];
				}

				return ['results' => []];
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null): ObjectEntity {
				$key = (string)$schema;
				$this->saved[$key][] = ['object' => $object, 'register' => $register, 'uuid' => $uuid];
				$entity = $this->entity($object, ($uuid ?? 'saved-uuid-' . count($this->saved[$key])));

				if ($schema === FscCallService::SCHEMA_SERVICE) {
					$cacheKey = $object['organisation'] . ':' . $object['service'];
					$this->cachedServices[$cacheKey] = $entity;
				}

				return $entity;
			}
		);

		$this->service = new FscCallService(
			$this->objectService,
			$this->logProvider,
			$this->restProvider,
			new RawSourceResolver($this->objectService, $this->createMock(LoggerInterface::class))
		);

	}//end setUp()

	/**
	 * Build a real ObjectEntity for a data payload (magic getters need the real Entity path).
	 *
	 * @param array $data The object data.
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid = 'uuid-1'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity($this, $data, $uuid);
	}//end entity()

	/**
	 * An FSC source entity (type fsc, log provider by default).
	 *
	 * @param array $configuration Extra configuration merged over the default.
	 * @param string $uuid Entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $configuration = [], string $uuid = 'source-1'): ObjectEntity {
		return $this->entity(
			[
				'type' => 'fsc',
				'isEnabled' => true,
				'configuration' => array_merge(['provider' => 'log'], $configuration),
			],
			$uuid
		);

	}//end sourceEntity()

	/**
	 * A basic resolution shared across provider mocks.
	 *
	 * @param array $overrides Extra fields merged over the default.
	 *
	 * @return array
	 */
	private function resolution(array $overrides = []): array {
		return array_merge(
			[
				'organisation' => 'org-a',
				'service' => 'svc-a',
				'endpoint' => 'https://outway.example.nl/svc-a',
				'grantRequired' => false,
				'authContext' => [],
			],
			$overrides
		);

	}//end resolution()

	/**
	 * resolveProvider() defaults to the log/sandbox binding.
	 *
	 * @return void
	 */
	public function testResolveProviderDefaultsToLog(): void {
		$this->assertSame($this->logProvider, $this->service->resolveProvider([]));

	}//end testResolveProviderDefaultsToLog()

	/**
	 * resolveProvider() selects the REST binding when configured.
	 *
	 * @return void
	 */
	public function testResolveProviderSelectsRest(): void {
		$this->assertSame($this->restProvider, $this->service->resolveProvider(['provider' => 'rest']));

	}//end testResolveProviderSelectsRest()

	/**
	 * resolveActiveSource() throws when no active source is configured.
	 *
	 * @return void
	 */
	public function testResolveActiveSourceThrowsWhenNoneConfigured(): void {
		$this->expectException(FscConnectivityException::class);
		$this->service->resolveActiveSource();

	}//end testResolveActiveSourceThrowsWhenNoneConfigured()

	/**
	 * callService() with no active source throws before any resolution is attempted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-no-active-source-produces-a-clean-not-configured-failure
	 */
	public function testCallServiceThrowsWhenNoSourceConfigured(): void {
		$this->expectException(FscConnectivityException::class);
		$this->service->callService(['organisation' => 'org-a', 'service' => 'svc-a']);

	}//end testCallServiceThrowsWhenNoSourceConfigured()

	/**
	 * A successful call (log provider) persists a sent record and caches the resolution.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-a-successful-call-persists-a-sent-record-and-caches-the-resolution
	 */
	public function testCallServicePersistsSentRecordAndCachesResolutionOnSuccess(): void {
		$this->sources[] = $this->sourceEntity();
		$this->logProvider->method('resolveService')->willReturn($this->resolution());
		$this->logProvider->method('call')->willReturn(['ref' => 'FSC-MOCK-1', 'statusCode' => 200, 'body' => []]);

		$result = $this->service->callService(['organisation' => 'org-a', 'service' => 'svc-a']);

		$this->assertSame('FSC-MOCK-1', $result['ref']);

		$this->assertCount(1, $this->saved[FscCallService::SCHEMA_CALL]);
		$savedCall = $this->saved[FscCallService::SCHEMA_CALL][0]['object'];
		$this->assertSame('sent', $savedCall['status']);
		$this->assertSame('org-a', $savedCall['organisation']);
		$this->assertSame('FSC-MOCK-1', $savedCall['ref']);

		$this->assertCount(1, $this->saved[FscCallService::SCHEMA_SERVICE]);
		$savedService = $this->saved[FscCallService::SCHEMA_SERVICE][0]['object'];
		$this->assertSame('org-a', $savedService['organisation']);
		$this->assertSame('svc-a', $savedService['service']);

	}//end testCallServicePersistsSentRecordAndCachesResolutionOnSuccess()

	/**
	 * Resolving the same organisation+service twice updates the same cache row, not a new one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-repeated-resolution-of-the-same-organisationservice-updates-one-cache-row
	 */
	public function testRepeatedResolutionUpsertsSameCacheRow(): void {
		$this->sources[] = $this->sourceEntity();
		$this->logProvider->method('resolveService')->willReturn($this->resolution());
		$this->logProvider->method('call')->willReturn(['ref' => 'FSC-MOCK-1', 'statusCode' => 200, 'body' => []]);

		$this->service->callService(['organisation' => 'org-a', 'service' => 'svc-a']);
		$this->service->callService(['organisation' => 'org-a', 'service' => 'svc-a']);

		$this->assertCount(2, $this->saved[FscCallService::SCHEMA_SERVICE]);
		// The first save() creates (no existing row yet, uuid=null); the second save() targets
		// the uuid the first save produced — an upsert, not a duplicate row.
		$firstUuidParam = $this->saved[FscCallService::SCHEMA_SERVICE][0]['uuid'];
		$secondUuidParam = $this->saved[FscCallService::SCHEMA_SERVICE][1]['uuid'];
		$firstEntityUuid = $this->cachedServices['org-a:svc-a']->getUuid();

		$this->assertNull($firstUuidParam);
		$this->assertNotNull($secondUuidParam);
		$this->assertSame($firstEntityUuid, $secondUuidParam);

	}//end testRepeatedResolutionUpsertsSameCacheRow()

	/**
	 * A failed transport call persists a failed record and rethrows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-a-failed-transport-call-persists-a-failed-record-and-rethrows
	 */
	public function testCallServicePersistsFailedRecordAndRethrows(): void {
		$this->sources[] = $this->sourceEntity(['provider' => 'rest']);
		$this->restProvider->method('resolveService')->willReturn($this->resolution());
		$this->restProvider->method('call')->willThrowException(
			new FscConnectivityException('transport unreachable')
		);

		try {
			$this->service->callService(['organisation' => 'org-a', 'service' => 'svc-a']);
			$this->fail('Expected FscConnectivityException was not thrown.');
		} catch (FscConnectivityException $exception) {
			$this->assertSame('transport unreachable', $exception->getMessage());
		}

		$saved = $this->saved[FscCallService::SCHEMA_CALL][0]['object'];
		$this->assertSame('failed', $saved['status']);
		$this->assertSame('transport unreachable', $saved['error']);

	}//end testCallServicePersistsFailedRecordAndRethrows()

	/**
	 * An unresolvable organisation/service propagates FscDirectoryException and persists no records.
	 *
	 * @return void
	 */
	public function testCallServiceWithUnresolvableTargetPersistsNothingAndThrows(): void {
		$this->sources[] = $this->sourceEntity();
		$this->logProvider->method('resolveService')->willThrowException(
			new FscDirectoryException('Unknown organisation "org-x" — not present in the configured directory.')
		);

		$this->expectException(FscDirectoryException::class);
		try {
			$this->service->callService(['organisation' => 'org-x', 'service' => 'svc-a']);
		} finally {
			$this->assertArrayNotHasKey(FscCallService::SCHEMA_CALL, $this->saved);
			$this->assertArrayNotHasKey(FscCallService::SCHEMA_SERVICE, $this->saved);
		}

	}//end testCallServiceWithUnresolvableTargetPersistsNothingAndThrows()

	/**
	 * One call's failure does not affect an independent second call (per-call isolation).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-one-calls-failure-does-not-affect-an-independent-second-call
	 */
	public function testOneCallsFailureDoesNotAffectIndependentSecondCall(): void {
		$this->sources[] = $this->sourceEntity(['provider' => 'rest']);

		$this->restProvider->method('resolveService')->willReturnCallback(
			fn (array $config, string $organisation, string $service) => $this->resolution(
				['organisation' => $organisation, 'service' => $service]
			)
		);
		$this->restProvider->method('call')->willReturnCallback(
			function (array $config, array $resolution, string $method, array $payload) {
				if ($resolution['organisation'] === 'org-fail') {
					throw new FscConnectivityException('down');
				}

				return ['ref' => 'FSC-ok', 'statusCode' => 200, 'body' => []];
			}
		);

		try {
			$this->service->callService(['organisation' => 'org-fail', 'service' => 'svc-a']);
			$this->fail('Expected the first call to fail.');
		} catch (FscConnectivityException $exception) {
			// Expected — the first call is deliberately failing.
		}

		$result = $this->service->callService(['organisation' => 'org-ok', 'service' => 'svc-b']);

		$this->assertSame('FSC-ok', $result['ref']);
		$this->assertCount(2, $this->saved[FscCallService::SCHEMA_CALL]);
		$this->assertSame('failed', $this->saved[FscCallService::SCHEMA_CALL][0]['object']['status']);
		$this->assertSame('sent', $this->saved[FscCallService::SCHEMA_CALL][1]['object']['status']);

	}//end testOneCallsFailureDoesNotAffectIndependentSecondCall()

	/**
	 * listResolvableServices() with no active source returns an empty list, not an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-listing-services-when-unconfigured-returns-an-empty-list-not-an-error
	 */
	public function testListResolvableServicesReturnsEmptyWhenUnconfigured(): void {
		$this->assertSame([], $this->service->listResolvableServices());

	}//end testListResolvableServicesReturnsEmptyWhenUnconfigured()

	/**
	 * listResolvableServices() returns the current fsc_service cache.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-listing-services-returns-the-current-cache
	 */
	public function testListResolvableServicesReturnsCache(): void {
		$this->sources[] = $this->sourceEntity();
		$this->logProvider->method('resolveService')->willReturn($this->resolution());
		$this->logProvider->method('call')->willReturn(['ref' => 'FSC-MOCK-1', 'statusCode' => 200, 'body' => []]);

		$this->service->callService(['organisation' => 'org-a', 'service' => 'svc-a']);

		$services = $this->service->listResolvableServices();

		$this->assertCount(1, $services);
		$this->assertSame('org-a', $services[0]['organisation']);

	}//end testListResolvableServicesReturnsCache()

	/**
	 * callService() defaults method to POST and payload to an empty array when absent.
	 *
	 * @return void
	 */
	public function testCallServiceDefaultsMethodAndPayload(): void {
		$this->sources[] = $this->sourceEntity();
		$this->logProvider->method('resolveService')->willReturn($this->resolution());

		$capturedMethod = null;
		$capturedPayload = null;
		$this->logProvider->method('call')->willReturnCallback(
			function (array $config, array $resolution, string $method, array $payload) use (&$capturedMethod, &$capturedPayload) {
				$capturedMethod = $method;
				$capturedPayload = $payload;
				return ['ref' => 'FSC-MOCK-1', 'statusCode' => 200, 'body' => []];
			}
		);

		$this->service->callService(['organisation' => 'org-a', 'service' => 'svc-a']);

		$this->assertSame('POST', $capturedMethod);
		$this->assertSame([], $capturedPayload);

	}//end testCallServiceDefaultsMethodAndPayload()
}//end class
