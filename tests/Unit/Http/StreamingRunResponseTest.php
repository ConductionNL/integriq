<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenConnector\Tests\Unit\Http;

use OCA\OpenConnector\Http\StreamingRunResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Covers the response returned by an already-streamed run (#1082).
 *
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
 */
final class StreamingRunResponseTest extends TestCase
{


    /**
     * render() must return an empty string.
     *
     * The AppFramework dispatcher renders a Response after the controller returns
     * and echoes the result. The body has already been streamed and the client
     * considers it finished, so anything rendered here is appended as trailing
     * garbage after the final event. This is the spec's "response does not
     * double-render" scenario.
     *
     * @return void
     */
    public function testRenderIsEmptySoTheDispatcherCannotDoubleRender(): void
    {
        $this->assertSame('', (new StreamingRunResponse())->render());
    }//end testRenderIsEmptySoTheDispatcherCannotDoubleRender()


    /**
     * The SSE headers are set, including the one that actually decides whether the
     * feature works.
     *
     * `X-Accel-Buffering: no` is load-bearing: without it nginx buffers the whole
     * response and the operator sees nothing until the run ends, which is precisely
     * the failure this change exists to avoid. A caching intermediary would be just
     * as fatal, hence no-store on Cache-Control.
     *
     * @return void
     */
    public function testStreamingHeadersAreSet(): void
    {
        // Read the raw header bag rather than calling getHeaders(), which resolves
        // IRequest from \OC::$server to merge in X-Request-Id and CSP — unavailable
        // in the standalone stub environment. addHeader() writes straight to this
        // property, so it is exactly what this class contributes.
        $property = new ReflectionProperty(Response::class, 'headers');
        $property->setAccessible(true);
        $headers = $property->getValue(new StreamingRunResponse());

        $this->assertSame('text/event-stream; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('no', $headers['X-Accel-Buffering']);
        $this->assertStringContainsString('no-cache', $headers['Cache-Control']);
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
    }//end testStreamingHeadersAreSet()


    /**
     * Status defaults to 200 and is overridable.
     *
     * Overriding is only meaningful before the first byte is flushed — after that
     * the status line is already on the wire, which is why failures during a run are
     * reported as `error`/`fatal` events instead.
     *
     * @return void
     */
    public function testStatusDefaultsToOkAndIsOverridable(): void
    {
        $this->assertSame(Http::STATUS_OK, (new StreamingRunResponse())->getStatus());
        $this->assertSame(
            Http::STATUS_INTERNAL_SERVER_ERROR,
            (new StreamingRunResponse(status: Http::STATUS_INTERNAL_SERVER_ERROR))->getStatus()
        );
    }//end testStatusDefaultsToOkAndIsOverridable()
}//end class
