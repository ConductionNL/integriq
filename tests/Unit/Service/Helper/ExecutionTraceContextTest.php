<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenConnector\Tests\Unit\Service\Helper;

use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use PHPUnit\Framework\TestCase;

/**
 * Covers execution-trace REQ-001 (traceId minted once, shared across steps)
 * and REQ-002 (ordered step buffer, durationMs computed from the step's own
 * start/end).
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
 */
final class ExecutionTraceContextTest extends TestCase
{


    /**
     * Constructing a context without an explicit traceId mints a fresh
     * UUIDv4, sets startedAt, and starts with an empty step buffer.
     *
     * @return void
     */
    public function testConstructorMintsFreshUuidAndEmptySteps(): void
    {
        $context = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'endpoint-1');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $context->getTraceId()
        );
        $this->assertSame('endpoint', $context->getEntryPoint());
        $this->assertSame('endpoint-1', $context->getEntryPointId());
        $this->assertNotEmpty($context->getStartedAt());
        $this->assertSame([], $context->getSteps());
    }//end testConstructorMintsFreshUuidAndEmptySteps()


    /**
     * Two independently-constructed contexts mint two DIFFERENT traceIds —
     * confirms mint-once-per-execution semantics (not a fixed/shared value).
     *
     * @return void
     */
    public function testEachContextMintsADistinctTraceId(): void
    {
        $a = new ExecutionTraceContext(entryPoint: 'endpoint');
        $b = new ExecutionTraceContext(entryPoint: 'endpoint');

        $this->assertNotSame($a->getTraceId(), $b->getTraceId());
    }//end testEachContextMintsADistinctTraceId()


    /**
     * An explicit traceId is reused verbatim (approval-resume rehydration,
     * design.md Decision 2) rather than minting a new one.
     *
     * @return void
     */
    public function testExplicitTraceIdIsReusedNotMinted(): void
    {
        $context = new ExecutionTraceContext(entryPoint: 'endpoint', traceId: 'fixed-trace-id');

        $this->assertSame('fixed-trace-id', $context->getTraceId());
    }//end testExplicitTraceIdIsReusedNotMinted()


    /**
     * addStep() appends steps with the next sequential `order`, sharing the
     * same traceId across every step (REQ-001's "carry it as a value object"
     * scenario — the traceId lives on the context, not per-step).
     *
     * @return void
     */
    public function testAddStepAssignsSequentialOrder(): void
    {
        $context = new ExecutionTraceContext(entryPoint: 'endpoint');

        $context->addStep(type: 'rule', name: 'first', timing: 'before', status: 'success');
        $context->addStep(type: 'mapping', name: 'second', timing: 'before', status: 'success');
        $context->addStep(type: 'call', name: 'third', timing: null, status: 'success');

        $steps = $context->getSteps();
        $this->assertCount(3, $steps);
        $this->assertSame(1, $steps[0]['order']);
        $this->assertSame(2, $steps[1]['order']);
        $this->assertSame(3, $steps[2]['order']);
        $this->assertSame('rule', $steps[0]['type']);
        $this->assertSame('mapping', $steps[1]['type']);
        $this->assertSame('call', $steps[2]['type']);
    }//end testAddStepAssignsSequentialOrder()


    /**
     * durationMs is computed from the step's own reported start/end
     * microtime — not from the context's own startedAt.
     *
     * @return void
     */
    public function testAddStepComputesDurationFromOwnStartEnd(): void
    {
        $context = new ExecutionTraceContext(entryPoint: 'sync');

        $start = 1000.000;
        $end   = 1000.250;
        $context->addStep(
            type: 'synchronization',
            name: 'item',
            timing: null,
            status: 'success',
            startedAtMicrotime: $start,
            finishedAtMicrotime: $end
        );

        $steps = $context->getSteps();
        $this->assertSame(250, $steps[0]['durationMs']);
    }//end testAddStepComputesDurationFromOwnStartEnd()


    /**
     * A step with no explicit start/end timing reports durationMs 0 — the
     * common case for rule/mapping steps whose caller does not track
     * sub-step timing precisely.
     *
     * @return void
     */
    public function testAddStepWithoutExplicitTimingReportsZeroDuration(): void
    {
        $context = new ExecutionTraceContext(entryPoint: 'endpoint');
        $context->addStep(type: 'rule', name: 'x', timing: 'before', status: 'skipped');

        $this->assertSame(0, $context->getSteps()[0]['durationMs']);
    }//end testAddStepWithoutExplicitTimingReportsZeroDuration()


    /**
     * addStep() never redacts — the caller MUST redact via
     * SensitiveFieldRegistry::redactArray() before calling addStep()
     * (design.md Decision 3); this documents the contract by proving the
     * context stores exactly the input/output arrays it is given.
     *
     * @return void
     */
    public function testAddStepStoresInputOutputVerbatimNeverRedacts(): void
    {
        $context = new ExecutionTraceContext(entryPoint: 'endpoint');

        $rawLookingInput  = ['headers' => ['authorization' => '***REDACTED***']];
        $rawLookingOutput = ['body' => ['ok' => true]];

        $context->addStep(type: 'rule', name: 'auth', timing: 'before', status: 'success', input: $rawLookingInput, output: $rawLookingOutput);

        $this->assertSame($rawLookingInput, $context->getSteps()[0]['input']);
        $this->assertSame($rawLookingOutput, $context->getSteps()[0]['output']);
    }//end testAddStepStoresInputOutputVerbatimNeverRedacts()


    /**
     * A replay context is constructed with `isReplay`/`dryRun`/`replayOf`
     * set, and MAY be pre-loaded with `priorSteps` — the approval-resume
     * rehydration case (design.md Decision 2) where `addStep()` must
     * continue the SAME ordered sequence, not restart at order 1.
     *
     * @return void
     */
    public function testPriorStepsSeedOrderContinuation(): void
    {
        $context = new ExecutionTraceContext(
            entryPoint: 'endpoint',
            traceId: 'orig-trace',
            replayOf: null,
            isReplay: false,
            dryRun: false,
            triggeredBy: 'http',
            priorSteps: [
                ['order' => 1, 'type' => 'rule', 'name' => 'before-1', 'timing' => 'before', 'status' => 'success', 'durationMs' => 0, 'startedAt' => 'x', 'input' => [], 'output' => []],
            ]
        );

        $context->addStep(type: 'rule', name: 'after-1', timing: 'after', status: 'success');

        $steps = $context->getSteps();
        $this->assertCount(2, $steps);
        $this->assertSame('before-1', $steps[0]['name']);
        $this->assertSame(2, $steps[1]['order']);
        $this->assertSame('after-1', $steps[1]['name']);
    }//end testPriorStepsSeedOrderContinuation()


    /**
     * Replay flags round-trip through the getters ExecutionTraceService
     * reads when persisting the record (REQ-005/REQ-006).
     *
     * @return void
     */
    public function testReplayFlagsAreExposed(): void
    {
        $context = new ExecutionTraceContext(
            entryPoint: 'sync',
            entryPointId: 'sync-1',
            replayOf: 'original-trace-id',
            isReplay: true,
            dryRun: true,
            triggeredBy: 'manual'
        );

        $this->assertSame('original-trace-id', $context->getReplayOf());
        $this->assertTrue($context->isReplay());
        $this->assertTrue($context->isDryRun());
        $this->assertSame('manual', $context->getTriggeredBy());
    }//end testReplayFlagsAreExposed()
}//end class
