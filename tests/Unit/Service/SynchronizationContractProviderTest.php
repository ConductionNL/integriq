<?php

/**
 * Unit tests for SynchronizationContractProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Integration\SynchronizationContractProvider;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pluggable integration registry SyncContract provider (OR cutover).
 */
class SynchronizationContractProviderTest extends TestCase {

	/**
	 * @var SynchronizationContractProvider
	 */
	private SynchronizationContractProvider $provider;

	/**
	 * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $appConfig;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l10n;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->l10n = $this->createMock(IL10N::class);

		// Default IL10N: return the key as-is so assertions can be string-based.
		$this->l10n->method('t')->willReturnCallback(static fn (string $key) => $key);

		// Default: storage_migrated = 'false' (provider disabled).
		$this->appConfig->method('getAppValueString')
			->willReturn('false');

		$this->provider = new SynchronizationContractProvider(
			$this->objectService,
			$this->appConfig,
			$this->l10n,
		);
	}//end setUp()

	/**
	 * Test that the constructor instantiates SynchronizationContractProvider.
	 *
	 * @return void
	 */
	public function testConstructorWiresDependencies(): void {
		$this->assertInstanceOf(SynchronizationContractProvider::class, $this->provider);
	}//end testConstructorWiresDependencies()

	/**
	 * Test that getId returns the expected string identifier.
	 *
	 * @return void
	 */
	public function testGetIdReturnsExpectedIdentifier(): void {
		$this->assertSame('sync-contract', $this->provider->getId());
	}//end testGetIdReturnsExpectedIdentifier()

	/**
	 * Test that list returns an empty array when the provider is disabled.
	 *
	 * @return void
	 */
	public function testListReturnsEmptyArrayWhenDisabled(): void {
		// Arrange — provider disabled (storage_migrated != 'true')
		$this->appConfig->method('getAppValueString')->willReturn('false');

		// OR findAll must NOT be called
		$this->objectService->expects($this->never())->method('findAll');

		// Act
		$result = $this->provider->list('some-register', 'some-schema', 'obj-uuid-1');

		// Assert
		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}//end testListReturnsEmptyArrayWhenDisabled()

	/**
	 * Test that list queries OR with targetId filter when enabled.
	 *
	 * The impl calls `$this->objectService->setRegister(...)->setSchema(...)->findAll(...)`.
	 * PHPUnit's auto-stubbing does NOT return $this for chainable setters with a
	 * `: static` return type — it produces a fresh mock per call — so the test
	 * must explicitly wire setRegister/setSchema to returnSelf, otherwise the
	 * configured findAll() never fires and the result is an empty array.
	 *
	 * @return void
	 */
	public function testListQueriesORWithTargetIdWhenEnabled(): void {
		// Arrange — provider enabled
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValueString')->willReturn('true');

		$contractEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'synchronizationId' => 'sync-uuid',
				'originId' => 'origin-1',
				'originHash' => 'abc123',
				'targetLastAction' => 'update',
				'targetLastSynced' => '2024-01-01T00:00:00Z',
				'sourceLastChecked' => '2024-01-01T00:00:00Z',
			],
			'contract-uuid-1'
		);

		$this->objectService = ObjectServiceMockBuilder::make($this);

		// The impl chains setRegister(...)->setSchema(...)->findAll(...).
		// PHPUnit does not auto-returnSelf these mocks; wire it explicitly.
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				$this->callback(static fn (array $c) => ($c['filters']['targetId'] ?? '') === 'obj-uuid-2')
			)
			->willReturn(['results' => [$contractEntity], 'total' => 1]);

		$provider = new SynchronizationContractProvider(
			$this->objectService,
			$this->appConfig,
			$this->l10n,
		);

		// Act
		$result = $provider->list('reg', 'schema', 'obj-uuid-2');

		// Assert
		$this->assertCount(1, $result);
		$this->assertSame('contract-uuid-1', $result[0]['id']);
		$this->assertSame('sync-uuid', $result[0]['synchronizationId']);
	}//end testListQueriesORWithTargetIdWhenEnabled()

	/**
	 * Test that list degrades to an empty array (not a 500) when the OR query
	 * throws — e.g. the `synchronization_contract` schema is declared in the
	 * register JSON but not yet mapped into the `openconnector` register on this
	 * instance (a lagging/forced-import state, openregister#2075). This leaf
	 * loads on every page and is queried from every app's OR sidebar, so a
	 * throw here must not surface as a fleet-wide 500 (AD-23 resilience).
	 *
	 * @return void
	 */
	public function testListReturnsEmptyArrayWhenQueryThrows(): void {
		// Arrange — provider enabled, but the OR query throws (unmapped schema).
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValueString')->willReturn('true');

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')
			->willThrowException(new \RuntimeException('schema synchronization_contract not found in register openconnector'));

		$provider = new SynchronizationContractProvider(
			$this->objectService,
			$this->appConfig,
			$this->l10n,
		);

		// Act
		$result = $provider->list('reg', 'schema', 'obj-uuid-err');

		// Assert — empty array, no exception propagated (endpoint stays 200).
		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}//end testListReturnsEmptyArrayWhenQueryThrows()

	/**
	 * Test that health returns unavailable when provider is disabled.
	 *
	 * @return void
	 */
	public function testHealthReturnsUnavailableWhenDisabled(): void {
		// Arrange — disabled (storage_migrated = 'false')
		$health = $this->provider->health();

		// Assert
		$this->assertSame('unavailable', $health['status']);
	}//end testHealthReturnsUnavailableWhenDisabled()

	/**
	 * Test that health returns ok when provider is enabled.
	 *
	 * @return void
	 */
	public function testHealthReturnsOkWhenEnabled(): void {
		// Arrange — enabled
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturn('true');

		$provider = new SynchronizationContractProvider(
			$this->objectService,
			$appConfig,
			$this->l10n,
		);

		// Act
		$health = $provider->health();

		// Assert
		$this->assertSame('ok', $health['status']);
	}//end testHealthReturnsOkWhenEnabled()

}//end class
