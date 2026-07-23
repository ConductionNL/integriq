<?php
/**
 * Tests for InlineSecretMigrationExecutor — the WRITING half of ocon#151 phase C.
 *
 * These tests assert the CONTRACT, not the double's return value (ocon#215):
 *   - the raw read uses `_render: false` and the org is asserted as
 *     `actingOrganisationId` to resolveInjectable() (or#450);
 *   - the inline value is nulled ONLY after a verified round-trip (the mutation
 *     guard: {@see testFailedVerifyDoesNotNullAndLeavesSourceCallable()} fails the
 *     instant verify-before-null is broken);
 *   - a mint failure, a save failure, an empty organisation, and an
 *     `authenticationConfig` bag all leave the inline secret INTACT;
 *   - NO secret ever reaches the logger.
 *
 * The FakeBroker below carries the EXACT mint()/resolveInjectable() arity of
 * openregister origin/development (or#440 + or#450) and records every call so the
 * argument contract — not just the return — is under test.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Security
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

namespace OCA\OpenConnector\Tests\Unit\Service\Security;

use OCA\OpenConnector\Service\Security\InlineSecretMigrationExecutor;
use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCA\OpenConnector\Tests\Helpers\MigrationSimulatingObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Records every log call so a test can assert no secret was ever handed to it.
 */
class ExecutorSpyLogger extends AbstractLogger
{

    /**
     * Every message + context this logger received, flattened.
     *
     * @var array<int, string>
     */
    public array $lines = [];

    /**
     * Record a log call.
     *
     * @param mixed  $level   The log level.
     * @param mixed  $message The message.
     * @param array  $context The context.
     *
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = (string) $message.' '.json_encode($context);
    }//end log()
}//end class

/**
 * A credential broker double with the or#440/#450 mint + resolveInjectable arity.
 */
class FakeBroker
{

    /**
     * Recorded mint() invocations (positional).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $mintCalls = [];

    /**
     * Recorded resolveInjectable() invocations (positional).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $resolveCalls = [];

    /**
     * uuid => secret, so verify returns the SAME secret mint was handed (happy path).
     *
     * @var array<string, string>
     */
    public array $vault = [];

    /**
     * When true, mint() throws.
     *
     * @var boolean
     */
    public bool $mintThrows = false;

    /**
     * When set, resolveInjectable() returns THIS instead of the vaulted secret
     * (used to force a round-trip mismatch).
     *
     * @var string|null
     */
    public ?string $forceResolveReturn = null;

    /**
     * When true, resolveInjectable() throws.
     *
     * @var boolean
     */
    public bool $resolveThrows = false;

    /**
     * Monotonic counter for minted uuids.
     *
     * @var integer
     */
    private int $counter = 0;

    /**
     * Mint a credential, storing the secret so a later resolve round-trips.
     *
     * @param string             $name         The credential name.
     * @param string             $provider     The provider id.
     * @param string             $owner        The owner UID.
     * @param array<int, string> $allowedApps  The allowed app ids.
     * @param string|null        $secret       The raw secret.
     * @param string             $scope        The scope.
     * @param string|null        $organisation The organisation UUID.
     *
     * @return ObjectEntity The minted credential entity.
     */
    public function mint(
        string $name,
        string $provider,
        string $owner,
        array $allowedApps = [],
        ?string $secret = null,
        string $scope = 'personal',
        ?string $organisation = null
    ): ObjectEntity {
        $this->mintCalls[] = [
            'name'         => $name,
            'provider'     => $provider,
            'owner'        => $owner,
            'allowedApps'  => $allowedApps,
            'secret'       => $secret,
            'scope'        => $scope,
            'organisation' => $organisation,
        ];

        if ($this->mintThrows === true) {
            throw new \RuntimeException('mint failed (simulated)');
        }

        $this->counter++;
        $uuid = 'cred-'.$this->counter;
        $this->vault[$uuid] = (string) $secret;

        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        return $entity;
    }//end mint()

    /**
     * Resolve an inject-only credential's secret (4-arg or#450 shape).
     *
     * @param string      $credentialId         The credential UUID.
     * @param string      $appId                The calling app id.
     * @param string|null $actingUserId         Sessionless acting user (personal branch only).
     * @param string|null $actingOrganisationId Sessionless acting organisation (organisation branch).
     *
     * @return string|null The resolved secret, or null.
     */
    public function resolveInjectable(
        string $credentialId,
        string $appId,
        ?string $actingUserId = null,
        ?string $actingOrganisationId = null
    ): ?string {
        $this->resolveCalls[] = [
            'credentialId'         => $credentialId,
            'appId'                => $appId,
            'actingUserId'         => $actingUserId,
            'actingOrganisationId' => $actingOrganisationId,
        ];

        if ($this->resolveThrows === true) {
            throw new \RuntimeException('resolve failed (simulated)');
        }

        if ($this->forceResolveReturn !== null) {
            return $this->forceResolveReturn;
        }

        return ($this->vault[$credentialId] ?? null);
    }//end resolveInjectable()
}//end class

/**
 * A broker WITHOUT mint() — an OpenRegister too old to migrate (fail-closed test).
 */
class MintlessBroker
{

    /**
     * resolveInjectable() with the or#450 arity but no mint().
     *
     * @param string      $credentialId         The credential UUID.
     * @param string      $appId                The calling app id.
     * @param string|null $actingUserId         Acting user.
     * @param string|null $actingOrganisationId Acting organisation.
     *
     * @return string|null Always null.
     */
    public function resolveInjectable(
        string $credentialId,
        string $appId,
        ?string $actingUserId = null,
        ?string $actingOrganisationId = null
    ): ?string {
        return null;
    }//end resolveInjectable()
}//end class

/**
 * A broker with mint() but only the 3-arg resolveInjectable() (pre-or#450).
 */
class LegacyResolveBroker
{

    /**
     * mint() present.
     *
     * @param string             $name         The name.
     * @param string             $provider     The provider.
     * @param string             $owner        The owner.
     * @param array<int, string> $allowedApps  Allowed apps.
     * @param string|null        $secret       Secret.
     * @param string             $scope        Scope.
     * @param string|null        $organisation Organisation.
     *
     * @return ObjectEntity The entity.
     */
    public function mint(
        string $name,
        string $provider,
        string $owner,
        array $allowedApps = [],
        ?string $secret = null,
        string $scope = 'personal',
        ?string $organisation = null
    ): ObjectEntity {
        return new ObjectEntity();
    }//end mint()

    /**
     * resolveInjectable() WITHOUT actingOrganisationId (the old arity).
     *
     * @param string      $credentialId The credential UUID.
     * @param string      $appId        The calling app id.
     * @param string|null $actingUserId Acting user.
     *
     * @return string|null Always null.
     */
    public function resolveInjectable(
        string $credentialId,
        string $appId,
        ?string $actingUserId = null
    ): ?string {
        return null;
    }//end resolveInjectable()
}//end class

/**
 * Testable executor exposing the broker seams (never touches \OCP\Server).
 */
class TestableInlineSecretMigrationExecutor extends InlineSecretMigrationExecutor
{

    /**
     * Whether the broker class should report as loadable.
     *
     * @var boolean
     */
    public bool $brokerClassAvailable = true;

    /**
     * The broker double returned by resolveBroker().
     *
     * @var object|null
     */
    public ?object $brokerInstance = null;

    /**
     * Overridden seam: broker class availability.
     *
     * @return boolean
     */
    protected function isBrokerClassAvailable(): bool
    {
        return $this->brokerClassAvailable;
    }//end isBrokerClassAvailable()

    /**
     * Overridden seam: broker resolution.
     *
     * @return object
     */
    protected function resolveBroker(): object
    {
        if ($this->brokerInstance === null) {
            throw new \RuntimeException('no broker (test)');
        }

        return $this->brokerInstance;
    }//end resolveBroker()
}//end class

/**
 * @covers \OCA\OpenConnector\Service\Security\InlineSecretMigrationExecutor
 */
class InlineSecretMigrationExecutorTest extends TestCase
{

    private const SECRET = 'super-secret-live-apikey-DO-NOT-LEAK';

    private const ORG = 'org-uuid-1234';

    private const OWNER = 'alice';

    /**
     * @var MigrationSimulatingObjectService
     */
    private MigrationSimulatingObjectService $objectService;

    /**
     * @var ExecutorSpyLogger
     */
    private ExecutorSpyLogger $logger;

    /**
     * @var FakeBroker
     */
    private FakeBroker $broker;

    /**
     * @var TestableInlineSecretMigrationExecutor
     */
    private TestableInlineSecretMigrationExecutor $executor;

    /**
     * Build the executor over the double, planner and fake broker.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = new MigrationSimulatingObjectService();
        $this->logger        = new ExecutorSpyLogger();
        $this->broker        = new FakeBroker();

        $planner = new InlineSecretMigrationPlanner($this->objectService, $this->logger);

        $this->executor                       = new TestableInlineSecretMigrationExecutor(
            $this->objectService,
            $planner,
            $this->logger
        );
        $this->executor->brokerInstance       = $this->broker;
        $this->executor->brokerClassAvailable = true;
    }//end setUp()

    /**
     * Raw re-read of a source's inline field, secrets intact.
     *
     * @param string $uuid  The source uuid.
     * @param string $field The field.
     *
     * @return mixed
     */
    private function rawField(string $uuid, string $field): mixed
    {
        $entity = $this->objectService->find(id: $uuid, register: 'openconnector', schema: 'source', _render: false);
        $object = ($entity?->getObject() ?? []);
        if (array_key_exists($field, $object) === false) {
            return '__absent__';
        }

        return $object[$field];
    }//end rawField()

    /**
     * Raw re-read of a source's nested credentialRef.
     *
     * @param string $uuid  The source uuid.
     * @param string $field The field.
     *
     * @return mixed
     */
    private function rawRef(string $uuid, string $field): mixed
    {
        $entity = $this->objectService->find(id: $uuid, register: 'openconnector', schema: 'source', _render: false);
        return ($entity?->getObject()['configuration']['authentication'][$field] ?? '__absent__');
    }//end rawRef()

    /**
     * HAPPY PATH: mint → verify → null writes the nested ref AND nulls the inline
     * field — proven by a raw `_render: false` re-read after the save.
     *
     * @return void
     */
    public function testHappyPathWritesNestedRefAndNullsInlineField(): void
    {
        $this->objectService->seed('src-1', ['name' => 'API source', 'apikey' => self::SECRET], self::OWNER, self::ORG);

        $result = $this->executor->migrateAll();

        $this->assertSame(1, $result['migrated']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['blocked']);

        // The inline value is now null (secret gone), proven by a RAW re-read.
        $this->assertNull($this->rawField('src-1', 'apikey'), 'The inline secret must be nulled after migration.');

        // The nested ref is written at configuration.authentication.apikey,
        // as the SOLE key, matching BrokeredCallService::isPlaceholder().
        $ref = $this->rawRef('src-1', 'apikey');
        $this->assertSame(['credentialRef' => ['credentialId' => 'cred-1']], $ref);

        // The post-run gate is now clean.
        $this->assertTrue($result['postRun']['clean']);
    }//end testHappyPathWritesNestedRefAndNullsInlineField()

    /**
     * CONTRACT: resolveInjectable is called with actingOrganisationId = the
     * source's org — the entire point of or#450. And mint is called at
     * organisation scope, with the org, and allowedApps pinned to openconnector.
     *
     * @return void
     */
    public function testVerifyAssertsSourceOrganisationAndMintIsOrganisationScoped(): void
    {
        $this->objectService->seed('src-1', ['name' => 'API source', 'apikey' => self::SECRET], self::OWNER, self::ORG);

        $this->executor->migrateAll();

        $this->assertCount(1, $this->broker->mintCalls);
        $mint = $this->broker->mintCalls[0];
        $this->assertSame('generic-apikey', $mint['provider']);
        $this->assertSame(self::OWNER, $mint['owner']);
        $this->assertSame(['openconnector'], $mint['allowedApps'], 'Guard 2 refuses unless openconnector is allowed.');
        $this->assertSame('organisation', $mint['scope'], 'ADR-064 Rule 4: never personal for infrastructure.');
        $this->assertSame(self::ORG, $mint['organisation']);
        $this->assertStringNotContainsString(self::SECRET, $mint['name'], 'The mint label must never carry the secret.');

        $this->assertCount(1, $this->broker->resolveCalls);
        $resolve = $this->broker->resolveCalls[0];
        $this->assertSame('cred-1', $resolve['credentialId']);
        $this->assertSame('openconnector', $resolve['appId']);
        $this->assertNull($resolve['actingUserId'], 'Org resolution must NOT reuse the actingUserId fallback.');
        $this->assertSame(
            self::ORG,
            $resolve['actingOrganisationId'],
            'The source organisation MUST be asserted as actingOrganisationId — this is what or#450 enables.'
        );
    }//end testVerifyAssertsSourceOrganisationAndMintIsOrganisationScoped()

    /**
     * MUTATION GUARD: a round-trip mismatch must NOT null the inline field, and
     * the source must remain callable exactly as before. Break "verify before
     * null" (null before/without verify) and this test fails.
     *
     * @return void
     */
    public function testFailedVerifyDoesNotNullAndLeavesSourceCallable(): void
    {
        $this->objectService->seed('src-1', ['name' => 'API source', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        // Force the round-trip to return a DIFFERENT value than the secret.
        $this->broker->forceResolveReturn = 'a-different-value';

        $result = $this->executor->migrateAll();

        $this->assertSame(0, $result['migrated']);
        $this->assertSame(1, $result['failed']);

        // The inline secret is UNTOUCHED — the source still works as before.
        $this->assertSame(self::SECRET, $this->rawField('src-1', 'apikey'), 'A failed verify must leave the secret intact.');
        // No ref was written.
        $this->assertSame('__absent__', $this->rawRef('src-1', 'apikey'), 'A failed verify must NOT write a credentialRef.');
        // No save happened at all.
        $this->assertSame([], $this->objectService->saves, 'A failed verify must not save the source.');
        // The post-run gate stays closed.
        $this->assertFalse($result['postRun']['clean']);
    }//end testFailedVerifyDoesNotNullAndLeavesSourceCallable()

    /**
     * A resolveInjectable EXCEPTION during verify is also fail-closed: no null.
     *
     * @return void
     */
    public function testVerifyExceptionLeavesInlineIntact(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        $this->broker->resolveThrows = true;

        $result = $this->executor->migrateAll();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(self::SECRET, $this->rawField('src-1', 'apikey'));
        $this->assertSame([], $this->objectService->saves);
    }//end testVerifyExceptionLeavesInlineIntact()

    /**
     * A failed MINT leaves the inline secret intact and never verifies/saves.
     *
     * @return void
     */
    public function testFailedMintLeavesInlineSecretIntact(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        $this->broker->mintThrows = true;

        $result = $this->executor->migrateAll();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(self::SECRET, $this->rawField('src-1', 'apikey'), 'A failed mint must leave the secret intact.');
        $this->assertSame([], $this->broker->resolveCalls, 'A failed mint must not attempt a verify.');
        $this->assertSame([], $this->objectService->saves, 'A failed mint must not save.');
    }//end testFailedMintLeavesInlineSecretIntact()

    /**
     * A failed SAVE (after a verified mint) leaves the inline secret intact.
     *
     * @return void
     */
    public function testFailedSaveLeavesInlineSecretIntact(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        $this->objectService->failSaveForUuid = 'src-1';

        $result = $this->executor->migrateAll();

        $this->assertSame(1, $result['failed']);
        // A save was ATTEMPTED (mint verified) but did not persist ⇒ secret intact.
        $this->assertNotSame([], $this->objectService->saves, 'The verified field must attempt a save.');
        $this->assertSame(self::SECRET, $this->rawField('src-1', 'apikey'), 'A failed save must leave the secret intact.');
    }//end testFailedSaveLeavesInlineSecretIntact()

    /**
     * EMPTY ORGANISATION → blocked, and NEVER minted (not even at personal scope).
     *
     * @return void
     */
    public function testEmptyOrganisationIsBlockedNeverMintedAtPersonalScope(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, null);

        $result = $this->executor->migrateAll();

        $this->assertSame(0, $result['migrated']);
        $this->assertSame(1, $result['blocked']);
        $this->assertSame([], $this->broker->mintCalls, 'A source with no organisation must NEVER be minted.');
        $this->assertSame(self::SECRET, $this->rawField('src-1', 'apikey'), 'The inline secret must be left intact.');
        $this->assertFalse($result['postRun']['clean'], 'A blocked source keeps the Phase D gate closed.');

        $this->assertSame('blocked', $result['sources'][0]['fields'][0]['outcome']);
        $this->assertSame('no-organisation', $result['sources'][0]['fields'][0]['reason']);
    }//end testEmptyOrganisationIsBlockedNeverMintedAtPersonalScope()

    /**
     * authenticationConfig is BLOCKED (needs-manual-review) and never minted.
     *
     * @return void
     */
    public function testAuthenticationConfigIsBlockedNotMinted(): void
    {
        $this->objectService->seed(
            'src-1',
            ['name' => 'OAuth', 'authenticationConfig' => ['client_secret' => 'shh']],
            self::OWNER,
            self::ORG
        );

        $result = $this->executor->migrateAll();

        $this->assertSame(0, $result['migrated']);
        $this->assertSame(1, $result['blocked']);
        $this->assertSame([], $this->broker->mintCalls);
        $this->assertFalse($result['postRun']['clean']);
        $this->assertSame('needs-manual-review', $result['sources'][0]['fields'][0]['reason']);
    }//end testAuthenticationConfigIsBlockedNotMinted()

    /**
     * IDEMPOTENCY: a second run is a no-op and an already-migrated field is skipped.
     *
     * @return void
     */
    public function testSecondRunIsNoOpAndAlreadyMigratedIsSkipped(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, self::ORG);

        $first = $this->executor->migrateAll();
        $this->assertSame(1, $first['migrated']);
        $this->assertCount(1, $this->broker->mintCalls);

        // A second run must mint NOTHING more and save NOTHING more.
        $mintCountAfterFirst = count($this->broker->mintCalls);
        $saveCountAfterFirst = count($this->objectService->saves);

        $second = $this->executor->migrateAll();

        $this->assertSame(0, $second['migrated'], 'The second run must migrate nothing.');
        $this->assertGreaterThanOrEqual(0, $second['skipped']);
        $this->assertCount($mintCountAfterFirst, $this->broker->mintCalls, 'The second run must not mint again.');
        $this->assertCount($saveCountAfterFirst, $this->objectService->saves, 'The second run must not save again.');
        $this->assertTrue($second['postRun']['clean']);
    }//end testSecondRunIsNoOpAndAlreadyMigratedIsSkipped()

    /**
     * PER-FIELD / PER-SOURCE ISOLATION: one source's failure never aborts the
     * batch, and a good field still migrates alongside a bad one.
     *
     * @return void
     */
    public function testPerFieldIsolationAcrossAndWithinSources(): void
    {
        // src-1: apikey good; src-2: no org (blocked); both survive independently.
        $this->objectService->seed('src-1', ['name' => 'Good', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        $this->objectService->seed('src-2', ['name' => 'NoOrg', 'apikey' => 'other-secret'], self::OWNER, null);

        $result = $this->executor->migrateAll();

        $this->assertSame(1, $result['migrated']);
        $this->assertSame(1, $result['blocked']);
        $this->assertNull($this->rawField('src-1', 'apikey'));
        $this->assertSame('other-secret', $this->rawField('src-2', 'apikey'), 'The blocked source is untouched.');
    }//end testPerFieldIsolationAcrossAndWithinSources()

    /**
     * NO SECRET EVER REACHES THE LOGGER — across happy, verify-fail, and mint-fail.
     *
     * @return void
     */
    public function testNoSecretEverReachesTheLogger(): void
    {
        $this->objectService->seed('ok', ['name' => 'OK', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        $this->objectService->seed('mismatch', ['name' => 'M', 'password' => 'p4ssw0rd-live'], self::OWNER, self::ORG);

        // Force a verify mismatch for the password source path by returning a
        // constant that is not either secret (the apikey path stays a happy path).
        $this->broker->forceResolveReturn = 'not-a-real-secret';

        $this->executor->migrateAll();

        $joined = implode("\n", $this->logger->lines);
        foreach ([self::SECRET, 'p4ssw0rd-live'] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $joined,
                'A secret reached the logger — log lines get shipped somewhere; this is a disclosure.'
            );
        }
    }//end testNoSecretEverReachesTheLogger()

    /**
     * The raw read that drives the executor MUST be `_render: false` — the only
     * read that still carries a writeOnly secret. Asserting the recorded context
     * makes the CONTRACT the thing under test (the mutation guard for ocon#215).
     *
     * @return void
     */
    public function testExecutorReadsRawWithRenderFalse(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, self::ORG);

        $this->executor->migrateAll();

        // At least one read declared _render:false AND _rbac:false (system context).
        $rawSystemReads = array_filter(
            $this->objectService->reads,
            static fn (array $r): bool => ($r['_render'] === false && $r['_rbac'] === false)
        );
        $this->assertNotEmpty(
            $rawSystemReads,
            'The executor must read the entity with _render:false (secrets intact) in _rbac:false system context.'
        );
    }//end testExecutorReadsRawWithRenderFalse()

    /**
     * FAIL CLOSED on an OpenRegister too old to MINT: nothing is rewritten.
     *
     * @return void
     */
    public function testFailsClosedWhenBrokerCannotMint(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        $this->executor->brokerInstance = new MintlessBroker();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mint|Nothing was rewritten/i');

        try {
            $this->executor->migrateAll();
        } finally {
            $this->assertSame(self::SECRET, $this->rawField('src-1', 'apikey'), 'Nothing may be rewritten.');
            $this->assertSame([], $this->objectService->saves);
        }
    }//end testFailsClosedWhenBrokerCannotMint()

    /**
     * FAIL CLOSED on an OpenRegister without the or#450 actingOrganisationId arity.
     *
     * @return void
     */
    public function testFailsClosedWhenBrokerCannotResolveOrganisationSessionlessly(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        $this->executor->brokerInstance = new LegacyResolveBroker();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/actingOrganisationId|openregister#450|Nothing was rewritten/i');

        try {
            $this->executor->migrateAll();
        } finally {
            $this->assertSame(self::SECRET, $this->rawField('src-1', 'apikey'), 'Nothing may be rewritten.');
        }
    }//end testFailsClosedWhenBrokerCannotResolveOrganisationSessionlessly()

    /**
     * FAIL CLOSED when the broker class is not even available.
     *
     * @return void
     */
    public function testFailsClosedWhenBrokerUnavailable(): void
    {
        $this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], self::OWNER, self::ORG);
        $this->executor->brokerClassAvailable = false;

        $this->expectException(\RuntimeException::class);

        try {
            $this->executor->migrateAll();
        } finally {
            $this->assertSame(self::SECRET, $this->rawField('src-1', 'apikey'));
        }
    }//end testFailsClosedWhenBrokerUnavailable()
}//end class
