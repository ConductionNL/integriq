<?php
/**
 * Unit tests for CallService.
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

use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Tests for the outbound call service (OR cutover — no deleted Db types).
 */
class CallServiceTest extends TestCase
{

    /**
     * @var CallService
     */
    private CallService $service;

    /**
     * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);

        $authService = $this->createMock(AuthenticationService::class);
        $appConfig   = $this->createMock(IAppConfig::class);

        // IAppConfig::hasKey defaults to false so no retention config is applied.
        $appConfig->method('hasKey')->willReturn(false);

        $this->service = new CallService(
            $this->objectService,
            new ArrayLoader([]),
            $authService,
            $appConfig,
        );
    }//end setUp()


    /**
     * Test that the constructor instantiates CallService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(CallService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that applyConfigDot expands dot-notation keys into nested arrays.
     *
     * @return void
     */
    public function testApplyConfigDotExpandsDotKeys(): void
    {
        // Arrange
        $config = [
            'foo.bar' => 'baz',
            'plain'   => 'value',
        ];

        // Act
        $result = $this->service->applyConfigDot($config);

        // Assert
        $this->assertArrayHasKey('foo', $result);
        $this->assertSame('baz', $result['foo']['bar']);
        $this->assertSame('value', $result['plain']);
        $this->assertArrayNotHasKey('foo.bar', $result);
    }//end testApplyConfigDotExpandsDotKeys()


    /**
     * Test that applyConfigDot returns flat config unchanged when no dot keys present.
     *
     * @return void
     */
    public function testApplyConfigDotLeavesNonDotKeysUntouched(): void
    {
        // Arrange
        $config = ['key' => 'value', 'another' => 123];

        // Act
        $result = $this->service->applyConfigDot($config);

        // Assert
        $this->assertSame($config, $result);
    }//end testApplyConfigDotLeavesNonDotKeysUntouched()


    /**
     * Test that removeFiles does not throw when config has no certificate keys.
     *
     * @return void
     */
    public function testRemoveFilesHandlesEmptyConfig(): void
    {
        // Arrange / Act / Assert — should not throw
        $this->service->removeFiles([]);
        $this->assertTrue(true);
    }//end testRemoveFilesHandlesEmptyConfig()


    /**
     * Test that getCertificate passes through config with no cert keys unchanged.
     *
     * @return void
     */
    public function testGetCertificatePassesThroughWhenNoCertKey(): void
    {
        // Arrange
        $config = ['url' => 'https://example.com'];

        // Act
        $this->service->getCertificate($config);

        // Assert
        $this->assertSame(['url' => 'https://example.com'], $config);
    }//end testGetCertificatePassesThroughWhenNoCertKey()


}//end class
