<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\SynchronizationsController;
use OCA\OpenConnector\Http\StreamingRunResponse;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the streaming branch on synchronizations#run and #test (#1082).
 *
 * The two things that matter here are opposite sides of the same guarantee: the
 * default request must be untouched (this is what makes the change safe for cron,
 * the scheduler and every API consumer), and the opt-in must actually switch
 * response type.
 *
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-opt-in-streaming-selection-preserves-default-behaviour
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-covers-synchronization-and-job-run-test-endpoints
 */
final class SynchronizationsControllerStreamingTest extends TestCase
{

    /**
     * @var SynchronizationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $synchronizationService;


    /**
     * Build a controller whose request exposes the given params/headers.
     *
     * @param array  $params The request params.
     * @param string $accept The Accept header.
     *
     * @return SynchronizationsController
     */
    private function controller(array $params=[], string $accept=''): SynchronizationsController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn(string $key, $default=null) => ($params[$key] ?? $default)
        );
        $request->method('getParams')->willReturn($params);
        $request->method('getHeader')->willReturnCallback(
            fn(string $name) => ($name === 'Accept') ? $accept : ''
        );

        $synchronization = new ObjectEntity();
        $synchronization->setUuid('sync-uuid-1');
        $synchronization->setObject(['name' => 'streamed-sync']);

        $orObjectService = $this->createMock(OrObjectService::class);
        $orObjectService->method('find')->willReturn($synchronization);

        $this->synchronizationService = $this->createMock(SynchronizationService::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($this->createMock(IUser::class));

        $controller = new SynchronizationsController(
            'openconnector',
            $request,
            $orObjectService,
            $this->synchronizationService,
            $l,
            $this->createMock(LoggerInterface::class),
            $userSession,
            $this->createMock(ActionAuthService::class)
        );

        $this->protectTestBuffers($controller);

        return $controller;
    }//end controller()


    /**
     * Stop beginStream() unwinding the buffers capture() relies on.
     *
     * The production floor of 0 tears down every buffer, PHPUnit's included, which
     * is right for a real request and destroys the test's ability to observe.
     *
     * @param object $controller The controller under test.
     *
     * @return void
     */
    private function protectTestBuffers(object $controller): void
    {
        $floor = new \ReflectionProperty($controller, 'streamBufferFloor');
        $floor->setAccessible(true);
        $floor->setValue($controller, ob_get_level() + 2);
    }//end protectTestBuffers()


    /**
     * Capture streamed output so it does not leak into the test report.
     *
     * Two nested buffers for the reason described in StreamsRunOutputTest: the
     * harness flushes the innermost buffer into its parent.
     *
     * @param callable $run The call to make.
     *
     * @return array{0: mixed, 1: string} The return value and everything written.
     */
    private function capture(callable $run): array
    {
        ob_start();
        ob_start();
        try {
            $value = $run();
        } finally {
            ob_end_clean();
        }

        return [$value, (string) ob_get_clean()];
    }//end capture()


    /**
     * No streaming flag: run() returns the same JSONResponse it always did, and
     * nothing is written to the output stream.
     *
     * @return void
     */
    public function testRunWithoutFlagReturnsJsonResponse(): void
    {
        $controller = $this->controller();
        $this->synchronizationService->method('synchronize')->willReturn(['objects' => ['created' => 3]]);

        [$response, $output] = $this->capture(fn() => $controller->run('sync-uuid-1'));

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(['objects' => ['created' => 3]], $response->getData());
        $this->assertSame('', $output, 'the default path must not write to the output stream');
    }//end testRunWithoutFlagReturnsJsonResponse()


    /**
     * No streaming flag: test() likewise unchanged.
     *
     * @return void
     */
    public function testTestWithoutFlagReturnsJsonResponse(): void
    {
        $controller = $this->controller();
        $this->synchronizationService->method('synchronize')->willReturn(['isValid' => true]);

        [$response, $output] = $this->capture(fn() => $controller->test('sync-uuid-1'));

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(['isValid' => true], $response->getData());
        $this->assertSame('', $output);
    }//end testTestWithoutFlagReturnsJsonResponse()


    /**
     * With the flag, run() streams: a StreamingRunResponse comes back and the SSE
     * frames have already been written.
     *
     * @return void
     */
    public function testRunWithFlagStreams(): void
    {
        $controller = $this->controller(params: ['stream' => '1']);
        $this->synchronizationService->method('synchronize')->willReturn(['objects' => ['created' => 1]]);

        [$response, $output] = $this->capture(fn() => $controller->run('sync-uuid-1'));

        $this->assertInstanceOf(StreamingRunResponse::class, $response);
        $this->assertStringContainsString('event: open', $output);
        $this->assertStringContainsString('event: result', $output);
        $this->assertStringContainsString('"created":1', $output);
    }//end testRunWithFlagStreams()


    /**
     * The Accept header alone is enough to opt test() into streaming.
     *
     * @return void
     */
    public function testTestWithAcceptHeaderStreams(): void
    {
        $controller = $this->controller(accept: 'text/event-stream');
        $this->synchronizationService->method('synchronize')->willReturn(['isValid' => true]);

        [$response, $output] = $this->capture(fn() => $controller->test('sync-uuid-1'));

        $this->assertInstanceOf(StreamingRunResponse::class, $response);
        $this->assertStringContainsString('event: result', $output);
    }//end testTestWithAcceptHeaderStreams()


    /**
     * A streamed run passes a trace context down into synchronize(), which is what
     * turns each step into a live progress frame.
     *
     * @return void
     */
    public function testStreamedRunPassesATraceContextToSynchronize(): void
    {
        $controller = $this->controller(params: ['stream' => true]);

        $seenTrace = null;
        $this->synchronizationService->method('synchronize')->willReturnCallback(
            function (...$args) use (&$seenTrace) {
                foreach ($args as $arg) {
                    if ($arg instanceof ExecutionTraceContext) {
                        $seenTrace = $arg;
                    }
                }

                return [];
            }
        );

        $this->capture(fn() => $controller->run('sync-uuid-1'));

        $this->assertInstanceOf(
            ExecutionTraceContext::class,
            $seenTrace,
            'without a trace context no progress frame can ever be emitted'
        );
        $this->assertSame('manual', $seenTrace->getEntryPoint());
        $this->assertSame('sync-uuid-1', $seenTrace->getEntryPointId());
    }//end testStreamedRunPassesATraceContextToSynchronize()


    /**
     * A throwing synchronize() during a streamed run becomes an `error` frame
     * rather than propagating — by then the 200 status is already on the wire.
     *
     * @return void
     */
    public function testStreamedRunReportsExceptionsAsErrorFrames(): void
    {
        $controller = $this->controller(params: ['stream' => '1']);
        $this->synchronizationService->method('synchronize')
            ->willThrowException(new \RuntimeException('upstream gave up'));

        [$response, $output] = $this->capture(fn() => $controller->run('sync-uuid-1'));

        $this->assertInstanceOf(StreamingRunResponse::class, $response);
        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('upstream gave up', $output);
    }//end testStreamedRunReportsExceptionsAsErrorFrames()


    /**
     * An unauthenticated caller is rejected with a real 401 even when streaming is
     * requested — the streaming branch sits after the auth checks, so opting in
     * cannot turn a rejection into a 200 stream containing an error frame.
     *
     * @return void
     */
    public function testUnauthenticatedStreamingRequestIsRejectedWithAStatusCode(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturn('1');
        $request->method('getParams')->willReturn(['stream' => '1']);
        $request->method('getHeader')->willReturn('text/event-stream');

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = new SynchronizationsController(
            'openconnector',
            $request,
            $this->createMock(OrObjectService::class),
            $this->createMock(SynchronizationService::class),
            $l,
            $this->createMock(LoggerInterface::class),
            $userSession,
            $this->createMock(ActionAuthService::class)
        );
        $this->protectTestBuffers($controller);

        [$response, $output] = $this->capture(fn() => $controller->run('sync-uuid-1'));

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(401, $response->getStatus());
        $this->assertSame('', $output, 'no streamed output may be produced for a rejected caller');
    }//end testUnauthenticatedStreamingRequestIsRejectedWithAStatusCode()
}//end class
