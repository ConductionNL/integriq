<?php
/**
 * Unit tests for EudiCredentialOfferService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use DateTime;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\OpenConnector\Exception\EudiIssuanceException;
use OCA\OpenConnector\Service\EudiCredentialOfferService;
use OCA\OpenConnector\Service\EudiIssuerKeyService;
use OCA\OpenConnector\Service\EudiStatusListService;
use OCA\OpenConnector\Service\OrganisationBridgeService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the offer creation/resolution, token exchange, credential
 * issuance (both format dispatch branches), and revocation lifecycle.
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md
 */
class EudiCredentialOfferServiceTest extends TestCase
{

    /**
     * In-memory "database" of OR object rows keyed by uuid, shared across
     * every schema this service touches (offer/session/status-list).
     *
     * @var array<string, array>
     */
    private array $rows = [];

    /**
     * In-memory transient cache backing the offer's pre-authorized_code hold.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * Captured outbound HTTP POSTs made by the revocation status callback.
     *
     * @var array<int, array{uri: string, options: array}>
     */
    private array $sentRequests = [];

    /**
     * Build a fully-wired EudiCredentialOfferService against in-memory fakes.
     *
     * @return EudiCredentialOfferService
     */
    private function makeService(): EudiCredentialOfferService
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function ($id, ...$rest) {
                if (isset($this->rows[$id]) === false) {
                    throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
                }

                $entity = new ObjectEntity();
                $entity->setUuid($id);
                $entity->setObject($this->rows[$id]);
                return $entity;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function ($config=[]) {
                $filters = ($config['filters'] ?? []);
                $results = [];
                foreach ($this->rows as $uuid => $data) {
                    $match = true;
                    foreach ($filters as $key => $value) {
                        if (in_array($key, ['register', 'schema'], true) === true) {
                            continue;
                        }

                        if (($data[$key] ?? null) !== $value) {
                            $match = false;
                            break;
                        }
                    }

                    if ($match === false) {
                        continue;
                    }

                    $entity = new ObjectEntity();
                    $entity->setUuid($uuid);
                    $entity->setObject($data);
                    $results[] = $entity;
                }

                return ['results' => $results];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function ($object=[], $register=null, $schema=null, $uuid=null) {
                if ($uuid === null) {
                    $uuid = bin2hex(random_bytes(8));
                }

                $object['uuid']    = $uuid;
                $this->rows[$uuid] = $object;

                $entity = new ObjectEntity();
                $entity->setUuid($uuid);
                $entity->setObject($object);
                return $entity;
            }
        );

        $appConfigStore = [];
        $appConfig      = $this->createMock(\OCP\IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') use (&$appConfigStore) {
                return ($appConfigStore[$app.'.'.$key] ?? $default);
            }
        );
        $appConfig->method('setValueString')->willReturnCallback(
            static function (string $app, string $key, string $value, bool $lazy=false, bool $sensitive=false) use (&$appConfigStore) {
                $appConfigStore[$app.'.'.$key] = $value;
                return true;
            }
        );

        $crypto = $this->createMock(\OCP\Security\ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(static fn (string $p): string => 'enc:'.base64_encode($p));
        $crypto->method('decrypt')->willReturnCallback(static fn (string $c): string => base64_decode(substr($c, strlen('enc:'))));

        $keyService        = new EudiIssuerKeyService($appConfig, $crypto, new NullLogger());
        $statusListService = new EudiStatusListService($objectService, $keyService, new NullLogger());
        $signatureService  = new WebhookSignatureService(new NullLogger());

        $organisationBridge = $this->createMock(OrganisationBridgeService::class);
        $organisationBridge->method('getActiveOrganisation')->willReturn(null);

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options=[]) {
                $this->sentRequests[] = ['uri' => $uri, 'options' => $options];
                return $this->createMock(\OCP\Http\Client\IResponse::class);
            }
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn ($key) => ($this->cache[$key] ?? null));
        $cache->method('set')->willReturnCallback(
            function ($key, $value, $ttl=0) {
                $this->cache[$key] = $value;
                return true;
            }
        );

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return new EudiCredentialOfferService(
            $objectService,
            $keyService,
            $statusListService,
            $signatureService,
            $organisationBridge,
            $clientService,
            $cacheFactory,
            new NullLogger()
        );

    }//end makeService()

    /**
     * A missing required field is rejected with 400 and persists nothing.
     *
     * @return void
     */
    public function testCreateOfferRejectsMissingFieldsWithNoPersistedRow(): void
    {
        $service = $this->makeService();

        try {
            $service->createOffer(['format' => 'jwt_vc_json'], 'consumer-1');
            $this->fail('expected EudiIssuanceException');
        } catch (EudiIssuanceException $exception) {
            $this->assertSame(400, $exception->getHttpStatus());
        }

        $this->assertCount(0, $this->rows, 'no eudi_credential_offer row may be persisted on a 400');

    }//end testCreateOfferRejectsMissingFieldsWithNoPersistedRow()

    /**
     * A valid offer is created; the pre-authorized_code is persisted only as a hash.
     *
     * @return void
     */
    public function testCreateOfferPersistsOnlyTheCodeHash(): void
    {
        $service = $this->makeService();

        $result = $service->createOffer(
            [
                'format'            => 'jwt_vc_json',
                'subjectId'         => 'learner-1',
                'credentialPayload' => 'header.payload.signature',
            ],
            'consumer-1'
        );

        $this->assertArrayHasKey('uuid', $result);
        $this->assertArrayHasKey('offerCode', $result);

        $row = $this->rows[$result['uuid']];
        $this->assertSame(hash('sha256', $result['offerCode']), $row['preAuthorizedCodeHash']);
        $this->assertArrayNotHasKey('preAuthorizedCode', $row);
        $this->assertSame('created', $row['status']);

    }//end testCreateOfferPersistsOnlyTheCodeHash()

    /**
     * A fresh offer resolves exactly once — the second fetch is unresolvable.
     *
     * @return void
     */
    public function testOfferResolvesExactlyOnce(): void
    {
        $service = $this->makeService();
        $result  = $service->createOffer(
            ['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'],
            'consumer-1'
        );

        $first = $service->resolveOfferForWallet($result['uuid']);
        $this->assertNotNull($first);
        $this->assertArrayHasKey('grants', $first);

        $second = $service->resolveOfferForWallet($result['uuid']);
        $this->assertNull($second, 'a second fetch of an already-consumed offer must be unresolvable');

    }//end testOfferResolvesExactlyOnce()

    /**
     * An expired (never-fetched) offer is unresolvable.
     *
     * @return void
     */
    public function testExpiredOfferIsUnresolvableEvenIfNeverFetched(): void
    {
        $service = $this->makeService();
        $result  = $service->createOffer(
            ['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'],
            'consumer-1'
        );

        $this->rows[$result['uuid']]['expiresAt'] = (new DateTime('-1 minute'))->format('c');

        $this->assertNull($service->resolveOfferForWallet($result['uuid']));

    }//end testExpiredOfferIsUnresolvableEvenIfNeverFetched()

    /**
     * A valid pre-authorized_code exchanges for exactly one access token; a
     * second exchange of the same code is rejected as invalid_grant (atomic
     * consume-on-read replay rejection, REQ-EUDI-006).
     *
     * @return void
     */
    public function testTokenExchangeIsSingleUseAtomicReplayRejection(): void
    {
        $service = $this->makeService();
        $offer   = $service->createOffer(
            ['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'],
            'consumer-1'
        );

        $data = [
            'grant_type'           => 'urn:ietf:params:oauth:grant-type:pre-authorized_code',
            'pre-authorized_code'  => $offer['offerCode'],
        ];

        $first = $service->exchangeToken($data);
        $this->assertArrayHasKey('access_token', $first);
        $this->assertArrayHasKey('c_nonce', $first);

        try {
            $service->exchangeToken($data);
            $this->fail('expected a replayed pre-authorized_code to be rejected');
        } catch (EudiIssuanceException $exception) {
            $this->assertSame('invalid_grant', $exception->getErrorCode());
        }

    }//end testTokenExchangeIsSingleUseAtomicReplayRejection()

    /**
     * A wrong tx_code does NOT consume the pre-authorized_code — a
     * subsequent correctly-PINned attempt still succeeds.
     *
     * @return void
     */
    public function testWrongTxCodeDoesNotConsumeTheCode(): void
    {
        $service = $this->makeService();
        $offer   = $service->createOffer(
            [
                'format'            => 'jwt_vc_json',
                'subjectId'         => 'learner-1',
                'credentialPayload' => 'abc',
                'txCode'            => '1234',
            ],
            'consumer-1'
        );

        $wrongAttempt = [
            'grant_type'          => 'urn:ietf:params:oauth:grant-type:pre-authorized_code',
            'pre-authorized_code' => $offer['offerCode'],
            'tx_code'             => '0000',
        ];

        try {
            $service->exchangeToken($wrongAttempt);
            $this->fail('expected a wrong tx_code to be rejected');
        } catch (EudiIssuanceException $exception) {
            $this->assertSame('invalid_grant', $exception->getErrorCode());
        }

        $correctAttempt              = $wrongAttempt;
        $correctAttempt['tx_code']   = '1234';
        $result                      = $service->exchangeToken($correctAttempt);
        $this->assertArrayHasKey('access_token', $result, 'the code must still be usable after one wrong tx_code attempt');

    }//end testWrongTxCodeDoesNotConsumeTheCode()

    /**
     * Build a valid `proof.jwt` (self-signed by a fresh wallet key) whose
     * `nonce` matches the given c_nonce.
     *
     * @param string $cNonce The session's current c_nonce to embed.
     *
     * @return string The compact-serialized proof JWT.
     */
    private function buildProofJwt(string $cNonce): string
    {
        $walletKey = JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig']);
        $publicJwk = $walletKey->toPublic()->jsonSerialize();

        $algorithmManager = new AlgorithmManager([new ES256()]);
        $jwsBuilder       = new JWSBuilder($algorithmManager);

        $jws = $jwsBuilder->create()
            ->withPayload(json_encode(['nonce' => $cNonce, 'iat' => time()], JSON_UNESCAPED_SLASHES))
            ->addSignature($walletKey, ['alg' => 'ES256', 'typ' => 'openid4vci-proof+jwt', 'jwk' => $publicJwk])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);

    }//end buildProofJwt()

    /**
     * `jwt_vc_json` is returned byte-identical to the payload supplied at
     * offer creation — never re-signed (design.md D-SIGN).
     *
     * @return void
     */
    public function testJwtVcJsonIsReturnedVerbatim(): void
    {
        $service = $this->makeService();
        $payload = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJsZWFybmVyLTEifQ.signature';

        $offer = $service->createOffer(
            ['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => $payload],
            'consumer-1'
        );
        $token = $service->exchangeToken(
            [
                'grant_type'          => 'urn:ietf:params:oauth:grant-type:pre-authorized_code',
                'pre-authorized_code' => $offer['offerCode'],
            ]
        );

        $proof  = $this->buildProofJwt($token['c_nonce']);
        $result = $service->issueCredential($token['access_token'], ['proof' => ['jwt' => $proof]]);

        $this->assertSame('jwt_vc_json', $result['format']);
        $this->assertSame($payload, $result['credential'], 'jwt_vc_json must be returned byte-identical, never re-signed');

    }//end testJwtVcJsonIsReturnedVerbatim()

    /**
     * `dc+sd-jwt` is minted and signed with the issuer's own active key.
     *
     * @return void
     */
    public function testDcSdJwtIsMintedAndSignedWithIssuerKey(): void
    {
        $service = $this->makeService();
        $claims  = ['achievement' => 'Certified Widget Assembly', 'level' => 3];

        $offer = $service->createOffer(
            [
                'format'            => 'dc+sd-jwt',
                'subjectId'         => 'learner-1',
                'credentialPayload' => $claims,
            ],
            'consumer-1'
        );
        $token = $service->exchangeToken(
            [
                'grant_type'          => 'urn:ietf:params:oauth:grant-type:pre-authorized_code',
                'pre-authorized_code' => $offer['offerCode'],
            ]
        );

        $proof  = $this->buildProofJwt($token['c_nonce']);
        $result = $service->issueCredential($token['access_token'], ['proof' => ['jwt' => $proof]]);

        $this->assertSame('dc+sd-jwt', $result['format']);
        $this->assertStringContainsString('~', $result['credential'], 'an SD-JWT VC carries at least one ~-separated disclosure');

        $jwtPart = explode('~', $result['credential'])[0];
        $parts   = explode('.', $jwtPart);
        $this->assertCount(3, $parts, 'the SD-JWT VC issuer-signed part is a compact JWS');

    }//end testDcSdJwtIsMintedAndSignedWithIssuerKey()

    /**
     * A replayed proof (same session, already consumed) is rejected and
     * issues no second credential.
     *
     * @return void
     */
    public function testReplayedProofIsRejected(): void
    {
        $service = $this->makeService();
        $offer   = $service->createOffer(
            ['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'],
            'consumer-1'
        );
        $token = $service->exchangeToken(
            [
                'grant_type'          => 'urn:ietf:params:oauth:grant-type:pre-authorized_code',
                'pre-authorized_code' => $offer['offerCode'],
            ]
        );

        $proof = $this->buildProofJwt($token['c_nonce']);
        $service->issueCredential($token['access_token'], ['proof' => ['jwt' => $proof]]);

        try {
            $service->issueCredential($token['access_token'], ['proof' => ['jwt' => $proof]]);
            $this->fail('expected a replayed session consumption to be rejected');
        } catch (EudiIssuanceException $exception) {
            $this->assertSame(400, $exception->getHttpStatus());
        }

    }//end testReplayedProofIsRejected()

    /**
     * Revocation flips the assigned status-list bit and fires a signed callback.
     *
     * @return void
     */
    public function testRevokeFlipsBitAndFiresSignedCallback(): void
    {
        $service = $this->makeService();
        $offer   = $service->createOffer(
            [
                'format'            => 'jwt_vc_json',
                'subjectId'         => 'learner-1',
                'credentialPayload' => 'abc',
                'callbackUrl'       => 'https://example.test/eudi-callback',
            ],
            'consumer-1'
        );

        $result = $service->revoke($offer['uuid'], 'consumer-1');
        $this->assertFalse($result['alreadyRevoked']);

        $statusListId = $this->rows[$offer['uuid']]['statusListId'];
        $index        = $this->rows[$offer['uuid']]['statusListIndex'];
        $this->assertSame(1, $this->rows[$statusListId]['bitstring'][$index]);

        $this->assertCount(1, $this->sentRequests, 'exactly one status callback must be delivered');
        $this->assertSame('https://example.test/eudi-callback', $this->sentRequests[0]['uri']);
        $this->assertMatchesRegularExpression(
            '/^t=\d+,v1=[0-9a-f]{64}$/',
            $this->sentRequests[0]['options']['headers']['X-OpenConnector-Signature']
        );

    }//end testRevokeFlipsBitAndFiresSignedCallback()

    /**
     * Revoking an already-revoked offer is idempotent (no double-toggle, no error).
     *
     * @return void
     */
    public function testRevokeIsIdempotent(): void
    {
        $service = $this->makeService();
        $offer   = $service->createOffer(
            ['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'],
            'consumer-1'
        );

        $service->revoke($offer['uuid'], 'consumer-1');
        $second = $service->revoke($offer['uuid'], 'consumer-1');

        $this->assertTrue($second['alreadyRevoked']);

        $statusListId = $this->rows[$offer['uuid']]['statusListId'];
        $index        = $this->rows[$offer['uuid']]['statusListIndex'];
        $this->assertSame(1, $this->rows[$statusListId]['bitstring'][$index], 'the bit must not double-toggle back to 0');

    }//end testRevokeIsIdempotent()

    /**
     * A consumer cannot revoke another consumer's offer (403, bit unchanged).
     *
     * @return void
     */
    public function testConsumerCannotRevokeAnotherConsumersOffer(): void
    {
        $service = $this->makeService();
        $offer   = $service->createOffer(
            ['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'],
            'acme-co'
        );

        try {
            $service->revoke($offer['uuid'], 'other-co');
            $this->fail('expected a 403 for a non-owning consumer');
        } catch (EudiIssuanceException $exception) {
            $this->assertSame(403, $exception->getHttpStatus());
        }

        $statusListId = $this->rows[$offer['uuid']]['statusListId'];
        $index        = $this->rows[$offer['uuid']]['statusListIndex'];
        $this->assertSame(0, $this->rows[$statusListId]['bitstring'][$index], 'the bit must remain unchanged on a forbidden revoke');

    }//end testConsumerCannotRevokeAnotherConsumersOffer()
}//end class
