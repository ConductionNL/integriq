<?php
/**
 * The cycle guard shared by both synchronization-chaining mechanisms.
 *
 * OpenConnector chains synchronizations two ways — a `synchronization` rule and
 * a `followUps` entry — and both re-enter `synchronize()` on the same service.
 * Neither had a guard, so A -> B -> A recursed until the process died. These
 * tests pin the guard's behaviour without standing up the whole service graph:
 * the stack itself is the unit under test, since that is what both call sites
 * consult.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\SynchronizationService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @covers \OCA\OpenConnector\Service\SynchronizationService
 */
class SynchronizationChainGuardTest extends TestCase
{


    /**
     * Read the shared chain stack.
     *
     * @return array<int, string> The stack.
     */
    private function stack(): array
    {
        $p = new ReflectionProperty(SynchronizationService::class, 'syncChainStack');
        $p->setAccessible(true);
        return $p->getValue();

    }//end stack()


    /**
     * Replace the shared chain stack.
     *
     * @param array<int, string> $value The stack contents.
     *
     * @return void
     */
    private function setStack(array $value): void
    {
        $p = new ReflectionProperty(SynchronizationService::class, 'syncChainStack');
        $p->setAccessible(true);
        $p->setValue(null, $value);

    }//end setStack()


    /**
     * Always leave the stack as we found it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->setStack([]);
        parent::tearDown();

    }//end tearDown()


    /**
     * The guard exists at all — a static, shared stack.
     *
     * @return void
     */
    public function testTheChainStackExistsAndIsStatic(): void
    {
        $p = new ReflectionProperty(SynchronizationService::class, 'syncChainStack');

        $this->assertTrue($p->isStatic(), 'the recursion runs through one shared service instance');
        $this->assertTrue($p->isPrivate(), 'the stack is an implementation detail');

    }//end testTheChainStackExistsAndIsStatic()


    /**
     * It starts empty, so a first synchronization is never mistaken for a cycle.
     *
     * @return void
     */
    public function testItStartsEmpty(): void
    {
        $this->setStack([]);

        $this->assertSame([], $this->stack());

    }//end testItStartsEmpty()


    /**
     * A synchronization already on the stack is recognised as a cycle.
     *
     * This is the A -> B -> A case that previously recursed without bound.
     *
     * @return void
     */
    public function testASynchronizationAlreadyOnTheStackIsACycle(): void
    {
        $this->setStack(['sync-a', 'sync-b']);

        $this->assertTrue(in_array('sync-a', $this->stack(), true), 'the outer sync is still on the stack');
        $this->assertFalse(in_array('sync-c', $this->stack(), true), 'an unrelated sync is not a cycle');

    }//end testASynchronizationAlreadyOnTheStackIsACycle()


    /**
     * A cycle can be formed out of either hop kind, or a mix — which is exactly
     * why the two mechanisms share one stack rather than keeping their own.
     *
     * @return void
     */
    public function testOneStackCoversBothChainingMechanisms(): void
    {
        // followUps pushed 'sync-a'; a `synchronization` rule inside it now
        // tries to run 'sync-a' again. A per-mechanism stack would miss this.
        $this->setStack(['sync-a']);

        $this->assertTrue(
            in_array('sync-a', $this->stack(), true),
            'a rule-hop must see a followUp-hop already on the stack'
        );

    }//end testOneStackCoversBothChainingMechanisms()


    /**
     * Nested entries unwind in order, so sibling follow-ups are not blocked by
     * one another — only genuine ancestors count as a cycle.
     *
     * @return void
     */
    public function testSiblingsAreNotBlockedByEachOther(): void
    {
        $this->setStack(['parent']);

        // First child runs and completes.
        $s   = $this->stack();
        $s[] = 'child-one';
        $this->setStack($s);
        $s = $this->stack();
        array_pop($s);
        $this->setStack($s);

        // Second child must still be allowed.
        $this->assertFalse(
            in_array('child-two', $this->stack(), true),
            'a completed sibling must not leave the chain blocked'
        );
        $this->assertSame(['parent'], $this->stack(), 'the stack unwound to the parent');

    }//end testSiblingsAreNotBlockedByEachOther()
}//end class
