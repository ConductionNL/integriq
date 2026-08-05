<?php
/**
 * Tests for the openconnector:authentication-config command (ocon#232).
 *
 * THE LOAD-BEARING TEST IS {@see testWithoutTheFlagNothingIsEverWritten()}: this
 * command can DELETE credential data, and the flag is the only thing standing
 * between an operator's audit and an irreversible write.
 *
 * These tests wire the REAL auditor and REAL remover over the render-boundary
 * double rather than mocking them. Mocking would reduce the gate test to
 * `expects($this->never())` on a double — which passes even if the production gate
 * is deleted, as long as the mock is not called for some unrelated reason. Driving
 * the real chain and asserting on the DOUBLE'S RECORDED SAVES means the assertion
 * is about whether a write actually reached storage. That is what makes
 * "make removal fire without the opt-in and a test fails" true.
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

use OCA\OpenConnector\Command\AuthenticationConfig;
use OCA\OpenConnector\Service\Security\AuthenticationConfigAuditor;
use OCA\OpenConnector\Service\Security\AuthenticationConfigRemover;
use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCA\OpenConnector\Tests\Helpers\MigrationSimulatingObjectService;
use OCA\OpenConnector\Tests\Helpers\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenConnector\Command\AuthenticationConfig
 */
class AuthenticationConfigTest extends TestCase
{

    /**
     * A recognisable secret that must never be printed by the command.
     *
     * @var string
     */
    private const SECRET = 'super-secret-client-secret-DO-NOT-LEAK';

    /**
     * The object-service double.
     *
     * @var MigrationSimulatingObjectService
     */
    private MigrationSimulatingObjectService $objectService;

    /**
     * The command tester.
     *
     * @var CommandTester
     */
    private CommandTester $tester;

    /**
     * Wire the command over REAL services over the double.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = new MigrationSimulatingObjectService();
        $logger              = new RecordingLogger();
        $auditor             = new AuthenticationConfigAuditor(
            new InlineSecretMigrationPlanner($this->objectService, $logger)
        );
        $remover             = new AuthenticationConfigRemover($auditor, $this->objectService, $logger);

        $command      = new AuthenticationConfig($auditor, $remover, $this->createMock(ContainerInterface::class));
        $this->tester = new CommandTester($command);
    }//end setUp()

    /**
     * Seed a source holding an authenticationConfig.
     *
     * @param string $uuid The uuid.
     * @param string $name The name.
     *
     * @return void
     */
    private function seedHoldingSource(string $uuid, string $name): void
    {
        $this->objectService->seed(
            uuid: $uuid,
            object: [
                'name'                 => $name,
                'authenticationConfig' => ['client_id' => 'public', 'client_secret' => self::SECRET],
                'configuration'        => ['headers' => ['Accept' => 'application/json']],
            ],
            owner: 'admin',
            organisation: 'org-1'
        );
    }//end seedHoldingSource()

    /**
     * THE GATE (mutation guard).
     *
     * The default action is a read-only audit. Every invocation WITHOUT the explicit
     * opt-in flag — including the audit, the json audit, and a bare run — must leave
     * storage byte-for-byte untouched.
     *
     * Delete the `--remove-authentication-config` check in
     * AuthenticationConfig::execute() so the removal fires unconditionally, and this
     * test FAILS: `saves` becomes non-empty and the stored value becomes null.
     *
     * @return void
     */
    public function testWithoutTheFlagNothingIsEverWritten(): void
    {
        $invocations = [
            'bare'        => [],
            'json'        => ['--json' => true],
            'with limit'  => ['--limit' => '50'],
        ];

        foreach ($invocations as $label => $args) {
            $this->objectService->saves = [];
            $this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

            $this->tester->execute($args);

            $this->assertSame(
                Command::SUCCESS,
                $this->tester->getStatusCode(),
                sprintf('The `%s` audit invocation should succeed.', $label)
            );
            $this->assertSame(
                [],
                $this->objectService->saves,
                sprintf(
                    'THE DELETION GATE FAILED: the `%s` invocation WROTE to storage without '
                    .'--%s. The audit must be read-only.',
                    $label,
                    AuthenticationConfig::FLAG_REMOVE
                )
            );
            $this->assertSame(
                ['client_id' => 'public', 'client_secret' => self::SECRET],
                $this->objectService->stored['src-1']['object']['authenticationConfig'],
                sprintf('The `%s` invocation destroyed a credential without the opt-in flag.', $label)
            );
        }
    }//end testWithoutTheFlagNothingIsEverWritten()

    /**
     * WITH the explicit flag, the value IS removed — proving the gate test above is
     * meaningful rather than passing because removal never works at all.
     *
     * @return void
     */
    public function testWithTheExplicitFlagTheValueIsRemoved(): void
    {
        $this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

        $this->tester->execute(['--'.AuthenticationConfig::FLAG_REMOVE => true]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $this->assertCount(1, $this->objectService->saves, 'The opt-in run must write.');
        $this->assertNull($this->objectService->stored['src-1']['object']['authenticationConfig']);
        $this->assertStringContainsString('Removed : 1', $this->tester->getDisplay());
    }//end testWithTheExplicitFlagTheValueIsRemoved()

    /**
     * THE OUTPUT SHAPE: the audit prints key NAMES and shapes, never a value.
     *
     * @return void
     */
    public function testTheAuditOutputCarriesKeyNamesButNeverAValue(): void
    {
        $this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

        $this->tester->execute([]);
        $display = $this->tester->getDisplay();

        $this->assertStringNotContainsString(
            self::SECRET,
            $display,
            'THE COMMAND PRINTED A SECRET. The audit exists so an operator can decide without ever '
            .'seeing the credential.'
        );
        $this->assertStringContainsString('client_secret', $display, 'The key NAME must be shown.');
        $this->assertStringContainsString('client_id', $display);
        $this->assertStringContainsString(sprintf('string(%d)', strlen(self::SECRET)), $display, 'The shape hint.');
        $this->assertStringContainsString(substr(hash('sha256', self::SECRET), 0, 8), $display, 'The fingerprint.');
    }//end testTheAuditOutputCarriesKeyNamesButNeverAValue()

    /**
     * The --json audit is machine-readable and equally value-free.
     *
     * @return void
     */
    public function testTheJsonAuditIsValueFree(): void
    {
        $this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

        $this->tester->execute(['--json' => true]);
        $display = $this->tester->getDisplay();

        $this->assertStringNotContainsString(self::SECRET, $display, 'The --json audit leaked a secret.');

        $report = json_decode($display, true);
        $this->assertIsArray($report, 'The --json output must be valid JSON.');
        $this->assertSame('authenticationConfig', $report['field']);
        $this->assertSame(1, $report['totalSources']);
        $this->assertSame(1, $report['holdValue']);
        $this->assertFalse($report['schemaPropertyRemovable']);
        $this->assertSame(['client_id', 'client_secret'], $report['sources'][0]['keys']);
        $this->assertSame([], $this->objectService->saves, 'The --json audit must still write nothing.');
    }//end testTheJsonAuditIsValueFree()

    /**
     * A Twig-referenced source is warned about in the audit and refused by the removal.
     *
     * @return void
     */
    public function testATwigReferencedSourceIsWarnedAndRefused(): void
    {
        $this->objectService->seed(
            uuid: 'src-twig',
            object: [
                'name'                 => 'Twig source',
                'authenticationConfig' => ['client_secret' => self::SECRET],
                'configuration'        => [
                    'headers' => ['Authorization' => 'Bearer {{ source.authenticationConfig.client_secret }}'],
                ],
            ],
            owner: null,
            organisation: null
        );

        $this->tester->execute([]);
        $this->assertStringContainsString('Twig-referenced   : 1', $this->tester->getDisplay());

        $this->tester->execute(['--'.AuthenticationConfig::FLAG_REMOVE => true]);
        $this->assertStringContainsString('Blocked : 1', $this->tester->getDisplay());
        $this->assertSame(
            ['client_secret' => self::SECRET],
            $this->objectService->stored['src-twig']['object']['authenticationConfig'],
            'A Twig-referenced source must keep its value.'
        );
    }//end testATwigReferencedSourceIsWarnedAndRefused()

    /**
     * The schema-property drop REFUSES while any source still holds a value.
     *
     * @return void
     */
    public function testTheSchemaDropIsRefusedWhileASourceStillHoldsAValue(): void
    {
        $this->seedHoldingSource(uuid: 'src-1', name: 'OAuth Source');

        $this->tester->execute(['--'.AuthenticationConfig::FLAG_DROP_SCHEMA => true]);

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertStringContainsString('Refusing to drop the schema property', $this->tester->getDisplay());
    }//end testTheSchemaDropIsRefusedWhileASourceStillHoldsAValue()

    /**
     * An invalid --limit is rejected before anything is read or written.
     *
     * @return void
     */
    public function testAnInvalidLimitIsRejected(): void
    {
        $this->tester->execute(['--limit' => 'abc']);

        $this->assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $this->assertSame([], $this->objectService->saves);
    }//end testAnInvalidLimitIsRejected()

    /**
     * The command is NOT a repair step.
     *
     * The deleting path must be unreachable from `occ maintenance:repair` and from an
     * app upgrade. That is guaranteed structurally: this is a Symfony Command
     * registered under `<commands>`, and it implements no IRepairStep. This test pins
     * that so a future refactor cannot quietly turn the delete into an unattended,
     * on-upgrade action.
     *
     * @return void
     */
    public function testTheCommandIsNotAndMustNotBecomeARepairStep(): void
    {
        $command = new AuthenticationConfig(
            new AuthenticationConfigAuditor(
                new InlineSecretMigrationPlanner($this->objectService, new RecordingLogger())
            ),
            $this->createMock(AuthenticationConfigRemover::class),
            $this->createMock(ContainerInterface::class)
        );

        $this->assertNotInstanceOf(
            \OCP\Migration\IRepairStep::class,
            $command,
            'This command DELETES credential data. It must never be an IRepairStep — a repair step runs '
            .'unattended on every upgrade, with no human to authorise the deletion.'
        );
        $this->assertInstanceOf(Command::class, $command);
        $this->assertSame('openconnector:authentication-config', $command->getName());
    }//end testTheCommandIsNotAndMustNotBecomeARepairStep()

    /**
     * The repair-step registration in info.xml must not list this command's class, and
     * the Phase-D repair step must still exclude authenticationConfig.
     *
     * Complements the structural test above with the WIRING: even a non-IRepairStep
     * class could be dragged into an upgrade path by a careless registration.
     *
     * @return void
     */
    public function testInfoXmlRegistersThisAsACommandAndNeverAsARepairStep(): void
    {
        $root = dirname(__DIR__, 3);

        // Read then parse, rather than simplexml_load_file(). Nextcloud's
        // base.php installs an external-entity loader that returns null, and
        // simplexml_load_file() resolves the FILE ITSELF through that loader —
        // so it returns false for a perfectly valid document whenever a real NC
        // is bootstrapped. simplexml_load_string() does not go through it.
        $xml = simplexml_load_string((string) file_get_contents($root.'/appinfo/info.xml'));

        $this->assertNotFalse($xml, 'info.xml must parse (a double hyphen in an XML comment breaks it).');

        $commands = [];
        foreach ($xml->commands->command as $command) {
            $commands[] = (string) $command;
        }

        $this->assertContains(
            'OCA\OpenConnector\Command\AuthenticationConfig',
            $commands,
            'The command must be registered so an operator can actually run it.'
        );

        $repairSteps = [];
        foreach ($xml->xpath('//repair-steps//step') as $step) {
            $repairSteps[] = (string) $step;
        }

        $this->assertNotContains(
            'OCA\OpenConnector\Command\AuthenticationConfig',
            $repairSteps,
            'The authenticationConfig removal must NEVER be reachable from an upgrade / maintenance:repair.'
        );
        $this->assertNotContains('OCA\OpenConnector\Service\Security\AuthenticationConfigRemover', $repairSteps);
    }//end testInfoXmlRegistersThisAsACommandAndNeverAsARepairStep()
}//end class
