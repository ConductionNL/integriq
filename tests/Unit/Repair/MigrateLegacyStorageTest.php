<?php

/**
 * The storage cutover completes on a path that a fresh install actually runs.
 *
 * ocon#1180. Two migrations already tried to set `storage_migrated` and both
 * did it from `postSchemaChange()`, which a first install never calls. The
 * tests here cover the relocated logic AND the wiring — because on this defect
 * the logic was never the broken part.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Repair;

use OCA\OpenConnector\Repair\MigrateLegacyStorage;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Behaviour of the MigrateLegacyStorage repair step.
 */
class MigrateLegacyStorageTest extends TestCase
{

    /**
     * The value `storage_migrated` holds after the step under test ran.
     *
     * @var string
     */
    private string $flag = 'false';


    /**
     * An IAppConfig double backed by {@see self::$flag}.
     *
     * @param string $initial The flag's value before the step runs.
     *
     * @return IAppConfig The configured double.
     */
    private function appConfig(string $initial='false'): IAppConfig
    {
        $this->flag = $initial;

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (): string => $this->flag
        );
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->flag = $value;
                return true;
            }
        );

        return $appConfig;

    }//end appConfig()


    /**
     * A database double where the named tables exist and hold the given counts.
     *
     * @param array<string, int> $counts Unprefixed table name => row count. Any
     *                                   table absent from the map is reported
     *                                   as not existing.
     *
     * @return IDBConnection The configured double.
     */
    private function database(array $counts): IDBConnection
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('tableExists')->willReturnCallback(
            static fn (string $table): bool => array_key_exists($table, $counts)
        );

        $db->method('getQueryBuilder')->willReturnCallback(
            function () use ($counts): IQueryBuilder {
                $selected = null;

                // `fetchOne()` deliberately — it is what `OCP\DB\IResult`
                // actually declares on this app's minimum Nextcloud. Mocking
                // the interface is what caught the production code reaching
                // for Doctrine's `fetchAssociative()`, which only exists from
                // NC 33 while info.xml advertises 32.
                $result = $this->createMock(IResult::class);
                $result->method('fetchOne')->willReturnCallback(
                    static function () use (&$selected, $counts): int {
                        return ($counts[$selected] ?? 0);
                    }
                );

                $functions = $this->createMock(IFunctionBuilder::class);
                $functions->method('count')->willReturn($this->createMock(IQueryFunction::class));

                $qb = $this->createMock(IQueryBuilder::class);
                $qb->method('func')->willReturn($functions);
                $qb->method('select')->willReturnSelf();
                $qb->method('from')->willReturnCallback(
                    static function (string $table) use (&$selected, $qb): IQueryBuilder {
                        $selected = $table;
                        return $qb;
                    }
                );
                $qb->method('executeQuery')->willReturn($result);

                return $qb;
            }
        );

        return $db;

    }//end database()


    /**
     * Build the step under test.
     *
     * @param IAppConfig    $appConfig The app-config double.
     * @param IDBConnection $db        The database double.
     *
     * @return MigrateLegacyStorage The step.
     */
    private function step(IAppConfig $appConfig, IDBConnection $db): MigrateLegacyStorage
    {
        // The container REFUSES to resolve, the way a real one does when
        // OpenRegister is not enabled. A bare double returns null instead,
        // which sends the step down a "called a method on null" path that no
        // deployment produces — a double that is wrong in the app's favour.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(
            new class extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
            }
        );

        return new MigrateLegacyStorage(
            $container,
            $appConfig,
            $db,
            new NullLogger()
        );

    }//end step()


    /**
     * The warnings the step emitted during the last run.
     *
     * @var string[]
     */
    private array $warnings = [];


    /**
     * An IOutput double that records warnings.
     *
     * Asserting the flag alone is not enough: "did not set the flag" is also
     * what an exception inside the counting loop produces, so a test that only
     * checks the flag passes identically whether the step decided or crashed.
     *
     * @return IOutput The recording double.
     */
    private function recordingOutput(): IOutput
    {
        $this->warnings = [];

        $output = $this->createMock(IOutput::class);
        $output->method('warning')->willReturnCallback(
            function (string $message): void {
                $this->warnings[] = $message;
            }
        );

        return $output;

    }//end recordingOutput()


    /**
     * A fresh install — no legacy table exists — declares the cutover complete.
     *
     * This is the ocon#1180 scenario: `Version2Date20260520000099` drops every
     * legacy table in the same schema pass that created it, so a first install
     * finds none of them, has nothing to copy, and must still end up with the
     * flag set.
     *
     * @return void
     */
    public function testAFreshInstallSetsTheFlag(): void
    {
        $appConfig = $this->appConfig();

        $this->step($appConfig, $this->database([]))->run($this->recordingOutput());

        $this->assertSame('true', $this->flag);

    }//end testAFreshInstallSetsTheFlag()


    /**
     * Empty legacy tables also count as a completed cutover.
     *
     * @return void
     */
    public function testEmptyLegacyTablesSetTheFlag(): void
    {
        $appConfig = $this->appConfig();
        $db        = $this->database(['openconnector_sources' => 0, 'openconnector_jobs' => 0]);

        $this->step($appConfig, $db)->run($this->recordingOutput());

        $this->assertSame([], $this->warnings, 'counting empty tables should not warn');
        $this->assertSame('true', $this->flag);

    }//end testEmptyLegacyTablesSetTheFlag()


    /**
     * A table that still holds rows does NOT get the flag set behind its back.
     *
     * The container double resolves nothing, so the migrator cannot be built
     * and no rows move — which is precisely when declaring the cutover complete
     * would strand them.
     *
     * @return void
     */
    public function testRemainingRowsDoNotSetTheFlag(): void
    {
        $appConfig = $this->appConfig();
        $db        = $this->database(['openconnector_sources' => 3]);

        $this->step($appConfig, $db)->run($this->recordingOutput());

        $this->assertSame('false', $this->flag);

        // Pin WHY it stopped. An unset flag is also what a crash in the
        // counting loop produces, so without this the test passes whether the
        // step reached the migration branch or never got near it — which is
        // exactly how it passed while the query double was malformed.
        $this->assertNotEmpty($this->warnings, 'the step should have said why it stopped');
        $this->assertStringContainsString('migrator', implode(' ', $this->warnings));

    }//end testRemainingRowsDoNotSetTheFlag()


    /**
     * A count that THROWS is not read as zero.
     *
     * An uncountable table has unknown contents. Treating the failure as an
     * empty table is the version of this bug that loses data instead of hiding
     * a feature.
     *
     * @return void
     */
    public function testAFailedCountLeavesTheFlagAlone(): void
    {
        $appConfig = $this->appConfig();

        $db = $this->createMock(IDBConnection::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('getQueryBuilder')->willThrowException(new \RuntimeException('database is gone'));

        $this->step($appConfig, $db)->run($this->recordingOutput());

        $this->assertSame('false', $this->flag);

    }//end testAFailedCountLeavesTheFlagAlone()


    /**
     * An instance that already completed the cutover is left untouched.
     *
     * @return void
     */
    public function testAnAlreadyMigratedInstanceIsANoop(): void
    {
        $appConfig = $this->appConfig(initial: 'true');

        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('tableExists');

        $this->step($appConfig, $db)->run($this->recordingOutput());

        $this->assertSame('true', $this->flag);

    }//end testAnAlreadyMigratedInstanceIsANoop()


    /**
     * The step's table list matches the cleanup migration's.
     *
     * The two lists are the same set of tables held in two places. A table
     * added to the migration but not here would be dropped from the cutover
     * check silently, and the check would report "complete" over it.
     *
     * @return void
     */
    public function testTheTableListMatchesTheCleanupMigration(): void
    {
        $migration = new \ReflectionClass(\OCA\OpenConnector\Migration\Version2Date20260520000099::class);
        $expected  = $migration->getConstant('LEGACY_TABLES');

        $this->assertIsArray($expected, 'the cleanup migration should still declare LEGACY_TABLES');

        sort($expected);
        $actual = MigrateLegacyStorage::LEGACY_TABLES;
        sort($actual);

        $this->assertSame($expected, $actual);

    }//end testTheTableListMatchesTheCleanupMigration()


    /**
     * The step is wired under BOTH `<install>` and `<post-migration>`.
     *
     * This is the assertion the whole issue turns on. The relocated logic is
     * worth nothing if it is declared only where a fresh install does not look,
     * which is exactly how the two previous attempts failed — and a wiring
     * claim that lives only in a docblock is what let the second one ship.
     *
     * @return void
     */
    public function testTheStepIsWiredForBothInstallAndUpgrade(): void
    {
        $info = simplexml_load_file(__DIR__.'/../../../appinfo/info.xml');

        $this->assertNotFalse($info, 'appinfo/info.xml should parse');

        foreach (['install', 'post-migration'] as $hook) {
            $steps = [];
            foreach ($info->{'repair-steps'}->{$hook}->step as $step) {
                $steps[] = (string) $step;
            }

            $this->assertContains(
                MigrateLegacyStorage::class,
                $steps,
                sprintf('MigrateLegacyStorage should be declared under <%s>', $hook)
            );
        }

    }//end testTheStepIsWiredForBothInstallAndUpgrade()


    /**
     * Every repair step named in info.xml is a class that exists.
     *
     * A typo in the XML is invisible at author time and only shows up as a
     * step that quietly never ran — the same failure mode as the missing
     * `<install>` block, one layer down.
     *
     * @return void
     */
    public function testEveryDeclaredRepairStepClassExists(): void
    {
        $info = simplexml_load_file(__DIR__.'/../../../appinfo/info.xml');

        $this->assertNotFalse($info, 'appinfo/info.xml should parse');

        $checked = 0;
        foreach (['install', 'pre-migration', 'post-migration', 'live-migration'] as $hook) {
            foreach ($info->{'repair-steps'}->{$hook}->step ?? [] as $step) {
                $class = (string) $step;
                $this->assertTrue(class_exists($class), $class.' is declared in info.xml but does not exist');
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'no repair steps were checked — the XML lookup found nothing');

    }//end testEveryDeclaredRepairStepClassExists()
}//end class
