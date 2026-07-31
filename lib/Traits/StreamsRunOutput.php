<?php
/**
 * OpenConnector Streaming Run Output trait.
 *
 * The one place the SSE framing, buffer flushing, exception handling and
 * fatal-error capture for a streamed run/test live.
 *
 * @category Traits
 * @package  OCA\OpenConnector\Traits
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Traits;

use OCA\OpenConnector\Http\StreamingRunResponse;
use DateTime;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCP\IRequest;

/**
 * Shared streaming harness for the run/test endpoints (#1082).
 *
 * Used by both `SynchronizationsController` and `JobsController` so the awkward
 * parts — output-buffer teardown, flush semantics, shutdown-handler registration —
 * exist once and cannot drift between the four endpoints.
 *
 * WHY STREAMING EXISTS AT ALL, given that execution-trace already records an
 * ordered per-step timeline: a persisted trace cannot record the death of its own
 * process. An OOM, a script timeout or a parse error kills PHP before anything is
 * written, so the run-log shows a run that simply stops. Those deaths are usually
 * the thing being debugged, and the only way to see one is to have already sent the
 * bytes. Progress events therefore reuse the trace-step shape (one vocabulary, one
 * redaction pass) while `error` and `fatal` are streaming-only.
 *
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-surfaces-exceptions-and-fatal-errors
 */
trait StreamsRunOutput
{

    /**
     * True once the streaming headers have been emitted and the body has begun.
     *
     * @var boolean
     */
    private bool $streamStarted = false;

    /**
     * Lowest output-buffer level {@see beginStream()} will leave standing.
     *
     * Zero — unwind everything — is what a real request wants, and unwinding closes
     * buffers this code does not own. That is correct for a request deliberately
     * bypassing normal response rendering, and wrong for a caller that still needs
     * its own: a test harness capturing output has buffers of its own, and having
     * them torn out from under it makes the streaming branch unobservable.
     *
     * It is state rather than a parameter because the controllers must not have to
     * know or care about it — threading a test-only argument through every call
     * site would put test concerns in production signatures.
     *
     * @var integer
     */
    private int $streamBufferFloor = 0;

    /**
     * Whether the caller opted into streaming output.
     *
     * Three ways in, because the callers differ: the frontend console posts a body
     * parameter, a curl-wielding operator finds `?stream=1` easier, and a
     * standards-minded client sends the Accept header. Any of them is enough;
     * absent all three the endpoint keeps its existing `JSONResponse` behaviour
     * byte-for-byte, which is what makes this safe for cron and API consumers.
     *
     * @param IRequest $request The current request.
     *
     * @return boolean True when the response should stream.
     *
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-opt-in-streaming-selection-preserves-default-behaviour
     */
    private function wantsStreaming(IRequest $request): bool
    {
        foreach (['stream', 'follow'] as $key) {
            $value = $request->getParam($key);
            if ($value !== null && $this->isTruthyFlag(value: $value) === true) {
                return true;
            }
        }

        $accept = (string) $request->getHeader('Accept');
        if (str_contains($accept, 'text/event-stream') === true) {
            return true;
        }

        return false;

    }//end wantsStreaming()

    /**
     * Interpret a request flag that may arrive as a bool, int or string.
     *
     * A query string always yields strings, a JSON body can yield real booleans,
     * and `?stream=1` yields "1" — so `filter_var` with the boolean filter is used
     * rather than a truthiness check, which would treat the string "false" (a very
     * plausible thing for a client to send) as true.
     *
     * @param mixed $value The raw flag value.
     *
     * @return boolean
     */
    private function isTruthyFlag(mixed $value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;

    }//end isTruthyFlag()

    /**
     * Begin the stream: tear down output buffering and register fatal capture.
     *
     * Output buffering is unwound completely rather than merely flushed. PHP may
     * have several buffers stacked (php.ini `output_buffering`, plus anything the
     * server added), and a single `ob_end_flush()` only pops the innermost — the
     * rest keep holding the bytes back and the operator still sees nothing.
     *
     * `set_time_limit(0)` is what lets a run outlive `max_execution_time`. The
     * proxy timeout it exists to defeat is handled separately, by the fact that
     * bytes start arriving immediately.
     *
     * @return void
     *
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
     */
    private function beginStream(): void
    {
        if ($this->streamStarted === true) {
            return;
        }

        $this->streamStarted = true;

        // Unwind EVERY buffer above the floor, not just the innermost one.
        while (ob_get_level() > $this->streamBufferFloor) {
            ob_end_flush();
        }

        // A streamed run is deliberately long; the request must not be killed by
        // max_execution_time mid-run.
        set_time_limit(0);

        // Keep going even if the operator closes the console, so the run is not
        // half-applied by an abandoned browser tab.
        ignore_user_abort(true);

        $this->registerFatalCapture();

    }//end beginStream()

    /**
     * Stream a fatal error, if one killed the request, before the socket closes.
     *
     * This is the whole point of the feature. A `catch` block never runs for an
     * OOM, a script timeout or a parse error — the process is simply gone, and the
     * persisted run-log shows a run that stopped for no visible reason. A shutdown
     * function still runs, and `error_get_last()` still holds the reason, so the
     * last thing down the wire can be the cause of death.
     *
     * @return void
     *
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-surfaces-exceptions-and-fatal-errors
     */
    private function registerFatalCapture(): void
    {
        register_shutdown_function(
            function (): void {
                $error = error_get_last();
                if ($error === null) {
                    return;
                }

                // Only the error types that terminate the process. A stray notice
                // or deprecation is not a death and would be noise.
                $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
                if (in_array($error['type'], $fatalTypes, true) === false) {
                    return;
                }

                $this->emitEvent(
                    event: 'fatal',
                    data: [
                        'type'    => $this->describeErrorType(type: $error['type']),
                        'message' => $error['message'],
                        'file'    => $error['file'],
                        'line'    => $error['line'],
                    ]
                );
            }
        );

    }//end registerFatalCapture()

    /**
     * Human-readable name for a PHP error constant.
     *
     * The operator reading the console needs "E_ERROR", not "1" — and an OOM
     * versus a parse error points at very different causes.
     *
     * @param integer $type The PHP error type constant.
     *
     * @return string
     */
    private function describeErrorType(int $type): string
    {
        return match ($type) {
            E_ERROR         => 'E_ERROR',
            E_PARSE         => 'E_PARSE',
            E_CORE_ERROR    => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_USER_ERROR    => 'E_USER_ERROR',
            default         => 'E_UNKNOWN('.$type.')',
        };

    }//end describeErrorType()

    /**
     * Write one SSE frame and push it all the way to the client.
     *
     * `flush()` alone is not enough when a buffer has been re-established since
     * {@see beginStream()}, so the level is checked each time. The double newline
     * terminating the frame is required by the SSE grammar — without it the client
     * waits for more of the same event and renders nothing.
     *
     * @param string $event The event name: progress|result|error|fatal|open.
     * @param array  $data  The payload, JSON-encoded into the frame's data field.
     *
     * @return void
     *
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
     */
    private function emitEvent(string $event, array $data): void
    {
        $payload = json_encode($data, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        if ($payload === false) {
            $payload = '{"message":"event payload could not be encoded"}';
        }

        echo 'event: '.$event."\n";
        echo 'data: '.$payload."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();

    }//end emitEvent()

    /**
     * Run an operation in streaming mode and return the already-streamed response.
     *
     * The operation receives an {@see ExecutionTraceContext} with a live step
     * listener attached, so every `addStep()` inside the run becomes a `progress`
     * frame as it happens rather than a row in a trace nobody can read yet.
     *
     * Ordering matters here: the trace listener is detached in the `finally` before
     * returning, because the context may outlive this method (it is passed into
     * services) and a listener writing to a closed socket afterwards would be an
     * error in an unrelated code path.
     *
     * @param callable                   $operation Receives the ExecutionTraceContext, returns the result payload
     *                                              the non-streaming branch would have put in its JSONResponse.
     * @param ExecutionTraceContext|null $trace     The trace context to observe, when the caller has one.
     *
     * @return StreamingRunResponse
     *
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-streaming-surfaces-exceptions-and-fatal-errors
     */
    private function streamOperation(callable $operation, ?ExecutionTraceContext $trace=null): StreamingRunResponse
    {
        $this->beginStream();

        // An immediate frame, before any work starts. It gets the first bytes past
        // the proxy right away (so the connection is established well inside any
        // gateway timeout) and tells the console it is connected.
        $this->emitEvent(
            event: 'open',
            data: [
                'startedAt' => (new DateTime())->format(DateTime::ATOM),
                'traceId'   => ($trace?->getTraceId()),
            ]
        );

        if ($trace !== null) {
            $trace->setStepListener(
                function (array $step): void {
                    $this->emitEvent(event: 'progress', data: $step);
                }
            );
        }

        try {
            $result = $operation($trace);

            $this->emitEvent(event: 'result', data: ($result ?? []));
        } catch (\Throwable $exception) {
            // Streamed rather than rethrown: the status line is long gone, so an
            // exception is only useful to the operator as an event. The message and
            // trace are exactly what the persisted log tends to lose.
            $this->emitEvent(
                event: 'error',
                data: [
                    'class'   => get_class($exception),
                    'message' => $exception->getMessage(),
                    'file'    => $exception->getFile(),
                    'line'    => $exception->getLine(),
                    'trace'   => $exception->getTraceAsString(),
                ]
            );
        } finally {
            $trace?->setStepListener(null);
        }//end try

        return new StreamingRunResponse();

    }//end streamOperation()
}//end trait
