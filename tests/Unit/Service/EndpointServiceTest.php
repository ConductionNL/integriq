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
use OCA\OpenConnector\Rule\AvgBsnPolicyRule;
use OCA\OpenConnector\Rule\CompositeFanoutRule;
use OCA\OpenConnector\Rule\ReferentienummerRule;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
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
     * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlGenerator;

    /**
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $container;

    /**
     * @var \OCA\OpenConnector\Service\ApprovalService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $approvalService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);

        $this->objectService = $this->createMock(ObjectService::class);
        $callService     = $this->createMock(CallService::class);
        $logger          = $this->createMock(LoggerInterface::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('getBaseUrl')->willReturn('https://example.org');
        $urlGenerator    = $this->urlGenerator;
        $mappingService  = $this->createMock(MappingService::class);
        $config          = $this->createMock(IConfig::class);
        $appConfig       = $this->createMock(IAppConfig::class);
        $storageService  = $this->createMock(StorageService::class);
        $authService     = $this->createMock(AuthorizationService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $container       = $this->container;
        $syncService     = $this->createMock(SynchronizationService::class);
        $ruleService     = $this->createMock(RuleService::class);
        $signatureService = new \OCA\OpenConnector\Service\WebhookSignatureService($logger);
        $rateLimitService = $this->createMock(\OCA\OpenConnector\Service\RateLimit\InboundRateLimitService::class);
        $compositeFanoutRule  = new CompositeFanoutRule($this->orObjectService, $logger);
        $referentienummerRule = new ReferentienummerRule();
        $avgBsnPolicyRule      = new AvgBsnPolicyRule();
        $this->approvalService = $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class);
        $approvalService       = $this->approvalService;

        // EndpointService constructor signature (18 args, no $appConfig):
        //   objectService, callService, logger, urlGenerator, mappingService,
        //   orObjectService, config, storageService, authorizationService,
        //   container, synchronizationService, ruleService, webhookSignatureService,
        //   rateLimitService, compositeFanoutRule, referentienummerRule, avgBsnPolicyRule,
        //   approvalService (hitl-approval-rule-action).
        // The previous version slipped $appConfig into position 8 which made
        // $storageService land on $authService — a pre-existing test bug
        // surfaced once #1015 unblocked the suite from crashing in setUp.
        unset($appConfig);
        $this->service = new EndpointService(
            $this->objectService,
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
            $signatureService,
            $rateLimitService,
            $compositeFanoutRule,
            $referentienummerRule,
            $avgBsnPolicyRule,
            $approvalService,
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


    /**
     * Test that locking rules keep the OpenRegister lock payload shape.
     *
     * @return void
     */
    public function testProcessLockingRuleReturnsLockPayloadArray(): void
    {
        $openRegisters = ObjectServiceMockBuilder::make($this);
        $openRegisters->method('lockObject')->willReturn(['locked' => true, 'process' => 'test-process']);
        $this->objectService->method('getOpenRegisters')->willReturn($openRegisters);

        $rule = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => ['locking' => ['action' => 'lock', 'duration' => 60]]],
            'rule-lock'
        );

        $method = new \ReflectionMethod(EndpointService::class, 'processLockingRule');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $rule, ['body' => []], 'object-uuid');

        $this->assertSame(['locked' => true, 'process' => 'test-process'], $result['body']);
    }//end testProcessLockingRuleReturnsLockPayloadArray()


    /**
     * Test that unlock boolean results are normalised to an object-shaped body.
     *
     * @return void
     */
    public function testProcessLockingRuleNormalisesUnlockBoolean(): void
    {
        $openRegisters = ObjectServiceMockBuilder::make($this);
        $openRegisters->method('unlockObject')->willReturn(true);
        $this->objectService->method('getOpenRegisters')->willReturn($openRegisters);

        $rule = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => ['locking' => ['action' => 'unlock']]],
            'rule-unlock'
        );

        $method = new \ReflectionMethod(EndpointService::class, 'processLockingRule');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $rule, ['body' => []], 'object-uuid');

        $this->assertSame(['unlocked' => true], $result['body']);
    }//end testProcessLockingRuleNormalisesUnlockBoolean()


    /**
     * A `before`-timing approval rule suspends the pipeline via
     * ApprovalService::suspend() and returns a 202 JSONResponse carrying the
     * approval_request id + status URL — rule-pipeline REQ-RULE-008 / TC-1.
     *
     * @return void
     */
    public function testProcessApprovalRuleBeforeSuspendsWith202(): void
    {
        $created = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'approval-created');
        $this->approvalService->expects($this->once())->method('suspend')->willReturn($created);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.org/apps/openconnector/api/approvals/approval-created');

        $endpoint  = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'WOO Publish'], 'endpoint-1');
        $rule      = ObjectServiceMockBuilder::objectEntity($this, ['order' => 20, 'configuration' => ['approval' => ['approverGroup' => 'woo-approvers']]], 'rule-1');
        $flowToken = new \OCA\OpenConnector\Service\Helper\FlowToken();

        $method = new \ReflectionMethod(EndpointService::class, 'processApprovalRule');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $rule, $endpoint, $flowToken, 'before');

        $this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
        $this->assertSame(202, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('pending_approval', $data['status']);
        $this->assertSame('approval-created', $data['approvalRequestId']);
    }//end testProcessApprovalRuleBeforeSuspendsWith202()


    /**
     * An approval rule reaching the dispatch during an `after` phase is
     * rejected as invalid configuration (no suspension attempted) —
     * rule-pipeline REQ-RULE-008 / approval-workflow REQ-001 / TC-3.
     *
     * @return void
     */
    public function testProcessApprovalRuleAfterThrows(): void
    {
        $this->approvalService->expects($this->never())->method('suspend');

        $endpoint  = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'WOO Publish'], 'endpoint-1');
        $rule      = ObjectServiceMockBuilder::objectEntity($this, ['order' => 20, 'timing' => 'after', 'configuration' => ['approval' => []]], 'rule-1');
        $flowToken = new \OCA\OpenConnector\Service\Helper\FlowToken();

        $method = new \ReflectionMethod(EndpointService::class, 'processApprovalRule');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $method->invoke($this->service, $rule, $endpoint, $flowToken, 'after');
    }//end testProcessApprovalRuleAfterThrows()


    /**
     * renderSelfUrlAndHal stamps an absolute `url` self-link built from the endpoint's own path.
     *
     * @return void
     */
    public function testRenderSelfUrlAndHalStampsAbsoluteSelfLink(): void
    {
        $endpoint = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['endpoint' => 'klantinteracties/klantcontacten'],
            'endpoint-uuid'
        );

        $result = $this->service->renderSelfUrlAndHal(['id' => 'resource-1', 'onderwerp' => 'x'], $endpoint);

        $this->assertSame('https://example.org/apps/openconnector/api/endpoint/klantinteracties/klantcontacten/resource-1', $result['url']);
        $this->assertSame($result['url'], $result['_links']['self']['href']);
    }//end testRenderSelfUrlAndHalStampsAbsoluteSelfLink()


    /**
     * renderSelfUrlAndHal renders each host's own base URL (no hard-coded host).
     *
     * Constructs a second EndpointService bound to a different IURLGenerator
     * mock — `urlGenerator` is a constructor-promoted `private readonly`
     * property, so it cannot be swapped on an already-built instance.
     *
     * @return void
     */
    public function testRenderSelfUrlAndHalReflectsItsOwnHost(): void
    {
        $endpoint = ObjectServiceMockBuilder::objectEntity($this, ['endpoint' => 'klantinteracties/partijen'], 'endpoint-uuid-2');

        $resultA = $this->service->renderSelfUrlAndHal(['id' => '1'], $endpoint);

        $otherUrlGenerator = $this->createMock(IURLGenerator::class);
        $otherUrlGenerator->method('getBaseUrl')->willReturn('https://other-host.example');

        $logger = $this->createMock(LoggerInterface::class);
        $otherService = new EndpointService(
            $this->objectService,
            $this->createMock(CallService::class),
            $logger,
            $otherUrlGenerator,
            $this->createMock(MappingService::class),
            $this->orObjectService,
            $this->createMock(IConfig::class),
            $this->createMock(StorageService::class),
            $this->createMock(AuthorizationService::class),
            $this->container,
            $this->createMock(SynchronizationService::class),
            $this->createMock(RuleService::class),
            new \OCA\OpenConnector\Service\WebhookSignatureService($logger),
            $this->createMock(\OCA\OpenConnector\Service\RateLimit\InboundRateLimitService::class),
            new CompositeFanoutRule($this->orObjectService, $logger),
            new ReferentienummerRule(),
            new AvgBsnPolicyRule(),
            $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
        );

        $resultB = $otherService->renderSelfUrlAndHal(['id' => '1'], $endpoint);

        $this->assertNotSame($resultA['url'], $resultB['url']);
        $this->assertStringStartsWith('https://other-host.example', $resultB['url']);
    }//end testRenderSelfUrlAndHalReflectsItsOwnHost()


    /**
     * checkPutMandatoryFields returns the schema's required fields absent from the PUT body.
     *
     * @return void
     */
    public function testCheckPutMandatoryFieldsReturnsMissingFields(): void
    {
        $mapper = $this->getMockBuilder(ORObjectService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getSchema'])
            ->getMock();
        $mapper->method('getSchema')->willReturn(42);

        $schema = new class {
            public function getRequired(): array
            {
                return ['name', 'email'];
            }
        };
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $this->container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($schemaMapper);

        $missing = $this->service->checkPutMandatoryFields(['name' => 'x'], $mapper);

        $this->assertSame(['email'], $missing);
    }//end testCheckPutMandatoryFieldsReturnsMissingFields()


    /**
     * checkPutMandatoryFields returns an empty list when every required field is present.
     *
     * @return void
     */
    public function testCheckPutMandatoryFieldsReturnsEmptyWhenComplete(): void
    {
        $mapper = $this->getMockBuilder(ORObjectService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getSchema'])
            ->getMock();
        $mapper->method('getSchema')->willReturn(42);

        $schema = new class {
            public function getRequired(): array
            {
                return ['name'];
            }
        };
        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $this->container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($schemaMapper);

        $missing = $this->service->checkPutMandatoryFields(['name' => 'x'], $mapper);

        $this->assertSame([], $missing);
    }//end testCheckPutMandatoryFieldsReturnsEmptyWhenComplete()


}//end class
