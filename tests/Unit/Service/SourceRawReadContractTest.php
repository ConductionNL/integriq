<?php

/**
 * The raw-source-read contract for the six direct-Guzzle clients (ocon#242).
 *
 * These six bypass {@see \OCA\OpenConnector\Service\CallService} entirely, so they
 * never benefit from its `_render: false` re-resolve (`resolveSourceForDispatch`,
 * ocon#236). Each located its active source through a RENDERED `findAll()` and then
 * decrypted `configuration.authentication.encryptedToken` / `mtls.*` itself — which
 * is why ocon#241 could not declare those paths write-only without taking all six
 * bridges down.
 *
 * WHAT THIS TEST PROVES: with the nested write-only boundary SIMULATED AS IF THE
 * PATHS WERE ALREADY DECLARED, every one of the six still resolves a source that
 * carries its credential. That is the blocker in ocon#242 being cleared.
 *
 * WHY IT CANNOT BE FAKED GREEN: {@see NestedWriteOnlyRenderBoundaryObjectService} is
 * a fake, not a mock. Its `findAll()` ALWAYS strips (the real `findAll()` has no
 * `_render` parameter — it renders unconditionally), and its `find()` strips unless
 * the caller passed `_render: false`. So the ARGUMENT is under test, not a stub's
 * return value. MUTATION GUARD: revert any `resolveActiveSource()` to
 * `return $results[0];` and {@see testResolveActiveSourceSurvivesTheWriteOnlyBoundary}
 * fails for that client, showing it dispatching with no token.
 *
 * NOTE the strip is deliberately NOT gated on `_rbac` here — mirroring
 * openregister#389's `schemaHasWriteOnlyRule()`, which gates on the SCHEMA only.
 * An `_rbac: false` read does NOT bring the secret back; that confusion shipped
 * three dead fixes (ocon#212/#226) and once sent webhooks out unsigned for weeks.
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
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\Dso\DsoClient;
use OCA\OpenConnector\Service\Dso\DsoVerzoekTranslator;
use OCA\OpenConnector\Service\Dso\LogDsoConnectorProvider;
use OCA\OpenConnector\Service\DsoIngestService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\Fsc\FscDirectoryClient;
use OCA\OpenConnector\Service\Fsc\LogFscConnectivityProvider;
use OCA\OpenConnector\Service\FscCallService;
use OCA\OpenConnector\Service\IwmoIjw\InboundRetourTranslator;
use OCA\OpenConnector\Service\IwmoIjw\IStandaardenClient;
use OCA\OpenConnector\Service\IwmoIjw\LogIwmoIjwProvider;
use OCA\OpenConnector\Service\IwmoIjw\OutboundBerichtTranslator;
use OCA\OpenConnector\Service\IwmoIjwSyncService;
use OCA\OpenConnector\Service\Kiss\KlantinteractiesClient;
use OCA\OpenConnector\Service\Kiss\LogKlantinteractiesProvider;
use OCA\OpenConnector\Service\KissSyncService;
use OCA\OpenConnector\Service\Security\RawSourceResolver;
use OCA\OpenConnector\Service\Sms\LogSmsProvider;
use OCA\OpenConnector\Service\Sms\RestNotifyNlProvider;
use OCA\OpenConnector\Service\SmsDispatchService;
use OCA\OpenConnector\Service\StufZkn\InboundBerichtTranslator;
use OCA\OpenConnector\Service\StufZkn\LogStufZknProvider;
use OCA\OpenConnector\Service\StufZkn\OutboundKennisgevingTranslator;
use OCA\OpenConnector\Service\StufZkn\StufZknAcknowledgementBuilder;
use OCA\OpenConnector\Service\StufZkn\StufZknClient;
use OCA\OpenConnector\Service\StufZknSyncService;
use OCA\OpenConnector\Tests\Helpers\NestedWriteOnlyRenderBoundaryObjectService;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SourceRawReadContractTest extends TestCase
{

    /**
     * The paths ocon#242 needs to be able to declare write-only on `source`.
     *
     * @var array<int, string>
     */
    private const CANDIDATE_PATHS = [
        'configuration.authentication.encryptedToken',
        'configuration.authentication.mtls',
    ];

    /**
     * The uuid of the single active source in each scenario.
     *
     * @var string
     */
    private const SOURCE_UUID = 'source-uuid-1';

    /**
     * A source exactly as an operator authors it, secrets included.
     *
     * @param string $type The source `type` discriminator.
     *
     * @return array<string, mixed> The raw source object.
     */
    private function rawSource(string $type): array
    {
        return [
            'name'          => 'Active '.$type.' source',
            'type'          => $type,
            'isEnabled'     => true,
            'configuration' => [
                'provider'       => 'rest',
                'location'       => 'https://example.test/api',
                'authentication' => [
                    'mode'           => 'token',
                    'encryptedToken' => 'CIPHERTEXT-TOKEN-BLOB',
                    'mtls'           => [
                        'encryptedCertificate' => 'CIPHERTEXT-CERT',
                        'encryptedPrivateKey'  => 'CIPHERTEXT-KEY',
                        'encryptedPassphrase'  => 'CIPHERTEXT-PASSPHRASE',
                    ],
                ],
            ],
        ];
    }//end rawSource()

    /**
     * An ObjectService fake with one active source, behind the nested write-only boundary.
     *
     * @param string $type The source `type`.
     *
     * @return NestedWriteOnlyRenderBoundaryObjectService The fake.
     */
    private function objectService(string $type): NestedWriteOnlyRenderBoundaryObjectService
    {
        $fake = new NestedWriteOnlyRenderBoundaryObjectService(self::CANDIDATE_PATHS);
        $fake->stored[self::SOURCE_UUID] = $this->rawSource($type);
        return $fake;
    }//end objectService()

    /**
     * A localization mock that echoes its input.
     *
     * @return IL10N The mock.
     */
    private function l10n(): IL10N
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        return $l;
    }//end l10n()

    /**
     * Build each of the six services against the given fake.
     *
     * @param string                                     $client The client key.
     * @param NestedWriteOnlyRenderBoundaryObjectService $fake   The object service fake.
     *
     * @return object The service exposing resolveActiveSource().
     */
    private function buildService(string $client, NestedWriteOnlyRenderBoundaryObjectService $fake): object
    {
        $logger   = $this->createMock(LoggerInterface::class);
        $resolver = new RawSourceResolver($fake, $logger);

        return match ($client) {
            'IStandaarden' => new IwmoIjwSyncService(
                $fake,
                $this->createMock(LogIwmoIjwProvider::class),
                $this->createMock(IStandaardenClient::class),
                new OutboundBerichtTranslator(),
                new InboundRetourTranslator(),
                $this->l10n(),
                $logger,
                $resolver
            ),
            'Dso' => new DsoIngestService(
                $fake,
                $this->getMockBuilder(HandoffService::class)->disableOriginalConstructor()->getMock(),
                new DsoVerzoekTranslator(),
                new LogDsoConnectorProvider(),
                $this->getMockBuilder(DsoClient::class)->disableOriginalConstructor()->getMock(),
                $logger,
                $resolver
            ),
            'Kiss' => new KissSyncService(
                $fake,
                $this->createMock(LogKlantinteractiesProvider::class),
                $this->getMockBuilder(KlantinteractiesClient::class)->disableOriginalConstructor()->getMock(),
                $this->l10n(),
                $logger,
                $resolver
            ),
            'StufZkn' => new StufZknSyncService(
                $fake,
                $this->createMock(LogStufZknProvider::class),
                $this->getMockBuilder(StufZknClient::class)->disableOriginalConstructor()->getMock(),
                new InboundBerichtTranslator(),
                new OutboundKennisgevingTranslator(),
                new StufZknAcknowledgementBuilder(),
                $logger,
                $resolver
            ),
            'NotifyNl' => new SmsDispatchService(
                $fake,
                $this->createMock(LogSmsProvider::class),
                $this->getMockBuilder(RestNotifyNlProvider::class)->disableOriginalConstructor()->getMock(),
                $this->getMockBuilder(EventService::class)->disableOriginalConstructor()->getMock(),
                $this->l10n(),
                $logger,
                $resolver
            ),
            'Fsc' => new FscCallService(
                $fake,
                $this->createMock(LogFscConnectivityProvider::class),
                $this->getMockBuilder(FscDirectoryClient::class)->disableOriginalConstructor()->getMock(),
                $resolver
            ),
        };
    }//end buildService()

    /**
     * The six clients named by ocon#242 and their source `type` discriminator.
     *
     * @return array<string, array{0: string, 1: string}> The cases.
     */
    public static function clientProvider(): array
    {
        return [
            'IStandaarden (iWMO/iJW)' => ['IStandaarden', IwmoIjwSyncService::SOURCE_TYPE],
            'Dso'                     => ['Dso', DsoIngestService::SOURCE_TYPE],
            'Kiss'                    => ['Kiss', KissSyncService::SOURCE_TYPE],
            'StufZkn'                 => ['StufZkn', StufZknSyncService::SOURCE_TYPE],
            'Fsc'                     => ['Fsc', FscCallService::SOURCE_TYPE],
            'NotifyNL (SMS)'          => ['NotifyNl', SmsDispatchService::SOURCE_TYPE],
        ];
    }//end clientProvider()

    /**
     * Each client's resolveActiveSource() must return a source whose credentials
     * survived the write-only render boundary.
     *
     * MUTATION GUARD: revert the client to `return $results[0];` (the rendered
     * findAll() row) and this fails — encryptedToken and mtls are both gone, which
     * is the bridge dispatching unauthenticated.
     *
     * @param string $client The client key.
     * @param string $type   The source type discriminator.
     *
     * @return void
     *
     * @dataProvider clientProvider
     */
    public function testResolveActiveSourceSurvivesTheWriteOnlyBoundary(string $client, string $type): void
    {
        $fake    = $this->objectService($type);
        $service = $this->buildService($client, $fake);

        $source = $service->resolveActiveSource();
        $auth   = ($source->getObject()['configuration']['authentication'] ?? []);

        $this->assertSame(
            'CIPHERTEXT-TOKEN-BLOB',
            ($auth['encryptedToken'] ?? null),
            $client.' lost configuration.authentication.encryptedToken across the render boundary — '
                .'it would dispatch with no Authorization header. It must re-read the source with _render: false.'
        );

        $this->assertSame(
            [
                'encryptedCertificate' => 'CIPHERTEXT-CERT',
                'encryptedPrivateKey'  => 'CIPHERTEXT-KEY',
                'encryptedPassphrase'  => 'CIPHERTEXT-PASSPHRASE',
            ],
            ($auth['mtls'] ?? null),
            $client.' lost the configuration.authentication.mtls sub-tree across the render boundary — '
                .'a mode=mtls source would fail its handshake.'
        );
    }//end testResolveActiveSourceSurvivesTheWriteOnlyBoundary()

    /**
     * The re-read must be `_render: false` — and must NOT widen access.
     *
     * Asserts the ARGUMENTS, not a stub's return value. `_rbac: false` would look
     * like a fix (it is the one that has repeatedly been mistaken for one) while
     * both failing to restore the secret AND handing the caller an object it may
     * not be entitled to. The caller's findAll() already located this source under
     * rbac/multitenancy, so the re-read stays under them.
     *
     * @param string $client The client key.
     * @param string $type   The source type discriminator.
     *
     * @return void
     *
     * @dataProvider clientProvider
     */
    public function testRawReReadUsesRenderFalseAndDoesNotWidenAccess(string $client, string $type): void
    {
        $fake    = $this->objectService($type);
        $service = $this->buildService($client, $fake);

        $service->resolveActiveSource();

        $sourceReads = array_values(
            array_filter($fake->reads, static fn (array $r): bool => $r['uuid'] === self::SOURCE_UUID)
        );

        $this->assertNotEmpty(
            $sourceReads,
            $client.' never re-read the located source by uuid; a rendered findAll() row is all it has.'
        );

        $rawReads = array_values(
            array_filter($sourceReads, static fn (array $r): bool => $r['_render'] === false)
        );

        $this->assertCount(
            1,
            $rawReads,
            $client.' must re-read the source EXACTLY once with _render: false.'
        );

        $this->assertSame('openconnector', $rawReads[0]['register'], $client.' re-read the wrong register.');
        $this->assertSame('source', $rawReads[0]['schema'], $client.' re-read the wrong schema.');
        $this->assertTrue($rawReads[0]['_rbac'], $client.' must NOT disable rbac to get the secret — _render is the load-bearing argument.');
        $this->assertTrue($rawReads[0]['_multitenancy'], $client.' must NOT disable multitenancy to get the secret.');
    }//end testRawReReadUsesRenderFalseAndDoesNotWidenAccess()

    /**
     * The boundary fake is honest: a RENDERED read really does lose the secrets.
     *
     * Without this, every assertion above could pass against a fake that simply
     * never strips anything — the failure mode this whole class exists to prevent.
     *
     * @return void
     */
    public function testTheFakeActuallyStripsOnARenderedRead(): void
    {
        $fake = $this->objectService('kiss');

        $rendered = $fake->findAll(config: ['filters' => ['schema' => 'source']])['results'][0];
        $auth     = ($rendered->getObject()['configuration']['authentication'] ?? []);

        $this->assertArrayNotHasKey('encryptedToken', $auth, 'The rendered findAll() row must lose encryptedToken.');
        $this->assertArrayNotHasKey('mtls', $auth, 'The rendered findAll() row must lose the whole mtls sub-tree.');
        $this->assertSame('token', ($auth['mode'] ?? null), 'A non-declared sibling must survive the strip.');

        // An `_rbac: false` read is NOT an escape: the strip is schema-gated.
        $rbacOff = $fake->find(id: self::SOURCE_UUID, register: 'openconnector', schema: 'source', _rbac: false);
        $this->assertArrayNotHasKey(
            'encryptedToken',
            ($rbacOff->getObject()['configuration']['authentication'] ?? []),
            '_rbac: false must NOT return the secret — only _render: false does.'
        );
    }//end testTheFakeActuallyStripsOnARenderedRead()
}//end class
