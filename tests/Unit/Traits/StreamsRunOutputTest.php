<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenConnector\Tests\Unit\Traits;

use OCA\OpenConnector\Http\StreamingRunResponse;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Traits\StreamsRunOutput;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Host for the trait under test.
 *
 * The trait's members are private — deliberately, since they are controller
 * internals rather than API — so the tests reach them by reflection on this host
 * rather than by widening the real thing for testability.
 */
final class StreamsRunOutputHost
{
    use StreamsRunOutput;
}

/**
 * Covers the shared streaming harness (#1082).
 *
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-opt-in-streaming-selection-preserves-default-behaviour
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-surfaces-exceptions-and-fatal-errors
 */
final class StreamsRunOutputTest extends TestCase
{


    /**
     * Invoke a private trait method on a host instance.
     *
     * @param StreamsRunOutputHost $host   The host.
     * @param string               $method The method name.
     * @param array                $args   Positional arguments.
     *
     * @return mixed
     */
    private function invoke(StreamsRunOutputHost $host, string $method, array $args=[]): mixed
    {
        $m = new ReflectionMethod(StreamsRunOutputHost::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($host, $args);

    }//end invoke()


    /**
     * Capture what an emitting call writes.
     *
     * TWO nested buffers, which looks odd and is necessary. `emitEvent()` calls
     * `ob_flush()`, which pushes the innermost buffer's contents UP one level and
     * empties it — so with a single buffer of our own, `ob_get_clean()` would come
     * back empty and the assertion would silently pass on nothing. The inner buffer
     * absorbs the flush, the outer one collects what was flushed into it.
     *
     * @param callable $emitter Performs the emitting call.
     *
     * @return string Everything written.
     */
    private function capture(callable $emitter): string
    {
        ob_start();
        ob_start();

        try {
            $emitter();
        } finally {
            // The inner buffer is empty after emitEvent()'s ob_flush(); discard it.
            ob_end_clean();
        }

        return (string) ob_get_clean();

    }//end capture()


    /**
     * Build an IRequest double whose params and Accept header are controlled.
     *
     * @param array  $params The request params.
     * @param string $accept The Accept header value.
     *
     * @return IRequest
     */
    private function request(array $params=[], string $accept=''): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($params) {
                return ($params[$key] ?? $default);
            }
        );
        $request->method('getHeader')->willReturnCallback(
            function (string $name) use ($accept) {
                return ($name === 'Accept') ? $accept : '';
            }
        );

        return $request;

    }//end request()


    /**
     * No flag and no streaming Accept header means the endpoint keeps its existing
     * behaviour. This is the guarantee that makes the change safe for cron and
     * every API consumer.
     *
     * @return void
     */
    public function testDefaultRequestDoesNotOptIntoStreaming(): void
    {
        $host = new StreamsRunOutputHost();

        $this->assertFalse($this->invoke($host, 'wantsStreaming', [$this->request()]));
        $this->assertFalse(
            $this->invoke($host, 'wantsStreaming', [$this->request(accept: 'application/json')])
        );
    }//end testDefaultRequestDoesNotOptIntoStreaming()


    /**
     * All three opt-in routes work: body param, `?stream=1`-style string, and the
     * Accept header. `follow` is accepted as a synonym of `stream`.
     *
     * @return void
     */
    public function testEachOptInRouteSelectsStreaming(): void
    {
        $host = new StreamsRunOutputHost();

        $this->assertTrue($this->invoke($host, 'wantsStreaming', [$this->request(['stream' => true])]));
        $this->assertTrue($this->invoke($host, 'wantsStreaming', [$this->request(['stream' => '1'])]));
        $this->assertTrue($this->invoke($host, 'wantsStreaming', [$this->request(['follow' => 'true'])]));
        $this->assertTrue(
            $this->invoke($host, 'wantsStreaming', [$this->request(accept: 'text/event-stream')])
        );
    }//end testEachOptInRouteSelectsStreaming()


    /**
     * A literal "false" must NOT opt in.
     *
     * This is why the flag goes through FILTER_VALIDATE_BOOLEAN rather than a
     * truthiness check: `stream=false` is a very plausible thing for a client to
     * send, and as a non-empty string it is truthy in PHP. Getting this wrong would
     * silently stream to a caller that explicitly asked not to.
     *
     * @return void
     */
    public function testExplicitFalseDoesNotOptIn(): void
    {
        $host = new StreamsRunOutputHost();

        $this->assertFalse($this->invoke($host, 'wantsStreaming', [$this->request(['stream' => 'false'])]));
        $this->assertFalse($this->invoke($host, 'wantsStreaming', [$this->request(['stream' => '0'])]));
        $this->assertFalse($this->invoke($host, 'wantsStreaming', [$this->request(['stream' => false])]));
    }//end testExplicitFalseDoesNotOptIn()


    /**
     * An emitted frame is valid SSE: an `event:` line, a `data:` line carrying
     * JSON, and the blank line that terminates the frame. Without that terminator
     * the client waits for more of the same event and renders nothing.
     *
     * @return void
     */
    public function testEmitEventWritesAValidSseFrame(): void
    {
        $host = new StreamsRunOutputHost();

        $frame = $this->capture(
            fn() => $this->invoke($host, 'emitEvent', ['progress', ['name' => 'step-one', 'order' => 1]])
        );

        $this->assertStringStartsWith("event: progress\n", $frame);
        $this->assertStringEndsWith("\n\n", $frame, 'the frame must be terminated by a blank line');

        $this->assertSame(1, preg_match('/^data: (.*)$/m', $frame, $m));
        $this->assertSame(['name' => 'step-one', 'order' => 1], json_decode($m[1], true));
    }//end testEmitEventWritesAValidSseFrame()


    /**
     * A payload that cannot be JSON-encoded still produces a well-formed frame
     * rather than a broken one or an exception. A debugging tool that dies on
     * unexpected data is worse than useless.
     *
     * @return void
     */
    public function testUnencodablePayloadStillProducesAFrame(): void
    {
        $host = new StreamsRunOutputHost();

        // An invalid UTF-8 byte sequence: json_encode cannot represent it.
        $frame = $this->capture(
            fn() => $this->invoke($host, 'emitEvent', ['progress', ['bad' => "\xB1\x31"]])
        );

        $this->assertStringStartsWith("event: progress\n", $frame);
        $this->assertStringEndsWith("\n\n", $frame);
    }//end testUnencodablePayloadStillProducesAFrame()


    /**
     * A successful streamed operation emits `open`, then the trace's steps as
     * `progress` frames as they happen, then `result` carrying the same payload the
     * non-streaming branch would have returned.
     *
     * The progress frames arriving between open and result is the substance of the
     * feature: it demonstrates steps are flushed DURING the run, not collected and
     * dumped at the end.
     *
     * @return void
     */
    public function testStreamOperationEmitsOpenProgressAndResult(): void
    {
        $host  = new StreamsRunOutputHost();
        $trace = new ExecutionTraceContext(entryPoint: 'manual');

        $output = $this->capture(
            function () use ($host, $trace) {
                return $this->invoke(
                    $host,
                    'streamOperation',
                    [
                        function (?ExecutionTraceContext $t) {
                            $t->addStep(type: 'synchronization', name: 'item-1', timing: null, status: 'success');
                            $t->addStep(type: 'synchronization', name: 'item-2', timing: null, status: 'success');

                            return ['objects' => ['created' => 2]];
                        },
                        $trace,
                        ob_get_level(),
                    ]
                );
            }
        );

        preg_match_all('/^event: (\w+)$/m', $output, $events);
        $this->assertSame(['open', 'progress', 'progress', 'result'], $events[1]);

        $this->assertStringContainsString('"name":"item-1"', $output);
        $this->assertStringContainsString('"created":2', $output);
    }//end testStreamOperationEmitsOpenProgressAndResult()


    /**
     * A throwing operation is reported as an `error` event, not rethrown.
     *
     * Once the first byte is flushed the status line is gone, so an exception can
     * only reach the operator as an event. Rethrowing would produce a mangled
     * half-streamed response and lose the message.
     *
     * @return void
     */
    public function testThrowingOperationIsStreamedAsAnErrorEvent(): void
    {
        $host  = new StreamsRunOutputHost();
        $trace = new ExecutionTraceContext(entryPoint: 'manual');

        $output = $this->capture(
            function () use ($host, $trace) {
                return $this->invoke(
                    $host,
                    'streamOperation',
                    [
                        function (?ExecutionTraceContext $t) {
                            throw new \RuntimeException('source refused the connection');
                        },
                        $trace,
                        ob_get_level(),
                    ]
                );
            }
        );

        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('source refused the connection', $output);
        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringNotContainsString('event: result', $output, 'a failed run has no result frame');
    }//end testThrowingOperationIsStreamedAsAnErrorEvent()


    /**
     * The listener is detached once the operation finishes.
     *
     * The trace context is passed into services and can outlive the request; a
     * listener still writing to a closed socket afterwards would raise errors in an
     * unrelated code path. Verified by adding a step after the run and confirming
     * nothing more is written.
     *
     * @return void
     */
    public function testListenerIsDetachedAfterTheOperation(): void
    {
        $host  = new StreamsRunOutputHost();
        $trace = new ExecutionTraceContext(entryPoint: 'manual');

        $this->capture(
            function () use ($host, $trace) {
                return $this->invoke(
                    $host,
                    'streamOperation',
                    [fn(?ExecutionTraceContext $t) => [], $trace, ob_get_level()]
                );
            }
        );

        $after = $this->capture(
            function () use ($trace) {
                $trace->addStep(type: 'call', name: 'after-the-run', timing: null, status: 'success');
            }
        );

        $this->assertSame('', $after, 'no frame may be written once the operation has returned');
    }//end testListenerIsDetachedAfterTheOperation()


    /**
     * The harness returns a response that renders nothing, so the AppFramework
     * dispatcher cannot append to a stream the client already considers finished.
     *
     * @return void
     */
    public function testStreamOperationReturnsANonRenderingResponse(): void
    {
        $host     = new StreamsRunOutputHost();
        $response = null;

        $this->capture(
            function () use ($host, &$response) {
                $response = $this->invoke(
                    $host,
                    'streamOperation',
                    [fn(?ExecutionTraceContext $t) => ['ok' => true], null, ob_get_level()]
                );
            }
        );

        $this->assertInstanceOf(StreamingRunResponse::class, $response);
        $this->assertSame('', $response->render());
    }//end testStreamOperationReturnsANonRenderingResponse()


    /**
     * The harness works without a trace context — progress frames simply do not
     * appear, and open/result still do. Job endpoints may not have one.
     *
     * @return void
     */
    public function testStreamOperationWorksWithoutATraceContext(): void
    {
        $host = new StreamsRunOutputHost();

        $output = $this->capture(
            function () use ($host) {
                return $this->invoke(
                    $host,
                    'streamOperation',
                    [fn(?ExecutionTraceContext $t) => ['done' => true], null, ob_get_level()]
                );
            }
        );

        preg_match_all('/^event: (\w+)$/m', $output, $events);
        $this->assertSame(['open', 'result'], $events[1]);
    }//end testStreamOperationWorksWithoutATraceContext()
}//end class
