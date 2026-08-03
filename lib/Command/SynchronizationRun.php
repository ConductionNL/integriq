<?php
/**
 * OpenConnector synchronization-run command.
 *
 * Runs one synchronization from the terminal, printing progress and the result as
 * it happens, with no request and therefore no timeout.
 *
 * @category Command
 * @package  OCA\OpenConnector\Command
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

namespace OCA\OpenConnector\Command;

use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Runs a synchronization from the terminal (#1082).
 *
 * The last-resort complement to the streamed HTTP endpoints. Streaming gets the
 * output past a reverse-proxy timeout, but it is still a web request: PHP-FPM
 * limits apply, the socket can die mid-fatal, and an OOM is reported by a shutdown
 * handler that is itself racing the process teardown.
 *
 * From the CLI none of that is in the way. There is no proxy to buffer the output,
 * no gateway timeout, no dispatcher to fight over the response body, and a fatal
 * prints its own message to the terminal. If a run dies in a way even the streamed
 * socket cannot report, this is where it can be seen — and it is xdebug-attachable,
 * which no amount of SSE framing gives you.
 *
 * Progress uses the same `ExecutionTraceContext` step listener the streaming
 * harness uses, so the terminal shows the same steps in the same order as the
 * console. One vocabulary, two renderers.
 *
 * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-occ-command-runs-a-synchronization-from-the-terminal
 */
class SynchronizationRun extends Command
{
    /**
     * Constructor.
     *
     * @param SynchronizationService $syncService     The synchronization engine.
     * @param OrObjectService        $orObjectService OpenRegister object access, to resolve the synchronization.
     */
    public function __construct(
        private readonly SynchronizationService $syncService,
        private readonly OrObjectService $orObjectService
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Configure the command name, argument and options.
     *
     * @return void
     *
     * @spec exclude Symfony console wiring — framework metadata, no domain behavior.
     */
    protected function configure(): void
    {
        $this->setName(name: 'openconnector:synchronization:run')
            ->setDescription('Run one synchronization from the terminal, printing progress and the result as it happens')
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The UUID of the synchronization to run'
            )
            ->addOption(
                'test',
                null,
                InputOption::VALUE_NONE,
                'Dry run: pass isTest so the engine performs a test synchronization'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Force the run regardless of whether the source data changed'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Emit the result payload as JSON instead of a formatted summary'
            );

    }//end configure()

    /**
     * Execute the command.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return integer 0 on success; 1 when the synchronization is missing or the run throws.
     *
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-occ-command-runs-a-synchronization-from-the-terminal
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $id     = (string) $input->getArgument('id');
        $isTest = (bool) $input->getOption('test');
        $force  = (bool) $input->getOption('force');
        $asJson = (bool) $input->getOption('json');

        $synchronization = $this->resolveSynchronization(io: $io, id: $id);
        if ($synchronization === null) {
            return Command::FAILURE;
        }

        $trace = new ExecutionTraceContext(
            entryPoint: 'manual',
            entryPointId: $id,
            dryRun: $isTest,
            triggeredBy: 'cli'
        );

        if ($asJson === false) {
            $mode = '';
            if ($isTest === true) {
                $mode = ' (test)';
            }

            $io->title(sprintf('Running synchronization %s%s', $id, $mode));
            $io->writeln(sprintf('<comment>traceId</comment> %s', $trace->getTraceId()));
            $io->newLine();

            // The same listener the streaming harness registers, rendering to a
            // terminal instead of an SSE frame.
            $trace->setStepListener(
                function (array $step) use ($output): void {
                    $output->writeln($this->formatStep(step: $step));
                }
            );
        }

        try {
            $result = $this->syncService->synchronize(
                synchronization: $synchronization,
                isTest: $isTest,
                force: $force,
                trace: $trace
            );
        } catch (Throwable $exception) {
            // Printed rather than swallowed: reproducing a failure in the terminal
            // is the entire reason this command exists, so the trace has to be
            // visible and not just the message.
            $io->newLine();
            $io->error(
                sprintf(
                    "%s: %s\n  at %s:%d",
                    get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                )
            );

            if ($output->isVerbose() === false) {
                $io->writeln('<comment>Re-run with -v for the stack trace.</comment>');

                return Command::FAILURE;
            }

            $io->writeln($exception->getTraceAsString());

            return Command::FAILURE;
        } finally {
            // The context outlives this method; a listener writing to a closed
            // stream afterwards would be an error in an unrelated path.
            $trace->setStepListener(null);
        }//end try

        if ($asJson === true) {
            $output->writeln(
                (string) json_encode(($result ?? []), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            );

            return Command::SUCCESS;
        }

        $io->newLine();
        $io->success('Synchronization finished.');
        $this->renderResult(io: $io, result: ($result ?? []));

        return Command::SUCCESS;

    }//end execute()

    /**
     * Resolve the synchronization, reporting to the console on failure.
     *
     * Extracted from {@see execute()} so that method stays within its length
     * budget. Returns null on either failure mode — a throwing lookup or a
     * genuine miss — having already told the operator which it was, since "not
     * found" and "the lookup itself blew up" want different next steps.
     *
     * @param SymfonyStyle $io The console style helper.
     * @param string       $id The synchronization UUID.
     *
     * @return ObjectEntity|null The synchronization, or null when it could not be resolved.
     */
    private function resolveSynchronization(SymfonyStyle $io, string $id): ?ObjectEntity
    {
        try {
            $synchronization = $this->orObjectService->find(
                id: $id,
                register: 'openconnector',
                schema: 'synchronization',
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $exception) {
            $io->error(sprintf('Synchronization "%s" not found: %s', $id, $exception->getMessage()));

            return null;
        }

        if ($synchronization === null) {
            $io->error(sprintf('Synchronization "%s" not found.', $id));

            return null;
        }

        return $synchronization;

    }//end resolveSynchronization()

    /**
     * Format one trace step as a terminal line.
     *
     * @param array $step The step, in the shape ExecutionTraceContext::addStep() buffers.
     *
     * @return string
     */
    private function formatStep(array $step): string
    {
        $colour = match (($step['status'] ?? '')) {
            'success' => 'info',
            'error'   => 'error',
            default   => 'comment',
        };

        return sprintf(
            '  <%s>%-8s</%s> %-16s %s <fg=gray>(%dms)</>',
            $colour,
            ($step['status'] ?? '?'),
            $colour,
            ($step['type'] ?? '?'),
            ($step['name'] ?? '?'),
            ($step['durationMs'] ?? 0)
        );

    }//end formatStep()

    /**
     * Print the result payload as a readable summary.
     *
     * Only the shapes the engine actually produces are special-cased; anything else
     * falls back to JSON rather than being silently dropped, because a run whose
     * result cannot be summarised still has to be inspectable.
     *
     * @param SymfonyStyle $io     The console style helper.
     * @param array        $result The result payload from synchronize().
     *
     * @return void
     */
    private function renderResult(SymfonyStyle $io, array $result): void
    {
        $objects = ($result['objects'] ?? null);
        $errors  = ($result['errors'] ?? []);

        $this->renderObjectCounts(io: $io, objects: $objects);
        $this->renderIsolatedErrors(io: $io, errors: $errors);

        // Neither section applied, so the payload is a shape this command does not
        // know how to summarise. Dump it rather than silently showing nothing — a
        // run whose result cannot be summarised still has to be inspectable.
        if ($objects === null && $errors === []) {
            $io->writeln(
                (string) json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            );
        }

    }//end renderResult()

    /**
     * Print the per-outcome object counts as a table.
     *
     * @param SymfonyStyle $io      The console style helper.
     * @param mixed        $objects The result's `objects` member, whatever shape it has.
     *
     * @return void
     */
    private function renderObjectCounts(SymfonyStyle $io, mixed $objects): void
    {
        if (is_array($objects) === false) {
            return;
        }

        $rows = [];
        foreach ($objects as $key => $value) {
            if (is_scalar($value) === true) {
                $rows[] = [$key, (string) $value];
            }
        }

        if ($rows === []) {
            return;
        }

        $io->table(['objects', 'count'], $rows);

    }//end renderObjectCounts()

    /**
     * Print the isolated per-object errors, when the run collected any.
     *
     * Per-object isolation (REQ-008) collects these rather than aborting the run,
     * so a run that "succeeded" can still carry failures worth seeing.
     *
     * @param SymfonyStyle $io     The console style helper.
     * @param mixed        $errors The result's `errors` member.
     *
     * @return void
     */
    private function renderIsolatedErrors(SymfonyStyle $io, mixed $errors): void
    {
        if (is_array($errors) === false || $errors === []) {
            return;
        }

        $io->warning(sprintf('%d isolated per-object error(s):', count($errors)));
        foreach ($errors as $error) {
            $rendered = (string) json_encode($error);
            if (is_scalar($error) === true) {
                $rendered = (string) $error;
            }

            $io->writeln('  - '.$rendered);
        }

    }//end renderIsolatedErrors()
}//end class
