<?php
/**
 * Unit tests for EndpointService.
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

use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EndpointService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\StorageService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\RuleService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the endpoint request handling service (OR cutover — no deleted Db types).
 */
class EndpointServiceTest extends TestCase
{

    /**
     * @var EndpointService
     */
    private EndpointService $service;

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);

        $objectService   = $this->createMock(ObjectService::class);
        $callService     = $this->createMock(CallService::class);
        $logger          = $this->createMock(LoggerInterface::class);
        $urlGenerator    = $this->createMock(IURLGenerator::class);
        $mappingService  = $this->createMock(MappingService::class);
        $config          = $this->createMock(IConfig::class);
        $appConfig       = $this->createMock(IAppConfig::class);
        $storageService  = $this->createMock(StorageService::class);
        $authService     = $this->createMock(AuthorizationService::class);
        $container       = $this->createMock(ContainerInterface::class);
        $syncService     = $this->createMock(SynchronizationService::class);
        $ruleService     = $this->createMock(RuleService::class);

        // EndpointService constructor signature (12 args, no $appConfig):
        //   objectService, callService, logger, urlGenerator, mappingService,
        //   orObjectService, config, storageService, authorizationService,
        //   container, synchronizationService, ruleService.
        // The previous version slipped $appConfig into position 8 which made
        // $storageService land on $authService — a pre-existing test bug
        // surfaced once #1015 unblocked the suite from crashing in setUp.
        unset($appConfig);
        $this->service = new EndpointService(
            $objectService,
            $callService,
            $logger,
            $urlGenerator,
            $mappingService,
            $this->orObjectService,
            $config,
            $storageService,
            $authService,
            $container,
            $syncService,
            $ruleService,
        );
    }//end setUp()


    /**
     * Test that the constructor instantiates EndpointService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(EndpointService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that handleRequest returns a JSONResponse (not-found) for a missing endpoint object.
     *
     * When the OR service returns null from find(), EndpointService should
     * return a 404 response rather than throwing.
     *
     * @return void
     */
    public function testHandleRequestReturns404WhenEndpointObjectMissing(): void
    {
        // Arrange
        $this->orObjectService->method('find')->willReturn(null);

        $endpointEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'method'   => 'GET',
                'path'     => '/test/path',
                'targetType' => 'register',
            ],
            'endpoint-uuid-1'
        );

        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getParams')->willReturn([]);
        $request->method('getHeader')->willReturn('');

        // Act
        $response = $this->service->handleRequest($endpointEntity, $request, '/test/path');

        // Assert — should return a response, not throw
        $this->assertInstanceOf(\OCP\AppFramework\Http\Response::class, $response);
    }//end testHandleRequestReturns404WhenEndpointObjectMissing()


    /**
     * Test handleRequest returns a response for an endpoint with proxy targetType.
     *
     * @return void
     */
    public function testHandleRequestWithProxyTargetTypeReturnsResponse(): void
    {
        // Arrange — source found by OR, but callService is mocked
        $sourceEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['isEnabled' => false, 'location' => 'https://example.com'],
            'source-uuid-1'
        );

        $this->orObjectService->method('find')->willReturn($sourceEntity);

        $endpointEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'method'     => 'GET',
                'path'       => '/api/proxy',
                'targetType' => 'api',
                'sourceId'   => 'source-uuid-1',
            ],
            'endpoint-uuid-2'
        );

        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getParams')->willReturn([]);
        $request->method('getHeader')->willReturn('');

        // Act
        $response = $this->service->handleRequest($endpointEntity, $request, '/api/proxy');

        // Assert
        $this->assertInstanceOf(\OCP\AppFramework\Http\Response::class, $response);
    }//end testHandleRequestWithProxyTargetTypeReturnsResponse()


    /**
     * Test that generateEndpointUrl returns a string (does not throw) for a valid schema mapper mock.
     *
     * @return void
     */
    public function testGenerateEndpointUrlReturnsString(): void
    {
        // Arrange — supply register+schema directly to skip the
        // ObjectService::getOpenRegisters()->getMapper(...) lookup path.
        // That path requires a deeper mock graph than the test originally
        // provided and was crashing once #1015 unblocked the suite.
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);

        // Act
        $url = $this->service->generateEndpointUrl(
            id: 'endpoint-id-1',
            schemaMapper: $schemaMapper,
            register: 1,
            schema: 1,
        );

        // Assert
        $this->assertIsString($url);
    }//end testGenerateEndpointUrlReturnsString()


}//end class
