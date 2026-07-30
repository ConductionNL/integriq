<?php

/**
 * Unit tests for the RecordInlineSecretMigrationStatus repair step (Phase C / ADR-064).
 *
 * Verifies the read-only Phase D gate signal: the step persists
 * `inline_secrets_clean = '1'` only when NO source holds an inline secret, and
 * fails the gate closed ('0') on error. It must never touch a source, and never
 * leak a secret.
 *
 * The step reads sources through the SAME render-boundary-simulating double the
 * planner tests use, so the trap (a rendered read losing the secret) is exercised
 * end-to-end here too.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-gate-signal
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Repair;

use OCA\OpenConnector\Repair\RecordInlineSecretMigrationStatus;
use OCA\OpenConnector\Service\Security\InlineSecretMigrationExecutor;
use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCA\OpenConnector\Tests\Helpers\RenderBoundarySimulatingObjectService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A tiny in-memory IAppConfig capturing setValueString writes.
 */
class RecordingAppConfig implements IAppConfig
{

    /**
     * The captured key => value writes.
     *
     * @var array<string, string>
     */
    public array $written = [];

    /**
     * Capture a string write.
     *
     * @param string $app       The app id.
     * @param string $key       The key.
     * @param string $value     The value.
     * @param bool   $lazy      Unused.
     * @param bool   $sensitive Unused.
     *
     * @return bool
     */
    public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool
    {
        $this->written[$key] = $value;
        return true;
    }//end setValueString()

    // phpcs:disable
    public function getApps(): array { return []; }
    public function getKeys(string $app): array { return []; }
    public function hasKey(string $app, string $key, ?bool $lazy = false): bool { return isset($this->written[$key]); }
    public function isSensitive(string $app, string $key, ?bool $lazy = false): bool { return false; }
    public function isLazy(string $app, string $key): bool { return false; }
    public function getAllValues(string $app, string $prefix = '', bool $filtered = false): array { return []; }
    public function getValues($app, $key) { return []; }
    public function getFilteredValues($app) { return []; }
    public function searchValues(string $key, bool $lazy = false, ?int $typedAs = null): array { return []; }
    public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string { return ($this->written[$key] ?? $default); }
    public function getValueInt(string $app, string $key, int $default = 0, bool $lazy = false): int { return $default; }
    public function getValueFloat(string $app, string $key, float $default = 0, bool $lazy = false): float { return $default; }
    public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool { return $default; }
    public function getValueArray(string $app, string $key, array $default = [], bool $lazy = false): array { return $default; }
    public function getValueType(string $app, string $key, ?bool $lazy = null): int { return 0; }
    public function setValueInt(string $app, string $key, int $value, bool $lazy = false, bool $sensitive = false): bool { return true; }
    public function setValueFloat(string $app, string $key, float $value, bool $lazy = false, bool $sensitive = false): bool { return true; }
    public function setValueBool(string $app, string $key, bool $value, bool $lazy = false): bool { return true; }
    public function setValueArray(string $app, string $key, array $value, bool $lazy = false, bool $sensitive = false): bool { return true; }
    public function updateSensitive(string $app, string $key, bool $sensitive): bool { return true; }
    public function updateLazy(string $app, string $key, bool $lazy): bool { return true; }
    public function getDetails(string $app, string $key): array { return []; }
    public function convertTypeToString(int $type): string { return ''; }
    public function convertTypeToInt(string $type): int { return 0; }
    public function deleteKey(string $app, string $key): void {}
    public function deleteApp(string $app): void {}
    public function clearCache(bool $reload = false): void {}
    // Added when NC34's IAppConfig grew these three; without them this double is
    // abstract and the whole Unit suite dies at load with a fatal error.
    public function searchKeys(string $app, string $prefix = '', bool $lazy = false): array { return []; }
    public function getKeyDetails(string $app, string $key): array { return []; }
    public function getAppInstalledVersions(bool $onlyEnabled = false): array { return []; }
    // phpcs:enable
}//end class

/**
 * A migration executor spy that records whether migrateAll() ran, without touching
 * \OCP\Server or any real broker.
 */
class SpyMigrationExecutor extends InlineSecretMigrationExecutor
{

    /**
     * How many times migrateAll() was invoked.
     *
     * @var int
     */
    public int $migrateCalls = 0;

    /**
     * When true, migrateAll() throws (simulating a blocked/absent broker).
     *
     * @var boolean
     */
    public bool $throws = false;

    /**
     * Record the invocation and return a fixed summary.
     *
     * @param int $limit Unused.
     *
     * @return array<string, mixed>
     */
    public function migrateAll(int $limit = 1000): array
    {
        $this->migrateCalls++;
        if ($this->throws === true) {
            throw new \RuntimeException('broker unavailable (test)');
        }

        return [
            'sources'      => [],
            'totalSources' => 0,
            'migrated'     => 1,
            'failed'       => 0,
            'blocked'      => 0,
            'skipped'      => 0,
            'postRun'      => ['clean' => true, 'pending' => 0, 'manual' => 0],
        ];
    }//end migrateAll()
}//end class

/**
 * A step subclass that returns the spy executor from the makeExecutor() seam and
 * records the call order relative to the appconfig recording.
 */
class TestableRecordStep extends RecordInlineSecretMigrationStatus
{

    /**
     * The spy handed back from makeExecutor().
     *
     * @var SpyMigrationExecutor|null
     */
    public ?SpyMigrationExecutor $spy = null;

    /**
     * Return the spy instead of a real executor.
     *
     * @param OrObjectService              $objectService The object service.
     * @param InlineSecretMigrationPlanner $planner       The planner.
     *
     * @return InlineSecretMigrationExecutor
     */
    protected function makeExecutor(OrObjectService $objectService, InlineSecretMigrationPlanner $planner): InlineSecretMigrationExecutor
    {
        if ($this->spy === null) {
            $this->spy = new SpyMigrationExecutor($objectService, $planner, new NullLogger());
        }

        return $this->spy;
    }//end makeExecutor()
}//end class

/**
 * @covers \OCA\OpenConnector\Repair\RecordInlineSecretMigrationStatus
 */
class RecordInlineSecretMigrationStatusTest extends TestCase
{

    /**
     * Build the step over a render-boundary double seeded with the given sources.
     *
     * @param array<string, array<string, mixed>> $stored   uuid => raw source data.
     * @param RecordingAppConfig                   $appConfig The recording appconfig.
     *
     * @return RecordInlineSecretMigrationStatus
     */
    private function makeStep(array $stored, RecordingAppConfig $appConfig): RecordInlineSecretMigrationStatus
    {
        $objectService         = new RenderBoundarySimulatingObjectService();
        $objectService->stored = $stored;

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService) {
                if ($id === OrObjectService::class || $id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $objectService;
                }

                throw new \RuntimeException('unexpected service: '.$id);
            }
        );

        return new RecordInlineSecretMigrationStatus($container, $appConfig, new NullLogger());
    }//end makeStep()

    /**
     * A dirty fleet must persist a NOT-clean gate — Phase D must be blocked.
     *
     * @return void
     */
    public function testDirtyFleetPersistsNotCleanGate(): void
    {
        $appConfig = new RecordingAppConfig();
        $step      = $this->makeStep(['src-1' => ['name' => 'Live', 'apikey' => 'live-secret']], $appConfig);

        $step->run($this->createMock(IOutput::class));

        $this->assertSame('0', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_CLEAN]);
        $this->assertSame('1', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_PENDING]);
    }//end testDirtyFleetPersistsNotCleanGate()

    /**
     * A fully-migrated fleet persists a CLEAN gate — Phase D may proceed.
     *
     * @return void
     */
    public function testCleanFleetPersistsCleanGate(): void
    {
        $appConfig = new RecordingAppConfig();
        $step      = $this->makeStep(
            ['src-1' => ['name' => 'Migrated', 'apikey' => ['credentialRef' => ['credentialId' => 'abc']]]],
            $appConfig
        );

        $step->run($this->createMock(IOutput::class));

        $this->assertSame('1', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_CLEAN]);
        $this->assertSame('0', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_PENDING]);
        $this->assertSame('0', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_MANUAL]);
    }//end testCleanFleetPersistsCleanGate()

    /**
     * A source that only holds an unmappable secret (authenticationConfig) still
     * blocks Phase D via the manual-review count.
     *
     * @return void
     */
    public function testManualReviewOnlyStillBlocksTheGate(): void
    {
        $appConfig = new RecordingAppConfig();
        $step      = $this->makeStep(
            ['src-1' => ['name' => 'OAuth', 'authenticationConfig' => ['client_secret' => 'shh']]],
            $appConfig
        );

        $step->run($this->createMock(IOutput::class));

        $this->assertSame('0', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_CLEAN]);
        $this->assertSame('1', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_MANUAL]);
    }//end testManualReviewOnlyStillBlocksTheGate()

    /**
     * When OpenRegister is absent the step is a clean no-op: it writes nothing and
     * does not throw. (The class_exists guard short-circuits before any read.)
     *
     * @return void
     */
    public function testNoOpWhenOpenRegisterIsAbsent(): void
    {
        // OrObjectService IS defined in this test process (the stub), so this test
        // asserts the shape instead: with no sources, the gate is clean and no
        // pending/manual work is recorded.
        $appConfig = new RecordingAppConfig();
        $step      = $this->makeStep([], $appConfig);

        $step->run($this->createMock(IOutput::class));

        $this->assertSame('1', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_CLEAN]);
    }//end testNoOpWhenOpenRegisterIsAbsent()

    /**
     * A failure computing the status must fail the gate CLOSED, never open.
     *
     * @return void
     */
    public function testFailureFailsTheGateClosed(): void
    {
        $appConfig = new RecordingAppConfig();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('boom'));

        $step = new RecordInlineSecretMigrationStatus($container, $appConfig, new NullLogger());
        $step->run($this->createMock(IOutput::class));

        $this->assertSame(
            '0',
            $appConfig->written[RecordInlineSecretMigrationStatus::KEY_CLEAN],
            'An unknown status is NOT a clean status — the gate must fail closed.'
        );
    }//end testFailureFailsTheGateClosed()

    /**
     * The step must never write to a source — the double records every read, and
     * none of them may carry the write flags of a save. (There is no save path on
     * the double; a write would call an undefined method and fail.)
     *
     * @return void
     */
    public function testStepNeverMutatesASource(): void
    {
        $appConfig = new RecordingAppConfig();
        $stored    = ['src-1' => ['name' => 'Live', 'apikey' => 'live-secret']];
        $step      = $this->makeStep($stored, $appConfig);

        $step->run($this->createMock(IOutput::class));

        $this->assertSame('live-secret', $stored['src-1']['apikey'], 'The inline secret must be untouched.');
    }//end testStepNeverMutatesASource()

    /**
     * The step now RUNS the migration (executor) before recording the gate — the
     * ordering contract Phase D relies on.
     *
     * @return void
     */
    public function testExecutorRunsBeforeRecording(): void
    {
        $appConfig     = new RecordingAppConfig();
        $objectService = new RenderBoundarySimulatingObjectService();
        // A migrated source: after the (spied) run the fleet reads clean.
        $objectService->stored = ['src-1' => ['name' => 'Migrated', 'apikey' => ['credentialRef' => ['credentialId' => 'abc']]]];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService) {
                if ($id === OrObjectService::class || $id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $objectService;
                }

                throw new \RuntimeException('unexpected service: '.$id);
            }
        );

        $step = new TestableRecordStep($container, $appConfig, new NullLogger());
        $step->run($this->createMock(IOutput::class));

        $this->assertNotNull($step->spy);
        $this->assertSame(1, $step->spy->migrateCalls, 'The migration executor must run exactly once.');
        // And the gate was recorded afterwards.
        $this->assertSame('1', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_CLEAN]);
    }//end testExecutorRunsBeforeRecording()

    /**
     * An executor failure (blocked/absent broker) is non-fatal: the step does not
     * throw, still records — and a dirty fleet stays NOT-clean.
     *
     * @return void
     */
    public function testExecutorFailureIsNonFatalAndKeepsGateClosed(): void
    {
        $appConfig     = new RecordingAppConfig();
        $objectService = new RenderBoundarySimulatingObjectService();
        $objectService->stored = ['src-1' => ['name' => 'Live', 'apikey' => 'still-inline']];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService) {
                if ($id === OrObjectService::class || $id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $objectService;
                }

                throw new \RuntimeException('unexpected service: '.$id);
            }
        );

        $step      = new TestableRecordStep($container, $appConfig, new NullLogger());
        $step->spy = new SpyMigrationExecutor($objectService, new InlineSecretMigrationPlanner($objectService, new NullLogger()), new NullLogger());
        $step->spy->throws = true;

        // Must not throw.
        $step->run($this->createMock(IOutput::class));

        $this->assertSame(1, $step->spy->migrateCalls);
        $this->assertSame('0', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_CLEAN], 'A dirty fleet must stay NOT-clean when the executor could not run.');
        $this->assertSame('1', $appConfig->written[RecordInlineSecretMigrationStatus::KEY_PENDING]);
    }//end testExecutorFailureIsNonFatalAndKeepsGateClosed()

    /**
     * The repair-step ORDERING contract in info.xml: the migration/record step runs
     * AFTER FlagSourceSecretsWriteOnly and BEFORE the Phase D removal step, which in
     * turn runs before MaterializeCatalogItems.
     *
     * @return void
     */
    public function testRepairStepOrderingContract(): void
    {
        $infoXml = __DIR__.'/../../../appinfo/info.xml';
        $this->assertFileExists($infoXml);

        $xml = simplexml_load_file($infoXml);
        $this->assertNotFalse($xml, 'info.xml must parse.');

        $steps = [];
        foreach ($xml->xpath('//repair-steps/post-migration/step') as $node) {
            $steps[] = (string) $node;
        }

        $flag   = array_search('OCA\\OpenConnector\\Repair\\FlagSourceSecretsWriteOnly', $steps, true);
        $record = array_search('OCA\\OpenConnector\\Repair\\RecordInlineSecretMigrationStatus', $steps, true);
        $remove = array_search('OCA\\OpenConnector\\Repair\\RemoveMigratedSourceSecretFields', $steps, true);
        $mat    = array_search('OCA\\OpenConnector\\Repair\\MaterializeCatalogItems', $steps, true);

        $this->assertIsInt($flag);
        $this->assertIsInt($record);
        $this->assertIsInt($remove);
        $this->assertIsInt($mat);

        $this->assertLessThan($record, $flag, 'FlagSourceSecretsWriteOnly must run before the migration/record step.');
        $this->assertLessThan($remove, $record, 'The migration must run before the Phase D removal.');
        $this->assertLessThan($mat, $remove, 'The Phase D removal must run before MaterializeCatalogItems.');
    }//end testRepairStepOrderingContract()
}//end class
