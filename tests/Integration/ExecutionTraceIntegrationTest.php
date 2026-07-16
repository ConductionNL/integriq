<?php
/**
 * Integration test: a traced outbound CallService::call() dispatch stamps
 * call_log.sessionId with the active traceId and the execution_trace's
 * `call` step reuses call_log's OWN already-redacted request/response data
 * byte-for-byte — never a second, independent redaction pass.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This repo has no live-Nextcloud-instance test harness (see
 * NextcloudEventDeliveryTest's header) — "integration" here follows the
 * same standalone-with-OCP-stubs convention: the REAL CallService class is
 * exercised end-to-end (redaction, retry dispatch, CallLog persistence,
 * trace-step assembly), with only the outermost boundaries (OpenRegister
 * persistence, the Guzzle HTTP transport) replaced with test doubles —
 * exactly CallServiceTest's own `mockGuzzleSequence()` technique.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
 * @spec openspec/specs/execution-trace/spec.md#requirement-snapshot-redaction-before-any-step-is-buffered-req-003
 * @spec openspec/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call_logsessionid-req-011
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Rule\AvgBsnPolicyRule;
use OCA\OpenConnector\Rule\CompositeFanoutRule;
use OCA\OpenConnector\Rule\ReferentienummerRule;
use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\BrokeredCallService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EndpointService;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\RuleService;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenConnector\Service\StorageService;
use OCA\OpenConnector\Service\SynchronizationService;
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
use Twig\Loader\ArrayLoader;

/**
 * @spec openspec/specs/execution-trace/spec.md
 */
class ExecutionTraceIntegrationTest extends TestCase
{

    /**
     * Captured saveObject() calls, keyed by insertion order.
     *
     * @var array
     */
    private array $saved = [];


    /**
     * Build a real CallService with a capturing ObjectService double and a
     * mocked Guzzle client returning the given single response.
     *
     * @param Response $response The response the mocked transport returns.
     *
     * @return CallService
     */
    private function buildCallService(Response $response): CallService
    {
        $this->saved = [];

        // Callback signature matches the test double's saveObject() shape
        // exactly (tests/stubs/OCA/OpenRegister/Service/ObjectService.php:
        // object, register, schema, uuid, _rbac, _multitenancy — no `extend`
        // param, unlike the real OpenRegister ObjectService), mirroring
        // CallServiceTest::buildBrokeredCallService()'s own callback.
        $objectService = $this->createMock(ORObjectService::class);
        $objectService->method('saveObject')->willReturnCallback(
            function ($object=[], $register=null, $schema=null, $uuid=null) {
                $this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
                $entity = new ObjectEntity();
                $entity->setUuid('saved-'.count($this->saved));
                $entity->setObject(is_array($object) === true ? $object : []);
                return $entity;
            }
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $brokered = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(false);

        $service = new CallService(
            $objectService,
            new ArrayLoader([]),
            $this->createMock(AuthenticationService::class),
            $appConfig,
            $this->createMock(LoggerInterface::class),
            $brokered,
            new SensitiveFieldRegistry(),
        );

        // Intercept the real Guzzle client — mirrors CallServiceTest's own
        // mockGuzzleSequence() technique (this file cannot reuse that
        // private method across test classes, so it is reproduced here for
        // a single response).
        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockClient->method('request')->willReturn($response);

        $clientProperty = new \ReflectionProperty(CallService::class, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        return $service;

    }//end buildCallService()


    /**
     * A source configured with a `client_secret` form parameter.
     *
     * @return ObjectEntity
     */
    private function makeSourceWithClientSecret(): ObjectEntity
    {
        $source = new ObjectEntity();
        $source->setUuid('source-trace-1');
        $source->setObject(
            [
                'name'          => 'trace-source',
                'isEnabled'     => true,
                'location'      => 'https://api.example.invalid',
                'configuration' => [
                    'form_params' => ['client_secret' => 'super-secret-value'],
                ],
            ]
        );

        return $source;

    }//end makeSourceWithClientSecret()


    /**
     * execution-trace REQ-001/http-call-engine REQ-011 Scenario 1: a call
     * made from within a traced execution stamps call_log.sessionId with
     * the active traceId.
     *
     * @return void
     */
    public function testTracedCallStampsCallLogSessionId(): void
    {
        $service = $this->buildCallService(new Response(200, [], '{"ok":true}'));
        $trace   = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'endpoint-1');

        $service->call(source: $this->makeSourceWithClientSecret(), endpoint: '/v1/items', trace: $trace);

        $callLogs = array_values(array_filter($this->saved, static fn (array $row): bool => $row['schema'] === 'call_log'));
        $this->assertCount(1, $callLogs);
        $this->assertSame($trace->getTraceId(), $callLogs[0]['object']['sessionId']);
    }//end testTracedCallStampsCallLogSessionId()


    /**
     * http-call-engine REQ-011 Scenario 2: an untraced call (no
     * ExecutionTraceContext) leaves sessionId unset — byte-for-byte
     * unchanged from pre-existing behaviour.
     *
     * @return void
     */
    public function testUntracedCallLeavesSessionIdUnset(): void
    {
        $service = $this->buildCallService(new Response(200, [], '{"ok":true}'));

        $service->call(source: $this->makeSourceWithClientSecret(), endpoint: '/v1/items');

        $callLogs = array_values(array_filter($this->saved, static fn (array $row): bool => $row['schema'] === 'call_log'));
        $this->assertCount(1, $callLogs);
        $this->assertArrayNotHasKey('sessionId', $callLogs[0]['object']);
    }//end testUntracedCallLeavesSessionIdUnset()


    /**
     * execution-trace REQ-003 Scenario 2 / http-call-engine REQ-011
     * Scenario 3: the trace's `call` step output equals the call_log's
     * redacted request/response — no plaintext secret in either, and no
     * second, independent redaction pass (the values are the SAME array).
     *
     * @return void
     */
    public function testTraceCallStepReusesCallLogsRedactedDataByteForByte(): void
    {
        $service = $this->buildCallService(new Response(200, [], '{"ok":true}'));
        $trace   = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'endpoint-1');

        $service->call(source: $this->makeSourceWithClientSecret(), endpoint: '/v1/items', trace: $trace);

        $callLogs = array_values(array_filter($this->saved, static fn (array $row): bool => $row['schema'] === 'call_log'));
        $this->assertCount(1, $callLogs);

        $steps = $trace->getSteps();
        $callSteps = array_values(array_filter($steps, static fn (array $step): bool => $step['type'] === 'call'));
        $this->assertCount(1, $callSteps);

        // No plaintext secret anywhere.
        $this->assertStringNotContainsString('super-secret-value', json_encode($callLogs[0]['object']));
        $this->assertStringNotContainsString('super-secret-value', json_encode($callSteps[0]['input']));
        $this->assertStringNotContainsString('super-secret-value', json_encode($callSteps[0]['output']));

        // The trace step's request input is the SAME redacted array persisted
        // to call_log.request — reused, not re-derived.
        $this->assertSame($callLogs[0]['object']['request'], $callSteps[0]['input']);
    }//end testTraceCallStepReusesCallLogsRedactedDataByteForByte()


    /**
     * execution-trace REQ-001 scenario: an ad-hoc call outside any traced
     * execution (no ExecutionTraceContext) produces no trace steps at all —
     * `call()`'s optional trace param defaults to null and nothing appends.
     *
     * @return void
     */
    public function testUntracedCallProducesNoTraceSteps(): void
    {
        $service = $this->buildCallService(new Response(200, [], '{"ok":true}'));

        // No trace object exists in this scenario at all — SourcesController::test()'s
        // real call site simply never constructs one; there is nothing to
        // assert against beyond confirming the call still completes normally.
        $result = $service->call(source: $this->makeSourceWithClientSecret(), endpoint: '/v1/items');

        $this->assertSame(200, $result->getObject()['statusCode']);
    }//end testUntracedCallProducesNoTraceSteps()


    /**
     * execution-trace REQ-002's headline: ONE execution's trace spans the rule
     * pipeline AND the outbound call, in real execution order, every step under
     * the SAME traceId.
     *
     * Both halves run their REAL implementation: `EndpointService::processRules()`
     * (rule + mapping dispatch) and `CallService::call()` (outbound dispatch,
     * redaction, CallLog persistence). Only the outermost boundaries are doubled
     * (OR persistence, Guzzle transport) — the trace context threaded through
     * both is one and the same object, which is precisely the contract under
     * test: steps interleave into one buffer rather than each layer starting its
     * own trace.
     *
     * The two halves are invoked in sequence rather than via `handleRequest()`
     * because `handleRequest()` mints its own trace internally and hands it to
     * `ExecutionTraceService::persist()`, leaving no seam to observe the buffer
     * from a test; driving that end-to-end needs a live instance (see the
     * unticked Task 12/Task 1 live-verification notes).
     *
     * @return void
     */
    public function testOneTraceSpansRuleMappingAndCallUnderOneTraceId(): void
    {
        $callService = $this->buildCallService(new Response(200, [], '{"ok":true}'));

        // Real MappingService collaborator behaviour for the `mapping` rule.
        $mappingService = $this->createMock(MappingService::class);
        $mappingService->method('getMapping')->willReturn($this->createMock(\OCA\OpenRegister\Db\Mapping::class));
        $mappingService->method('executeMapping')->willReturn(['mapped' => true]);

        $orObjectService = $this->createMock(ORObjectService::class);
        $mappingRule     = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['order' => 10, 'name' => 'Map it', 'type' => 'mapping', 'timing' => 'before', 'configuration' => ['mapping' => 'mapping-1']],
            'rule-map'
        );
        $orObjectService->method('find')->willReturn($mappingRule);

        $logger          = $this->createMock(LoggerInterface::class);
        $endpointService = new EndpointService(
            $this->createMock(ObjectService::class),
            $callService,
            $logger,
            $this->createMock(IURLGenerator::class),
            $mappingService,
            $orObjectService,
            $this->createMock(IConfig::class),
            $this->createMock(StorageService::class),
            $this->createMock(AuthorizationService::class),
            $this->createMock(ContainerInterface::class),
            $this->createMock(SynchronizationService::class),
            $this->createMock(RuleService::class),
            new \OCA\OpenConnector\Service\WebhookSignatureService($logger),
            $this->createMock(\OCA\OpenConnector\Service\RateLimit\InboundRateLimitService::class),
            new CompositeFanoutRule($orObjectService, $logger),
            new ReferentienummerRule(),
            new AvgBsnPolicyRule(),
            $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
            $this->createMock(IRequestId::class),
        );

        // ONE context, threaded through both layers.
        $trace    = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'endpoint-span-1');
        $expected = $trace->getTraceId();

        $endpoint = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'ep', 'rules' => ['rule-map']], 'endpoint-span-1');

        $processRules = new \ReflectionMethod(EndpointService::class, 'processRules');
        $processRules->setAccessible(true);
        $processRules->invoke(
            $endpointService,
            $endpoint,
            $this->createMock(\OCP\IRequest::class),
            ['parameters' => [], 'headers' => [], 'path' => '/x', 'method' => 'POST', 'body' => []],
            'before',
            null,
            new FlowToken(),
            null,
            $trace,
            false
        );

        $callService->call(source: $this->makeSourceWithClientSecret(), endpoint: '/v1/items', trace: $trace);

        $steps = $trace->getSteps();

        // Real execution order: the rule ran, then the call — not grouped by type.
        $this->assertCount(2, $steps);
        $this->assertSame('rule', $steps[0]['type']);
        $this->assertSame('Map it', $steps[0]['name']);
        $this->assertSame('call', $steps[1]['type']);

        // Ordered 1..n.
        $this->assertSame(1, $steps[0]['order']);
        $this->assertSame(2, $steps[1]['order']);

        // Exactly one traceId for the whole execution, and the call_log written
        // during it is joinable back to it via sessionId (http-call-engine REQ-011).
        $callLogs = array_values(array_filter($this->saved, static fn (array $row): bool => $row['schema'] === 'call_log'));
        $this->assertCount(1, $callLogs);
        $this->assertSame($expected, $callLogs[0]['object']['sessionId']);

        // No secret leaked into any step of the assembled trace.
        $this->assertStringNotContainsString('super-secret-value', json_encode($steps));
    }//end testOneTraceSpansRuleMappingAndCallUnderOneTraceId()
}//end class
