<?php
/**
 * Unit tests for SynchronizationContractProvider.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\Integration\SynchronizationContractProvider;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pluggable integration registry SyncContract provider (OR cutover).
 */
class SynchronizationContractProviderTest extends TestCase
{

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
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->l10n          = $this->createMock(IL10N::class);

        // Default IL10N: return the key as-is so assertions can be string-based.
        $this->l10n->method('t')->willReturnCallback(static fn(string $key) => $key);

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
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(SynchronizationContractProvider::class, $this->provider);
    }//end testConstructorWiresDependencies()


    /**
     * Test that getId returns the expected string identifier.
     *
     * @return void
     */
    public function testGetIdReturnsExpectedIdentifier(): void
    {
        $this->assertSame('sync-contract', $this->provider->getId());
    }//end testGetIdReturnsExpectedIdentifier()


    /**
     * Test that list returns an empty array when the provider is disabled.
     *
     * @return void
     */
    public function testListReturnsEmptyArrayWhenDisabled(): void
    {
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
     * @return void
     */
    public function testListQueriesORWithTargetIdWhenEnabled(): void
    {
        // Arrange — provider enabled
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getAppValueString')->willReturn('true');

        $contractEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'synchronizationId' => 'sync-uuid',
                'originId'          => 'origin-1',
                'originHash'        => 'abc123',
                'targetLastAction'  => 'update',
                'targetLastSynced'  => '2024-01-01T00:00:00Z',
                'sourceLastChecked' => '2024-01-01T00:00:00Z',
            ],
            'contract-uuid-1'
        );

        $this->objectService = ObjectServiceMockBuilder::make($this);
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with(
                $this->callback(static fn(array $c) => ($c['filters']['targetId'] ?? '') === 'obj-uuid-2')
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
     * Test that health returns unavailable when provider is disabled.
     *
     * @return void
     */
    public function testHealthReturnsUnavailableWhenDisabled(): void
    {
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
    public function testHealthReturnsOkWhenEnabled(): void
    {
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
