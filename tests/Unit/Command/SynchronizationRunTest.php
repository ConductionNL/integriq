<?php
/**
 * Tests for the openconnector:synchronization:run command (#1082).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Command;

use OCA\OpenConnector\Command\SynchronizationRun;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenConnector\Command\SynchronizationRun
 *
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-occ-command-runs-a-synchronization-from-the-terminal
 */
class SynchronizationRunTest extends TestCase
{

    /**
     * @var SynchronizationService&\PHPUnit\Framework\MockObject\MockObject
     */
    private $synchronizationService;

    /**
     * @var OrObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;


    /**
     * Build a tester over the command with a resolvable synchronization.
     *
     * @param boolean $found Whether the lookup should return a synchronization.
     *
     * @return CommandTester
     */
    private function tester(bool $found=true): CommandTester
    {
        $this->synchronizationService = $this->createMock(SynchronizationService::class);
        $this->orObjectService        = $this->createMock(OrObjectService::class);

        if ($found === true) {
            $synchronization = new ObjectEntity();
            $synchronization->setUuid('sync-uuid-1');
            $synchronization->setObject(['name' => 'cli-sync']);
            $this->orObjectService->method('find')->willReturn($synchronization);
        } else {
            $this->orObjectService->method('find')->willReturn(null);
        }

        return new CommandTester(
            new SynchronizationRun($this->synchronizationService, $this->orObjectService)
        );

    }//end tester()


    /**
     * A successful run prints the traceId, the step lines and a success summary,
     * and exits 0.
     *
     * @return void
     */
    public function testSuccessfulRunPrintsProgressAndSucceeds(): void
    {
        $tester = $this->tester();

        // Emit two steps from inside synchronize(), which is what the terminal
        // renderer is subscribed to.
        $this->synchronizationService->method('synchronize')->willReturnCallback(
            function (...$args) {
                foreach ($args as $arg) {
                    if ($arg instanceof ExecutionTraceContext) {
                        $arg->addStep(type: 'synchronization', name: 'item-1', timing: null, status: 'success');
                        $arg->addStep(type: 'call', name: 'source-fetch', timing: null, status: 'error');
                    }
                }

                return ['objects' => ['created' => 2, 'invalid' => 1]];
            }
        );

        $exit   = $tester->execute(['id' => 'sync-uuid-1']);
        $output = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('traceId', $output);
        $this->assertStringContainsString('item-1', $output, 'each trace step must reach the terminal as it happens');
        $this->assertStringContainsString('source-fetch', $output);
        $this->assertStringContainsString('created', $output);
    }//end testSuccessfulRunPrintsProgressAndSucceeds()


    /**
     * A missing synchronization exits non-zero rather than running anything.
     *
     * @return void
     */
    public function testMissingSynchronizationFails(): void
    {
        $tester = $this->tester(found: false);
        $this->synchronizationService->expects($this->never())->method('synchronize');

        $exit = $tester->execute(['id' => 'does-not-exist']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }//end testMissingSynchronizationFails()


    /**
     * A throwing run prints the exception class, message and location, and exits
     * non-zero.
     *
     * Reproducing a failure in the terminal is the entire reason this command
     * exists, so the exception must be rendered rather than swallowed — and the
     * command must say how to get the stack trace rather than silently omitting it.
     *
     * @return void
     */
    public function testThrowingRunReportsTheExceptionAndFails(): void
    {
        $tester = $this->tester();
        $this->synchronizationService->method('synchronize')
            ->willThrowException(new \RuntimeException('source refused the connection'));

        $exit   = $tester->execute(['id' => 'sync-uuid-1']);
        $output = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringContainsString('source refused the connection', $output);
        $this->assertStringContainsString('-v', $output, 'the operator must be told how to get the trace');
    }//end testThrowingRunReportsTheExceptionAndFails()


    /**
     * --test and --force reach synchronize() as isTest and force.
     *
     * @return void
     */
    public function testFlagsAreForwardedToTheEngine(): void
    {
        $tester = $this->tester();

        $seen = [];
        $this->synchronizationService->method('synchronize')->willReturnCallback(
            function (...$args) use (&$seen) {
                $seen = $args;

                return [];
            }
        );

        $tester->execute(['id' => 'sync-uuid-1', '--test' => true, '--force' => true]);

        // Positional: synchronization, isTest, force, ...
        $this->assertTrue($seen[1], '--test must arrive as isTest');
        $this->assertTrue($seen[2], '--force must arrive as force');
    }//end testFlagsAreForwardedToTheEngine()


    /**
     * --json emits the raw result payload and suppresses the decorated progress
     * output, so the command can be piped.
     *
     * @return void
     */
    public function testJsonModeEmitsMachineReadableOutputOnly(): void
    {
        $tester = $this->tester();

        $this->synchronizationService->method('synchronize')->willReturnCallback(
            function (...$args) {
                foreach ($args as $arg) {
                    if ($arg instanceof ExecutionTraceContext) {
                        $arg->addStep(type: 'synchronization', name: 'noisy-step', timing: null, status: 'success');
                    }
                }

                return ['objects' => ['created' => 1]];
            }
        );

        $tester->execute(['id' => 'sync-uuid-1', '--json' => true]);
        $output = $tester->getDisplay();

        $this->assertStringNotContainsString(
            'noisy-step',
            $output,
            'json mode must not interleave step lines with the payload'
        );
        $this->assertStringNotContainsString('traceId', $output);

        $decoded = json_decode(trim($output), true);
        $this->assertSame(['objects' => ['created' => 1]], $decoded, 'json mode must emit parseable JSON');
    }//end testJsonModeEmitsMachineReadableOutputOnly()


    /**
     * A run that returns isolated per-object errors (REQ-008) surfaces them, since
     * an otherwise-successful run can still carry failures worth seeing.
     *
     * @return void
     */
    public function testIsolatedPerObjectErrorsAreSurfaced(): void
    {
        $tester = $this->tester();
        $this->synchronizationService->method('synchronize')->willReturn(
            [
                'objects' => ['created' => 1],
                'errors'  => ['object 42 failed validation'],
            ]
        );

        $tester->execute(['id' => 'sync-uuid-1']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('isolated per-object error', $output);
        $this->assertStringContainsString('object 42 failed validation', $output);
    }//end testIsolatedPerObjectErrorsAreSurfaced()
}//end class
