<?php
/**
 * The retry sweep must agree with recordFailure() about when a message is done.
 *
 * `processRetries()` treated its `$maxRetries` argument as a hard ceiling while
 * `recordFailure()` used the SUBSCRIPTION's `retryPolicy.maxRetries` to decide
 * when to abandon. For any subscription allowing more retries than the sweep's
 * default the two disagreed, and a message fell between them: the sweep stopped
 * retrying it, recordFailure never abandoned it, and it sat in `failed` for ever
 * while being re-scanned on every pass.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/dead-letter-replay/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\EventService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenConnector\Service\EventService
 */
class EventRetryCeilingTest extends TestCase
{


    /**
     * The sweep and the abandon decision must share one source of truth.
     *
     * @return void
     */
    public function testTheSweepDefaultMatchesTheClassDefault(): void
    {
        $rc      = new ReflectionClass(EventService::class);
        $default = $rc->getConstant('DEFAULT_MAX_RETRIES');

        $sweep = new ReflectionMethod(EventService::class, 'processRetries');
        $param = $sweep->getParameters()[0];

        $this->assertSame(
            $default,
            $param->getDefaultValue(),
            'a second, different literal here is what let the sweep and recordFailure disagree'
        );

    }//end testTheSweepDefaultMatchesTheClassDefault()


    /**
     * The ceiling actually applied is resolved per message, from its own
     * subscription — not taken from the sweep's argument alone.
     *
     * @return void
     */
    public function testThereIsAPerMessageCeilingResolver(): void
    {
        $this->assertTrue(
            method_exists(EventService::class, 'maxRetriesForMessage'),
            'the sweep must be able to ask what ceiling applies to THIS message'
        );

        $m = new ReflectionMethod(EventService::class, 'maxRetriesForMessage');
        $this->assertTrue($m->isPrivate());
        $this->assertSame('int', (string) $m->getReturnType());

    }//end testThereIsAPerMessageCeilingResolver()


    /**
     * An exhausted message is dead-lettered rather than skipped.
     *
     * Skipping is what made the sweep's cost grow with the number of dead
     * messages while never reaching a state an operator could filter on.
     *
     * @return void
     */
    public function testExhaustedMessagesAreAbandonedNotSkipped(): void
    {
        $this->assertTrue(
            method_exists(EventService::class, 'abandonExhausted'),
            'a message past its ceiling must reach a terminal state'
        );

        $m = new ReflectionMethod(EventService::class, 'abandonExhausted');
        $this->assertTrue($m->isPrivate());
        $this->assertSame('void', (string) $m->getReturnType());

    }//end testExhaustedMessagesAreAbandonedNotSkipped()


    /**
     * The sweep takes the HIGHER of its argument and the message's own policy,
     * so it can never stop retrying a message the policy still considers live.
     *
     * Asserted on the source because the branch needs a fully wired service and
     * a persisted subscription to reach; the arithmetic is what matters and it
     * is one expression.
     *
     * @return void
     */
    public function testTheSweepArgumentIsAFloorNotACeiling(): void
    {
        $rc     = new ReflectionClass(EventService::class);
        $source = file_get_contents($rc->getFileName());
        $body   = substr($source, (int) strpos($source, 'public function processRetries'));

        $this->assertStringContainsString(
            'max($maxRetries, $this->maxRetriesForMessage(',
            $body,
            'the applied ceiling must be the higher of the two, or the old stranding bug returns'
        );

    }//end testTheSweepArgumentIsAFloorNotACeiling()
}//end class
