<?php
/**
 * Integration test: a real NC-native event flows through the full
 * listener -> EventService -> deliverMessage -> WebhookSignatureService ->
 * retry -> dead-letter -> replay chain, using REAL collaborator instances
 * (only the outermost boundaries — the OpenRegister persistence layer and
 * the outbound HTTP client — are test doubles).
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
 * This repo has no live-Nextcloud-instance test harness (no docker-based
 * integration runner is wired into either phpunit.xml or phpunit-unit.xml,
 * and none is referenced by this app's documented local check commands) —
 * "integration" here follows the same standalone-with-OCP-stubs convention
 * every other suite in this repo uses (tests/bootstrap.php), but exercises
 * the REAL EventService/WebhookSignatureService/listener classes together
 * end-to-end rather than mocking EventService itself, unlike the Unit suite.
 *
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 * @spec openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Integration;

use OCA\OpenConnector\EventListener\NextcloudFileEventListener;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Node;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */
class NextcloudEventDeliveryTest extends TestCase
{

    /**
     * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var IClientService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $clientService;

    /**
     * @var EventService
     */
    private EventService $eventService;

    /**
     * @var NextcloudFileEventListener
     */
    private NextcloudFileEventListener $listener;

    /**
     * Persisted `event_message` state, keyed by uuid — a minimal in-memory
     * OR stand-in so the retry sweep / replay flow can read back what a
     * prior save wrote, exercising the real state machine across calls.
     *
     * @var array<string, array>
     */
    private array $messages = [];


    /**
     * Set up test fixtures: real EventService/WebhookSignatureService/
     * listener wired together; ObjectService faked with a minimal in-memory
     * `event_message` store so state persists across the multi-step flow.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->messages      = [];
        $this->objectService = ObjectServiceMockBuilder::make($this);
        $this->clientService = $this->createMock(IClientService::class);
        $logger               = $this->createMock(LoggerInterface::class);

        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, string $register, string $schema, ?string $uuid=null) {
                if ($schema !== 'event_message') {
                    return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'obj-uuid');
                }

                $id                     = $uuid ?? ('msg-'.count($this->messages));
                $this->messages[$id]    = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, $id);
            }
        );
        $this->objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) {
                if ($schema === 'event_message' && isset($this->messages[$id]) === true) {
                    return ObjectServiceMockBuilder::objectEntity($this, $this->messages[$id], $id);
                }

                if ($schema === 'event_subscription') {
                    return ObjectServiceMockBuilder::objectEntity(
                        $this,
                        [
                            'style'            => 'push',
                            'sink'             => 'https://sink.example/hook',
                            'protocolSettings' => ['signingSecret' => 'whsec_integrationtest'],
                        ],
                        'sub-uuid'
                    );
                }

                return null;
            }
        );
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $filters = ($config['filters'] ?? []);
                if (($filters['schema'] ?? null) === 'event_subscription') {
                    $subscription = ObjectServiceMockBuilder::objectEntity(
                        $this,
                        [
                            'status'           => 'active',
                            'style'            => 'push',
                            'sink'             => 'https://sink.example/hook',
                            'protocolSettings' => ['signingSecret' => 'whsec_integrationtest'],
                        ],
                        'sub-uuid'
                    );
                    return ['results' => [$subscription], 'total' => 1];
                }

                if (($filters['schema'] ?? null) === 'event_message') {
                    $entities = [];
                    foreach ($this->messages as $id => $body) {
                        $entities[] = ObjectServiceMockBuilder::objectEntity($this, $body, $id);
                    }

                    return ['results' => $entities, 'total' => count($entities)];
                }

                return ['results' => [], 'total' => 0];
            }
        );

        $this->eventService = new EventService(
            $this->objectService,
            $this->clientService,
            $logger,
            new WebhookSignatureService($logger),
            $this->createMock(SynchronizationService::class),
            $this->createMock(JobService::class),
            $this->createMock(CallService::class),
            $this->createMock(FlowRunnerService::class)
        );

        $this->listener = new NextcloudFileEventListener($this->eventService, $logger);

    }//end setUp()


    /**
     * TC-27: firing a real NodeCreatedEvent through the listener produces an
     * outbound request carrying a verifiable X-OpenConnector-Signature
     * header over the exact CloudEvents payload bytes.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
     */
    public function testFileCreatedEventDeliversWithVerifiableSignature(): void
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(100);
        $node->method('getPath')->willReturn('/invoice.pdf');
        $node->method('getMimetype')->willReturn('application/pdf');
        $owner = $this->createMock(IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $node->method('getOwner')->willReturn($owner);

        $capturedHeaders = null;
        $capturedBody    = null;
        $response        = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('ok');
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(
            function (string $url, array $opts) use (&$capturedHeaders, &$capturedBody, $response) {
                $capturedHeaders = $opts['headers'];
                $capturedBody    = $opts['body'];
                return $response;
            }
        );
        $this->clientService->method('newClient')->willReturn($client);

        $this->listener->handle(new NodeCreatedEvent($node));

        $this->assertArrayHasKey('X-OpenConnector-Signature', $capturedHeaders);
        $header = $capturedHeaders['X-OpenConnector-Signature'];
        preg_match('/t=(\d+),v1=([0-9a-f]+)/', $header, $m);
        $expected = hash_hmac('sha256', $m[1].'.'.$capturedBody, 'whsec_integrationtest');
        $this->assertSame($expected, $m[2]);

        // Confirm the delivered event carries the correct CloudEvents shape.
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('com.nextcloud.files.node.created', $decoded['type']);
        $this->assertSame('/nextcloud/files', $decoded['source']);
    }//end testFileCreatedEventDeliversWithVerifiableSignature()


    /**
     * TC-28: the sink fails until `maxRetries` is exhausted (message reaches
     * `abandoned`, i.e. dead-lettered), then recovers; replaying the message
     * delivers it successfully.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003
     */
    public function testFullFailureDeadLetterReplayLoop(): void
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(101);
        $node->method('getPath')->willReturn('/report.pdf');
        $node->method('getMimetype')->willReturn('application/pdf');
        $node->method('getOwner')->willReturn(null);

        // The sink fails every attempt until told otherwise.
        $sinkHealthy = false;
        $client      = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(
            function () use (&$sinkHealthy) {
                $response = $this->createMock(IResponse::class);
                if ($sinkHealthy === true) {
                    $response->method('getStatusCode')->willReturn(200);
                    $response->method('getBody')->willReturn('ok');
                } else {
                    $response->method('getStatusCode')->willReturn(500);
                    $response->method('getBody')->willReturn('down');
                    $response->method('getHeader')->willReturn('');
                }

                return $response;
            }
        );
        $this->clientService->method('newClient')->willReturn($client);

        // First attempt (via the listener -> processEvent -> deliverMessage).
        $this->listener->handle(new NodeCreatedEvent($node));

        $messageId = array_key_first($this->messages);
        $this->assertSame('failed', $this->messages[$messageId]['status']);

        // Force nextAttempt into the past on every remaining sweep so
        // processRetries() doesn't have to sleep in real time for the test.
        $exhaustSweep = function () use ($messageId) {
            $this->messages[$messageId]['nextAttempt'] = (new \DateTime('-1 minute'))->format('c');
            $this->eventService->processRetries(5);
        };

        // 4 more failing attempts exhaust the default maxRetries=5.
        for ($i = 0; $i < 4; $i++) {
            $exhaustSweep();
        }

        $this->assertSame('abandoned', $this->messages[$messageId]['status']);

        // Sink recovers; replay delivers successfully.
        $sinkHealthy = true;
        $this->eventService->replayMessage($messageId, 'admin');

        $this->assertSame('delivered', $this->messages[$messageId]['status']);
        // attempts[] preserved across the whole campaign (5 failures + 1 success).
        $this->assertGreaterThanOrEqual(6, count($this->messages[$messageId]['attempts']));
    }//end testFullFailureDeadLetterReplayLoop()
}//end class
