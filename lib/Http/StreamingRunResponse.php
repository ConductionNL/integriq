<?php
/**
 * OpenConnector Streaming Run Response.
 *
 * A Response whose body has already been written to the output stream by the
 * time the AppFramework dispatcher gets hold of it.
 *
 * @category Http
 * @package  OCA\OpenConnector\Http
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

namespace OCA\OpenConnector\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

/**
 * Response for an operation that streamed its own output (#1082).
 *
 * THE PROBLEM THIS SOLVES. The AppFramework dispatcher renders a `Response` after
 * the controller returns and echoes the result. That is fine for a buffered
 * payload and fatal for a streamed one: the controller has already written and
 * flushed its events to the client, so anything the dispatcher renders afterwards
 * is appended to a stream the client considers finished — duplicate or trailing
 * garbage after the final event.
 *
 * So {@see render()} returns an empty string. The status and headers still travel
 * through the normal Nextcloud response path — which matters, because it is what
 * keeps the auth posture and middleware stack identical to the non-streaming
 * branch (spec: "Streaming preserves the existing authentication posture") — while
 * the body contributes nothing, having already been sent.
 *
 * Headers are set here rather than in the controller so every streaming endpoint
 * gets the same three, in particular `X-Accel-Buffering: no`. Without that last
 * one nginx buffers the whole response and the operator sees nothing until the run
 * ends, which defeats the entire feature.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 *
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
 */
class StreamingRunResponse extends Response
{
    /**
     * Construct a response for an already-streamed body.
     *
     * @param integer $status HTTP status; defaults to 200. A streaming response
     *                        cannot change its status once the first byte has been
     *                        flushed, so failures are reported as `error`/`fatal`
     *                        EVENTS inside the stream rather than as a status code.
     */
    public function __construct(int $status=Http::STATUS_OK)
    {
        parent::__construct();

        $this->setStatus(status: $status);

        // Server-Sent Events framing.
        $this->addHeader(name: 'Content-Type', value: 'text/event-stream; charset=utf-8');

        // Neither the browser nor any intermediary may cache a live stream.
        $this->addHeader(name: 'Cache-Control', value: 'no-cache, no-store, must-revalidate');
        $this->addHeader(name: 'Pragma', value: 'no-cache');

        // The load-bearing header: without it nginx (and other reverse proxies)
        // buffer the response and nothing reaches the operator until the run has
        // finished — the exact failure this feature exists to avoid.
        $this->addHeader(name: 'X-Accel-Buffering', value: 'no');

        // Keep the connection from being reused for a subsequent request, since
        // the body length is not known up front.
        $this->addHeader(name: 'Connection', value: 'close');

    }//end __construct()

    /**
     * Render nothing: the body was streamed before this response existed.
     *
     * @return string Always an empty string.
     *
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
     */
    public function render(): string
    {
        return '';

    }//end render()
}//end class
