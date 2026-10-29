<?php

/**
 * Contract of the CallService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use UnexpectedValueException;

/**
 * Public operations of {@see CallService} that other components call.
 */
interface CallServiceInterface {
	/**
	 * Render a Source-relative upstream path from an inbound request context,
	 * then refuse it if the RESULT escapes the Source (ocon#1069).
	 *
	 * THE DEFECT
	 * ----------
	 * `EndpointService::handleSourceRequest()` hands the endpoint's authored
	 * `endpoint` string straight to {@see call()}, which concatenates it onto
	 * `source.location` with no substitution at all — Twig runs over
	 * `configuration` (see {@see renderConfiguration()}) and never over the
	 * path. An endpoint whose path is `/repos/{owner}/{repo}/issues` therefore
	 * sent the braces literally upstream, so a `targetType: api` endpoint could
	 * not parameterise its upstream path from the inbound request at all.
	 *
	 * WHY A SEPARATE TWIG ENVIRONMENT
	 * -------------------------------
	 * The engine is the same; two properties deliberately are not.
	 *
	 *  - `strict_variables` is ON. A placeholder the inbound request does not
	 *    satisfy is a failure, not an empty string: silently collapsing
	 *    `/repos/{{owner}}/issues` to `/repos//issues` would dispatch a
	 *    DIFFERENT upstream request and report success.
	 *  - The authentication functions (`oauthToken`, `jwtToken`, …) and the
	 *    `source` context are NOT exposed. `configuration` may carry a secret
	 *    because it is redacted before it reaches a CallLog; a URL is persisted
	 *    to `call_log.request.url` verbatim, so a secret that reaches the path
	 *    is a secret written to the log.
	 *
	 * SSRF CONTAINMENT
	 * ----------------
	 * Two independent layers, because templating means the effective path is
	 * only knowable at execute time:
	 *
	 *  1. Every substituted value is `rawurlencode()`d BEFORE rendering, so an
	 *     inbound value cannot contribute a `/`, `?`, `#` or a control
	 *     character to the path — only the authored literal can.
	 *  2. The FINAL rendered value is put through
	 *     {@see FlowConfigGuard::endpointEscapeCode()} — the app's single
	 *     containment predicate, the same rules `SourceCallNode` applies to a
	 *     rendered flow endpoint — plus an empty-segment check. An absolute
	 *     URL, a scheme-relative `//host`, a `../` traversal or a control
	 *     character is refused before any request is made.
	 *
	 * @param string $endpoint The authored, Source-relative path template.
	 * @param array $context The template context (inbound path/query/body values).
	 *
	 * @return string The rendered, containment-checked path.
	 *
	 * @throws UnexpectedValueException When a placeholder is unsatisfied, when the template
	 *                                  is unparseable, or when the rendered path escapes the
	 *                                  Source location.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function renderEndpointPath(string $endpoint, array $context): string;

	/**
	 * Writes the certificate and ssl keys to disk, returns the filenames.
	 *
	 * @param array $config The configuration as stored in the source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function getCertificate(array &$config);

	/**
	 * Removes certificates and private keys from disk if they are not necessary anymore.
	 *
	 * @param array $config The configuration with filenames.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function removeFiles(array $config): void;

	/**
	 * Buffer call logs instead of writing one per call, for the duration of a run.
	 *
	 * A paginated fetch makes one call per page and writes one CallLog per call.
	 * Even stripped of RBAC, audit and validation that save costs ~55 ms, and it
	 * is SERIAL inside the page fan-out — which is why widening the concurrency
	 * window barely moved the per-page number.
	 *
	 * Buffered, the caller still gets a CallLog carrying the full request and
	 * response (the same object the interactive test path has always returned);
	 * it simply has no uuid until the flush. That is the one behavioural
	 * difference, and it is why this is OPT-IN per run rather than the default:
	 * a caller that needs the persisted row's identity immediately must not
	 * enable it.
	 *
	 * @param bool $enabled Whether to buffer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function bufferCallLogs(bool $enabled): void;

	/**
	 * Write every buffered call log in one bulk save.
	 *
	 * MUST be called in a `finally` by whoever enabled buffering — a run that
	 * throws still made the calls, and losing their logs would make the failure
	 * harder to diagnose than not buffering at all.
	 *
	 * Failure to write logs must never fail the work they describe, so this
	 * swallows and reports: the calls succeeded, and a lost log is a lost log.
	 *
	 * @return int How many logs were written.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function flushCallLogs(): int;

	/**
	 * Manually trips the circuit breaker for a Source, regardless of its
	 * prior state or failure count (REQ-009). Sets `circuitBreakerState =
	 * 'open'`, `circuitBreakerOpenedAt = now()`, and
	 * `circuitBreakerFailureCount = circuitBreakerThreshold`, so the breaker
	 * reads as open through the normal {@see guardCircuitBreaker()} path on
	 * the very next dispatch.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to trip.
	 *
	 * @return ObjectEntity The saved source ObjectEntity.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
	 */
	public function tripCircuitBreaker(ObjectEntity $source): ObjectEntity;

	/**
	 * Manually resets the circuit breaker for a Source (REQ-009). Sets
	 * `circuitBreakerState = 'closed'`, `circuitBreakerFailureCount = 0`,
	 * `circuitBreakerOpenedAt = null` — the next dispatch proceeds normally.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to reset.
	 *
	 * @return ObjectEntity The saved source ObjectEntity.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
	 */
	public function resetCircuitBreaker(ObjectEntity $source): ObjectEntity;

	/**
	 * Calls a source according to given configuration.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to call.
	 * @param string $endpoint The endpoint on the source to call.
	 * @param string $method The method on which to call the source.
	 * @param array $config The additional configuration to call the source.
	 * @param boolean $asynchronous Whether to call the source asynchronously.
	 * @param boolean $createCertificates Whether to create certificates for this source.
	 * @param boolean $overruleAuth Whether to overrule the source authentication.
	 * @param boolean $read Whether this is a singular read (vs list) call.
	 * @param boolean $runningSupportRequest Internal flag set when invoked from preRequest/postRequest hooks.
	 * @param mixed $sink Optional stream resource. When given, the response body is streamed
	 *                    into it (Guzzle `sink` option) instead of buffered, and the CallLog
	 *                    records an empty body (stream-file-content #110). Guzzle HTTP path
	 *                    only. Default null = unchanged behaviour for every existing caller.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, when this call is
	 *                                          made from within a traced execution (execution-trace
	 *                                          REQ-001). When present, `call_log.sessionId` is set to
	 *                                          `$trace->getTraceId()` and a `call` step is appended
	 *                                          to the trace using the already-redacted request/
	 *                                          response data — no second redaction pass.
	 * @param boolean $persistLog When false (interactive "Test connection"), the response
	 *                            is returned without writing a CallLog or mutating the
	 *                            source — no heavy object save, no test-noise log rows.
	 * @param boolean $withholdBodies When true, the call is recorded without any body: the record
	 *                                names the source, the user, the moment and the outcome, and
	 *                                holds no request body, no response body, no headers, no query
	 *                                and no replay request. It wins over `logBody` and over an open
	 *                                investigation window. The caller still gets the whole answer.
	 *
	 * @return ObjectEntity
	 *
	 * @throws GuzzleException On HTTP transport failure.
	 * @throws LoaderError On Twig loader error.
	 * @throws SyntaxError On Twig syntax error.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-post-body-sources-and-body-based-pagination-req-010
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call-log-sessionid-req-011
	 */
	public function call(
		ObjectEntity $source,
		string $endpoint = '',
		string $method = 'GET',
		array $config = [],
		bool $asynchronous = false,
		bool $createCertificates = true,
		bool $overruleAuth = false,
		bool $read = false,
		bool $runningSupportRequest = false,
		mixed $sink = null,
		?ExecutionTraceContext $trace = null,
		bool $persistLog = true,
		bool $withholdBodies = false,
	): ObjectEntity;

	/**
	 * Asynchronous sibling of {@see call()}: dispatches one outbound request and
	 * returns a Guzzle promise that resolves to the same `CallLog` ObjectEntity
	 * the synchronous path returns (ocon#111 Task 0).
	 *
	 * Why a sibling rather than a widened `call()`: `call()` is the app's central
	 * HTTP surface with ~30 call sites (`SourceCallNode`, `PingAction`,
	 * `NotuBizConnectorService`, `IBabsConnectorService`, `EventService`,
	 * `PromotionService`, `SynchronizationService`, …) that all rely on the
	 * `ObjectEntity` return. An `ObjectEntity|PromiseInterface` union would ripple
	 * through static analysis at every one of them for no behavioural gain. See
	 * `openspec/changes/archive/2026-09-29-parallel-file-fetch/design.md` → "Sibling async methods,
	 * not union returns".
	 *
	 * ONE consumed shape. The promise always resolves to a persisted `CallLog`
	 * entity — including for a short-circuit (source disabled, rate-limited,
	 * breaker open, credential misconfiguration), where the synchronous path also
	 * returns a synthetic CallLog carrying the 409/429/503 status. Callers check
	 * the logged status code exactly as they already do for `call()`; a rejection
	 * means a genuine transport failure, never a guard outcome.
	 *
	 * TWO deliberate differences from `call()`, both consequences of not blocking:
	 *  - **No retry loop.** `dispatchWithRetry()` sleeps between attempts
	 *    ({@see sleepRetryBackoff()}), which would stall the shared curl-multi
	 *    event loop and serialize the very requests this method exists to overlap.
	 *    Asynchronous dispatch is therefore single-attempt; a caller needing
	 *    retries must re-dispatch. Circuit-breaker bookkeeping (REQ-008) is still
	 *    recorded from the promise callbacks, so breaker state does not diverge.
	 *  - **Persistence happens at settle time**, inside `then()`. The CallLog row
	 *    is written when the response arrives, not when this method returns.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to call.
	 * @param string $endpoint The endpoint on the source to call.
	 * @param string $method The method on which to call the source.
	 * @param array $config The additional configuration to call the source.
	 * @param boolean $overruleAuth Whether to overrule the source authentication.
	 * @param boolean $read Whether this is a singular read (vs list) call.
	 * @param boolean $runningSupportRequest Internal flag set when invoked from preRequest/postRequest hooks.
	 * @param mixed $sink Optional temp-file PATH. When given, the response body is
	 *                    streamed into that file (Guzzle `sink` option) instead of
	 *                    buffered, and the CallLog records an empty body. MUST be a
	 *                    path, never a stream resource — Guzzle wraps a resource sink
	 *                    in a PSR-7 Stream and closes it on destruct, handing the
	 *                    caller back a closed handle (stream-file-content #110).
	 * @param ExecutionTraceContext|null $trace The active execution trace context, when any.
	 * @param callable|null $onHeaders Optional callback invoked with the PSR-7 response once its
	 *                                 headers have arrived and before the body downloads. The
	 *                                 concurrent file fetcher uses it to read `Content-Length`
	 *                                 for its in-flight byte budget (ocon#111).
	 * @param boolean $persistLog When false, a transient unsaved CallLog is returned and
	 *                            nothing is mutated — mirrors call()'s flag so the two
	 *                            siblings cannot diverge on logging behaviour.
	 *
	 * @return PromiseInterface A promise resolving to the persisted CallLog ObjectEntity.
	 *
	 * @throws LoaderError On Twig loader error during preparation.
	 * @throws SyntaxError On Twig syntax error during preparation.
	 * @throws \OCP\DB\Exception On persistence failure of a synthetic CallLog during preparation.
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	public function callAsync(
		ObjectEntity $source,
		string $endpoint = '',
		string $method = 'GET',
		array $config = [],
		bool $overruleAuth = false,
		bool $read = false,
		bool $runningSupportRequest = false,
		mixed $sink = null,
		?ExecutionTraceContext $trace = null,
		?callable $onHeaders = null,
		bool $persistLog = true,
	): PromiseInterface;

	/**
	 * Uses Adbar Dot to place the values of keys with a dot in it in the $config array
	 * to the correct position in the then updated multidimensional $config array.
	 *
	 * @param array $config The config array.
	 *
	 * @return array The updated config array.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function applyConfigDot(array $config): array;
}
