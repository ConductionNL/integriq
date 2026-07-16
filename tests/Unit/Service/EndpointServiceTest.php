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
use OCA\OpenConnector\Service\FlowRunnerService;
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
use OCP\IRequestId;
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
     * @var FlowRunnerService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $flowRunnerService;


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
        $this->flowRunnerService = $this->createMock(FlowRunnerService::class);
        $flowRunnerService       = $this->flowRunnerService;

        // EndpointService constructor signature (20 args, no $appConfig):
        //   objectService, callService, logger, urlGenerator, mappingService,
        //   orObjectService, config, storageService, authorizationService,
        //   container, synchronizationService, ruleService, webhookSignatureService,
        //   rateLimitService, compositeFanoutRule, referentienummerRule, avgBsnPolicyRule,
        //   approvalService (hitl-approval-rule-action), requestId
        //   (flow-workflowengine-integration — triggerFromFlow()'s synthetic request),
        //   flowRunnerService (visual-flow-orchestration — the `flow` rule action type).
        // The previous version slipped $appConfig into position 8 which made
        // $storageService land on $authService — a pre-existing test bug
        // surfaced once #1015 unblocked the suite from crashing in setUp.
        unset($appConfig);
        $requestId = $this->createMock(IRequestId::class);
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
            $requestId,
            $flowRunnerService,
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
     * A `flow` rule calls FlowRunnerService::run() with the pipeline's
     * $data as input and returns $data unmodified when the run completes
     * successfully — rule-pipeline REQ-RULE-009 / TC-16.
     *
     * @return void
     */
    public function testProcessFlowRuleRunsAndReturnsDataUnmodified(): void
    {
        $flow    = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Test flow'], 'flow-1');
        $flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'completed'], 'flow-run-1');

        $this->flowRunnerService->expects($this->once())->method('findFlow')->with('flow-1')->willReturn($flow);
        $this->flowRunnerService->expects($this->once())
            ->method('run')
            ->with($this->identicalTo($flow), ['foo' => 'bar'], 'endpoint')
            ->willReturn($flowRun);

        $rule = ObjectServiceMockBuilder::objectEntity($this, ['order' => 20, 'configuration' => ['flow' => 'flow-1']], 'rule-1');
        $data = ['foo' => 'bar'];

        $method = new \ReflectionMethod(EndpointService::class, 'processFlowRule');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $rule, $data);

        $this->assertSame($data, $result);
    }//end testProcessFlowRuleRunsAndReturnsDataUnmodified()


    /**
     * A `flow` rule whose referenced flow run ends `stopped`/`dead_letter`/
     * `failed` surfaces as a rule-pipeline failure (an Exception), matching
     * the same failure contract every other rule type already uses —
     * rule-pipeline REQ-RULE-009.
     *
     * @return void
     */
    public function testProcessFlowRuleThrowsWhenFlowRunFails(): void
    {
        $flow    = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Test flow'], 'flow-1');
        $flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'stopped'], 'flow-run-1');

        $this->flowRunnerService->method('findFlow')->willReturn($flow);
        $this->flowRunnerService->method('run')->willReturn($flowRun);

        $rule = ObjectServiceMockBuilder::objectEntity($this, ['order' => 20, 'configuration' => ['flow' => 'flow-1']], 'rule-1');

        $method = new \ReflectionMethod(EndpointService::class, 'processFlowRule');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $method->invoke($this->service, $rule, ['foo' => 'bar']);
    }//end testProcessFlowRuleThrowsWhenFlowRunFails()


    /**
     * A `flow` rule with no `configuration.flow` is a configuration error —
     * throws before ever calling FlowRunnerService.
     *
     * @return void
     */
    public function testProcessFlowRuleThrowsWithoutConfiguredFlow(): void
    {
        $this->flowRunnerService->expects($this->never())->method('run');

        $rule = ObjectServiceMockBuilder::objectEntity($this, ['order' => 20, 'configuration' => []], 'rule-1');

        $method = new \ReflectionMethod(EndpointService::class, 'processFlowRule');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $method->invoke($this->service, $rule, ['foo' => 'bar']);
    }//end testProcessFlowRuleThrowsWithoutConfiguredFlow()


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
            $this->createMock(IRequestId::class),
            $this->createMock(FlowRunnerService::class),
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


    /**
     * triggerFromFlow() (flow-workflowengine-integration TC-6) synthesizes a GET
     * request carrying the given parameters and delegates to the existing
     * handleRequest() without duplicating any routing/proxy logic — verified via
     * a partial mock so only handleRequest() is intercepted, real construction of
     * the synthetic OC\AppFramework\Http\Request stub still runs.
     *
     * @return void
     *
     * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operations-onevent-must-dispatch-to-endpointservicetriggerfromflow-req-003
     */
    public function testTriggerFromFlowSynthesizesRequestAndDelegatesToHandleRequest(): void
    {
        $endpoint = ObjectServiceMockBuilder::objectEntity($this, ['endpoint' => 'test/trigger'], 'endpoint-uuid-flow-1');

        $requestId = $this->createMock(IRequestId::class);
        $requestId->method('getId')->willReturn('req-flow-1');

        $expectedResponse = new \OCP\AppFramework\Http\JSONResponse(['ok' => true]);

        $service = $this->getMockBuilder(EndpointService::class)
            ->setConstructorArgs(
                [
                    $this->objectService,
                    $this->createMock(CallService::class),
                    $this->createMock(LoggerInterface::class),
                    $this->urlGenerator,
                    $this->createMock(MappingService::class),
                    $this->orObjectService,
                    $this->createMock(IConfig::class),
                    $this->createMock(StorageService::class),
                    $this->createMock(AuthorizationService::class),
                    $this->container,
                    $this->createMock(SynchronizationService::class),
                    $this->createMock(RuleService::class),
                    new \OCA\OpenConnector\Service\WebhookSignatureService($this->createMock(LoggerInterface::class)),
                    $this->createMock(\OCA\OpenConnector\Service\RateLimit\InboundRateLimitService::class),
                    new CompositeFanoutRule($this->orObjectService, $this->createMock(LoggerInterface::class)),
                    new ReferentienummerRule(),
                    new AvgBsnPolicyRule(),
                    $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
                    $requestId,
                    $this->createMock(FlowRunnerService::class),
                ]
            )
            ->onlyMethods(['handleRequest'])
            ->getMock();

        $service->expects($this->once())
            ->method('handleRequest')
            ->with(
                $this->identicalTo($endpoint),
                $this->callback(
                    static function (\OCP\IRequest $request): bool {
                        return $request->getMethod() === 'GET' && $request->getParam('foo') === 'bar';
                    }
                ),
                ''
            )
            ->willReturn($expectedResponse);

        $result = $service->triggerFromFlow($endpoint, ['foo' => 'bar']);

        $this->assertSame($expectedResponse, $result);
    }//end testTriggerFromFlowSynthesizesRequestAndDelegatesToHandleRequest()


    /**
     * Build a dedicated EndpointService instance for the trace-propagation
     * tests below, with a caller-supplied RuleService mock (so `custom` rule
     * dispatch is assertable) and container mock (so `save_object`'s OR
     * write is assertable never-called under dry-run).
     *
     * @param \PHPUnit\Framework\MockObject\MockObject      $ruleService     RuleService mock.
     * @param \PHPUnit\Framework\MockObject\MockObject      $container       ContainerInterface mock.
     * @param \PHPUnit\Framework\MockObject\MockObject|null $mappingService  Optional MappingService mock
     *                                                                       (the `mapping` rule's collaborator).
     * @param \PHPUnit\Framework\MockObject\MockObject|null $syncService     Optional SynchronizationService mock
     *                                                                       (the `synchronization` rule's collaborator).
     *
     * @return EndpointService
     */
    private function buildServiceForTraceTests($ruleService, $container, $mappingService=null, $syncService=null): EndpointService
    {
        $logger = $this->createMock(LoggerInterface::class);

        if ($mappingService === null) {
            $mappingService = $this->createMock(MappingService::class);
        }

        if ($syncService === null) {
            $syncService = $this->createMock(SynchronizationService::class);
        }

        return new EndpointService(
            $this->objectService,
            $this->createMock(CallService::class),
            $logger,
            $this->urlGenerator,
            $mappingService,
            $this->orObjectService,
            $this->createMock(IConfig::class),
            $this->createMock(StorageService::class),
            $this->createMock(AuthorizationService::class),
            $container,
            $syncService,
            $ruleService,
            new \OCA\OpenConnector\Service\WebhookSignatureService($logger),
            $this->createMock(\OCA\OpenConnector\Service\RateLimit\InboundRateLimitService::class),
            new CompositeFanoutRule($this->orObjectService, $logger),
            new ReferentienummerRule(),
            new AvgBsnPolicyRule(),
            $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
            $this->createMock(IRequestId::class),
        );
    }//end buildServiceForTraceTests()


    /**
     * A traced pipeline records one step per evaluated rule, in order,
     * including a `skipped` status for a rule whose conditions fail —
     * execution-trace REQ-002, rule-pipeline REQ-RULE-010.
     *
     * @return void
     */
    public function testProcessRulesEmitsOrderedStepsIncludingSkipped(): void
    {
        $ruleService = $this->createMock(RuleService::class);
        $ruleService->method('processCustomRule')->willReturn(['body' => ['ok' => true]]);
        $container = $this->createMock(ContainerInterface::class);

        $service = $this->buildServiceForTraceTests($ruleService, $container);

        $endpoint = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'ep', 'rules' => ['rule-skip', 'rule-custom']], 'endpoint-trace-1');

        $skippedRule = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['order' => 10, 'name' => 'Skipped rule', 'type' => 'custom', 'timing' => 'before', 'conditions' => ['==' => [1, 2]]],
            'rule-skip'
        );
        $customRule  = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['order' => 20, 'name' => 'Custom rule', 'type' => 'custom', 'timing' => 'before'],
            'rule-custom'
        );

        $this->orObjectService->method('find')->willReturnCallback(
            static function (string $id, ...$rest) use ($skippedRule, $customRule) {
                unset($rest);
                return match ($id) {
                    'rule-skip' => $skippedRule,
                    'rule-custom' => $customRule,
                    default => null,
                };
            }
        );

        $flowToken = new \OCA\OpenConnector\Service\Helper\FlowToken();
        $trace     = new \OCA\OpenConnector\Service\Helper\ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'endpoint-trace-1');

        $method = new \ReflectionMethod(EndpointService::class, 'processRules');
        $method->setAccessible(true);

        $method->invoke(
            $service,
            $endpoint,
            $this->createMock(\OCP\IRequest::class),
            ['parameters' => [], 'headers' => [], 'path' => '/x', 'method' => 'GET', 'body' => []],
            'before',
            null,
            $flowToken,
            null,
            $trace,
            false
        );

        $steps = $trace->getSteps();
        $this->assertCount(2, $steps);
        $this->assertSame(1, $steps[0]['order']);
        $this->assertSame('skipped', $steps[0]['status']);
        $this->assertSame(2, $steps[1]['order']);
        $this->assertSame('success', $steps[1]['status']);
    }//end testProcessRulesEmitsOrderedStepsIncludingSkipped()


    /**
     * When no ExecutionTraceContext is supplied, processRules() behaves
     * identically to its pre-existing, untraced behaviour — no step
     * buffering occurs (there is nothing to assert on since no trace object
     * exists), and the rule chain still runs to completion — rule-pipeline
     * REQ-RULE-010 Notes.
     *
     * @return void
     */
    public function testProcessRulesWithoutTraceRunsUnaffected(): void
    {
        $ruleService = $this->createMock(RuleService::class);
        $ruleService->method('processCustomRule')->willReturn(['body' => ['ok' => true]]);
        $container = $this->createMock(ContainerInterface::class);

        $service = $this->buildServiceForTraceTests($ruleService, $container);

        $endpoint = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'ep', 'rules' => ['rule-custom']], 'endpoint-trace-2');
        $customRule = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['order' => 10, 'name' => 'Custom rule', 'type' => 'custom', 'timing' => 'before'],
            'rule-custom'
        );
        $this->orObjectService->method('find')->willReturn($customRule);

        $flowToken = new \OCA\OpenConnector\Service\Helper\FlowToken();

        $method = new \ReflectionMethod(EndpointService::class, 'processRules');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            $endpoint,
            $this->createMock(\OCP\IRequest::class),
            ['parameters' => [], 'headers' => [], 'path' => '/x', 'method' => 'GET', 'body' => []],
            'before',
            null,
            $flowToken,
            null,
            null,
            false
        );

        $this->assertIsArray($result);
        $this->assertSame(['ok' => true], $result['body']);
    }//end testProcessRulesWithoutTraceRunsUnaffected()


    /**
     * Under a dry-run replay, a `save_object` rule does NOT perform its
     * write — the OpenRegister ObjectService is never resolved from the
     * container — and the recorded step carries `status: 'skipped_dry_run'`
     * — rule-pipeline REQ-RULE-011.
     *
     * @return void
     */
    public function testProcessRulesDryRunSuppressesSaveObjectWrite(): void
    {
        $ruleService = $this->createMock(RuleService::class);
        $container   = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');

        $service = $this->buildServiceForTraceTests($ruleService, $container);

        $endpoint = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'ep', 'rules' => ['rule-save']], 'endpoint-trace-3');
        $saveRule = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['order' => 10, 'name' => 'Save object', 'type' => 'save_object', 'timing' => 'before', 'configuration' => ['save_object' => ['register' => 'openconnector', 'schema' => 'x']]],
            'rule-save'
        );
        $this->orObjectService->method('find')->willReturn($saveRule);

        $flowToken = new \OCA\OpenConnector\Service\Helper\FlowToken();
        $trace     = new \OCA\OpenConnector\Service\Helper\ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'endpoint-trace-3');

        $method = new \ReflectionMethod(EndpointService::class, 'processRules');
        $method->setAccessible(true);

        $method->invoke(
            $service,
            $endpoint,
            $this->createMock(\OCP\IRequest::class),
            ['parameters' => [], 'headers' => [], 'path' => '/x', 'method' => 'GET', 'body' => []],
            'before',
            null,
            $flowToken,
            null,
            $trace,
            true
        );

        $steps = $trace->getSteps();
        $this->assertCount(1, $steps);
        $this->assertSame('skipped_dry_run', $steps[0]['status']);
    }//end testProcessRulesDryRunSuppressesSaveObjectWrite()


    /**
     * Under a dry-run replay, a `mapping` rule (no external side-effect) is
     * NOT suppressed — the mapping is applied for real and the step carries a
     * normal `status: 'success'` — rule-pipeline REQ-RULE-011's
     * "dryRun does not suppress a mapping rule" scenario.
     *
     * @return void
     */
    public function testProcessRulesDryRunDoesNotSuppressMappingRule(): void
    {
        $mappingService = $this->createMock(MappingService::class);
        $mappingService->method('getMapping')->willReturn($this->createMock(\OCA\OpenRegister\Db\Mapping::class));
        // The mapping MUST actually execute under dryRun — this expectation is
        // the assertion that REQ-RULE-011 does not over-suppress.
        $mappingService->expects($this->once())
            ->method('executeMapping')
            ->willReturn(['mapped' => true]);

        $service = $this->buildServiceForTraceTests(
            $this->createMock(RuleService::class),
            $this->createMock(ContainerInterface::class),
            $mappingService
        );

        $endpoint    = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'ep', 'rules' => ['rule-map']], 'endpoint-trace-4');
        $mappingRule = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['order' => 10, 'name' => 'Map it', 'type' => 'mapping', 'timing' => 'before', 'configuration' => ['mapping' => 'mapping-1']],
            'rule-map'
        );
        $this->orObjectService->method('find')->willReturn($mappingRule);

        $flowToken = new \OCA\OpenConnector\Service\Helper\FlowToken();
        $trace     = new \OCA\OpenConnector\Service\Helper\ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'endpoint-trace-4');

        $method = new \ReflectionMethod(EndpointService::class, 'processRules');
        $method->setAccessible(true);

        $method->invoke(
            $service,
            $endpoint,
            $this->createMock(\OCP\IRequest::class),
            ['parameters' => [], 'headers' => [], 'path' => '/x', 'method' => 'GET', 'body' => []],
            'before',
            null,
            $flowToken,
            null,
            $trace,
            true
        );

        $steps = $trace->getSteps();
        $this->assertCount(1, $steps);
        $this->assertSame('success', $steps[0]['status']);
        $this->assertNotSame('skipped_dry_run', $steps[0]['status']);
    }//end testProcessRulesDryRunDoesNotSuppressMappingRule()


    /**
     * Under a dry-run replay, a `synchronization` rule is a deliberate partial
     * exception: it is NOT blanket-skipped but forwards `isTest: true` into
     * SynchronizationService::synchronize(), reusing synchronization-engine
     * REQ-011's existing no-write guarantee — rule-pipeline REQ-RULE-011's
     * "dryRun forwards isTest to a synchronization rule" scenario.
     *
     * @return void
     */
    public function testProcessRulesDryRunForwardsIsTestToSynchronizationRule(): void
    {
        $synchronizationEntity = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'sync-1'], 'sync-uuid-dry');
        // processSyncRule()'s debug line reads the entity's own `name` column
        // (not the object body) via the Entity __call getter.
        $synchronizationEntity->setName('sync-1');

        $syncService = $this->createMock(SynchronizationService::class);
        $syncService->method('getSynchronization')->willReturn($synchronizationEntity);
        $syncService->expects($this->once())
            ->method('synchronize')
            ->with(
                $this->anything(),
                // isTest MUST be true under dryRun.
                true,
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->isInstanceOf(\OCA\OpenConnector\Service\Helper\ExecutionTraceContext::class)
            )
            ->willReturn(['result' => []]);

        $service = $this->buildServiceForTraceTests(
            $this->createMock(RuleService::class),
            $this->createMock(ContainerInterface::class),
            null,
            $syncService
        );

        $endpoint = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'ep', 'rules' => ['rule-sync']], 'endpoint-trace-5');
        $syncRule = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'order'         => 10,
                'name'          => 'Sync it',
                'type'          => 'synchronization',
                'timing'        => 'before',
                'configuration' => ['synchronization' => 'sync-uuid-dry'],
            ],
            'rule-sync'
        );
        $this->orObjectService->method('find')->willReturn($syncRule);

        $flowToken = new \OCA\OpenConnector\Service\Helper\FlowToken();
        $trace     = new \OCA\OpenConnector\Service\Helper\ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'endpoint-trace-5');

        $method = new \ReflectionMethod(EndpointService::class, 'processRules');
        $method->setAccessible(true);

        $method->invoke(
            $service,
            $endpoint,
            $this->createMock(\OCP\IRequest::class),
            ['parameters' => [], 'headers' => [], 'path' => '/x', 'method' => 'GET', 'body' => []],
            'before',
            null,
            $flowToken,
            null,
            $trace,
            true
        );

        // Not blanket-skipped — the rule ran (for real, in test mode).
        $steps = $trace->getSteps();
        $this->assertCount(1, $steps);
        $this->assertNotSame('skipped_dry_run', $steps[0]['status']);
        // Guards the regression this test surfaced: processSyncRule() used to
        // throw BadFunctionCallException on getSourceConfig(), which
        // processRules() swallowed into a generic 500 + an `error` step.
        $this->assertSame('success', $steps[0]['status']);
    }//end testProcessRulesDryRunForwardsIsTestToSynchronizationRule()
}//end class
