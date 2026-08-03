<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\JobsController;
use OCA\OpenConnector\Http\StreamingRunResponse;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Covers the streaming branch on jobs#run and jobs#test (#1082).
 *
 * The synchronization endpoints are covered separately; these exist because the
 * spec requires all FOUR endpoints to stream through the one harness, and because
 * the job path differs in two ways worth pinning: `executeJob()` returns an
 * ObjectEntity rather than an array, and `run()`'s forceRun resolution was moved
 * above the streaming branch.
 *
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-covers-synchronization-and-job-run-test-endpoints
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-opt-in-streaming-selection-preserves-default-behaviour
 */
final class JobsControllerStreamingTest extends TestCase
{

    /**
     * @var JobService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $jobService;


    /**
     * Build a controller whose request exposes the given params/headers.
     *
     * @param array  $params The request params.
     * @param string $accept The Accept header.
     *
     * @return JobsController
     */
    private function controller(array $params=[], string $accept=''): JobsController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn(string $key, $default=null) => ($params[$key] ?? $default)
        );
        $request->method('getParams')->willReturn($params);
        $request->method('getHeader')->willReturnCallback(
            fn(string $name) => ($name === 'Accept') ? $accept : ''
        );

        $job = new ObjectEntity();
        $job->setUuid('job-uuid-1');
        $job->setObject(['name' => 'streamed-job']);

        $orObjectService = $this->createMock(OrObjectService::class);
        $orObjectService->method('find')->willReturn($job);

        $this->jobService = $this->createMock(JobService::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($this->createMock(IUser::class));

        $controller = new JobsController(
            'openconnector',
            $request,
            $orObjectService,
            $this->jobService,
            $l,
            $userSession,
            $this->createMock(ActionAuthService::class)
        );

        // Protect the buffers capture() owns; the production floor of 0 unwinds
        // PHPUnit's own and the streamed output would be lost.
        $floor = new ReflectionProperty($controller, 'streamBufferFloor');
        $floor->setAccessible(true);
        $floor->setValue($controller, ob_get_level() + 2);

        return $controller;
    }//end controller()


    /**
     * Capture streamed output. Two nested buffers, because the harness's
     * `ob_flush()` empties the innermost into its parent.
     *
     * @param callable $run The call to make.
     *
     * @return array{0: mixed, 1: string}
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
     * Build a job-log ObjectEntity, which is what executeJob() returns.
     *
     * @param array $data The log body.
     *
     * @return ObjectEntity
     */
    private function jobLog(array $data): ObjectEntity
    {
        $log = new ObjectEntity();
        $log->setUuid('joblog-1');
        $log->setObject($data);

        return $log;
    }//end jobLog()


    /**
     * No flag: jobs#run returns the same JSONResponse it always did, and writes
     * nothing to the output stream.
     *
     * @return void
     */
    public function testRunWithoutFlagReturnsJsonResponse(): void
    {
        $controller = $this->controller();
        $this->jobService->method('executeJob')->willReturn($this->jobLog(['level' => 'INFO']));

        [$response, $output] = $this->capture(fn() => $controller->run('job-uuid-1'));

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame('', $output, 'the default path must not write to the output stream');
    }//end testRunWithoutFlagReturnsJsonResponse()


    /**
     * No flag: jobs#test likewise unchanged.
     *
     * @return void
     */
    public function testTestWithoutFlagReturnsJsonResponse(): void
    {
        $controller = $this->controller();
        $this->jobService->method('executeJob')->willReturn($this->jobLog(['level' => 'INFO']));

        [$response, $output] = $this->capture(fn() => $controller->test('job-uuid-1'));

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame('', $output);
    }//end testTestWithoutFlagReturnsJsonResponse()


    /**
     * With the flag, jobs#run streams, and the final frame carries the serialized
     * job log — the same payload the JSONResponse branch returns.
     *
     * @return void
     */
    public function testRunWithFlagStreamsTheSerializedJobLog(): void
    {
        $controller = $this->controller(params: ['stream' => '1']);
        $this->jobService->method('executeJob')->willReturn($this->jobLog(['level' => 'INFO', 'message' => 'done']));

        [$response, $output] = $this->capture(fn() => $controller->run('job-uuid-1'));

        $this->assertInstanceOf(StreamingRunResponse::class, $response);
        $this->assertStringContainsString('event: open', $output);
        $this->assertStringContainsString('event: result', $output);
        $this->assertStringContainsString('done', $output);
    }//end testRunWithFlagStreamsTheSerializedJobLog()


    /**
     * The Accept header alone opts jobs#test into streaming.
     *
     * @return void
     */
    public function testTestWithAcceptHeaderStreams(): void
    {
        $controller = $this->controller(accept: 'text/event-stream');
        $this->jobService->method('executeJob')->willReturn($this->jobLog(['level' => 'INFO']));

        [$response, $output] = $this->capture(fn() => $controller->test('job-uuid-1'));

        $this->assertInstanceOf(StreamingRunResponse::class, $response);
        $this->assertStringContainsString('event: result', $output);
    }//end testTestWithAcceptHeaderStreams()


    /**
     * A null return from executeJob() still yields a result frame rather than
     * breaking the stream — the non-streaming branch returns `JSONResponse(null)`
     * for the same case, so the streamed equivalent must not throw.
     *
     * @return void
     */
    public function testNullJobResultStillProducesAResultFrame(): void
    {
        $controller = $this->controller(params: ['stream' => '1']);
        $this->jobService->method('executeJob')->willReturn(null);

        [$response, $output] = $this->capture(fn() => $controller->run('job-uuid-1'));

        $this->assertInstanceOf(StreamingRunResponse::class, $response);
        $this->assertStringContainsString('event: result', $output);
    }//end testNullJobResultStillProducesAResultFrame()


    /**
     * `forceRun` still reaches executeJob() on the streaming path.
     *
     * Worth pinning because its resolution was hoisted ABOVE the streaming branch
     * so both paths could share it — a refactor that could silently have left the
     * streamed path always passing false.
     *
     * @return void
     */
    public function testForceRunReachesTheEngineOnTheStreamingPath(): void
    {
        $controller = $this->controller(params: ['stream' => '1', 'forceRun' => 'true']);

        $seen = null;
        $this->jobService->method('executeJob')->willReturnCallback(
            function (...$args) use (&$seen) {
                $seen = $args;

                return null;
            }
        );

        $this->capture(fn() => $controller->run('job-uuid-1'));

        $this->assertNotNull($seen);
        $this->assertTrue($seen[1], 'forceRun must survive the hoist above the streaming branch');
    }//end testForceRunReachesTheEngineOnTheStreamingPath()


    /**
     * jobs#test always forces the run, streamed or not.
     *
     * @return void
     */
    public function testTestAlwaysForcesTheRunWhenStreaming(): void
    {
        $controller = $this->controller(params: ['stream' => '1']);

        $seen = null;
        $this->jobService->method('executeJob')->willReturnCallback(
            function (...$args) use (&$seen) {
                $seen = $args;

                return null;
            }
        );

        $this->capture(fn() => $controller->test('job-uuid-1'));

        $this->assertNotNull($seen);
        $this->assertTrue($seen[1], 'test must force the run');
    }//end testTestAlwaysForcesTheRunWhenStreaming()


    /**
     * A trace context reaches executeJob(), which is what turns each job step into
     * a live progress frame.
     *
     * @return void
     */
    public function testStreamedRunPassesATraceContext(): void
    {
        $controller = $this->controller(params: ['stream' => true]);

        $seenTrace = null;
        $this->jobService->method('executeJob')->willReturnCallback(
            function (...$args) use (&$seenTrace) {
                foreach ($args as $arg) {
                    if ($arg instanceof ExecutionTraceContext) {
                        $seenTrace = $arg;
                        $arg->addStep(type: 'job', name: 'step-1', timing: null, status: 'success');
                    }
                }

                return null;
            }
        );

        [, $output] = $this->capture(fn() => $controller->run('job-uuid-1'));

        $this->assertInstanceOf(ExecutionTraceContext::class, $seenTrace);
        $this->assertSame('job-uuid-1', $seenTrace->getEntryPointId());
        $this->assertStringContainsString('step-1', $output, 'a job step must reach the stream as a progress frame');
    }//end testStreamedRunPassesATraceContext()


    /**
     * An unauthenticated streaming request is rejected with a real 401 and no
     * streamed output — the branch sits after the auth checks.
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

        $controller = new JobsController(
            'openconnector',
            $request,
            $this->createMock(OrObjectService::class),
            $this->createMock(JobService::class),
            $l,
            $userSession,
            $this->createMock(ActionAuthService::class)
        );

        [$response, $output] = $this->capture(fn() => $controller->run('job-uuid-1'));

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(401, $response->getStatus());
        $this->assertSame('', $output, 'no streamed output may be produced for a rejected caller');
    }//end testUnauthenticatedStreamingRequestIsRejectedWithAStatusCode()
}//end class
