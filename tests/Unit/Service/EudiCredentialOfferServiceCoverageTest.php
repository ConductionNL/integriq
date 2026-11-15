<?php

/**
 * Additional unit tests for EudiCredentialOfferService (error and edge paths).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTime;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Exception\EudiIssuanceException;
use OCA\Integriq\Service\EudiCredentialOfferService;
use OCA\Integriq\Service\EudiIssuerKeyService;
use OCA\Integriq\Service\EudiStatusListService;
use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Error-path and edge-case tests for the EUDI credential offer lifecycle:
 * organisation resolution, offer validation, unresolvable offers, proof.jwt
 * rejection reasons, credential-endpoint failures, and revocation failures.
 *
 * @covers \OCA\Integriq\Service\EudiCredentialOfferService
 * @uses   \OCA\Integriq\Service\EudiIssuerKeyService
 * @uses   \OCA\Integriq\Service\EudiStatusListService
 * @uses   \OCA\Integriq\Service\WebhookSignatureService
 * @uses   \OCA\Integriq\Service\Helper\RandomUuid
 * @uses   \OCA\Integriq\Exception\EudiIssuanceException
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md
 */
class EudiCredentialOfferServiceCoverageTest extends TestCase {

	/**
	 * The pre-authorized_code grant type.
	 *
	 * @var string
	 */
	private const GRANT = 'urn:ietf:params:oauth:grant-type:pre-authorized_code';

	/**
	 * In-memory OR object rows keyed by uuid.
	 *
	 * @var array<string, array>
	 */
	private array $rows = [];

	/**
	 * In-memory transient cache.
	 *
	 * @var array<string, string>
	 */
	private array $cache = [];

	/**
	 * Build the service against in-memory fakes.
	 *
	 * @param array|null           $activeOrganisation What the organisation bridge returns.
	 * @param LoggerInterface|null $logger             Logger (defaults to NullLogger).
	 * @param IClient|null         $client             HTTP client for status callbacks.
	 *
	 * @return EudiCredentialOfferService
	 */
	private function makeService(
		?array $activeOrganisation = null,
		?LoggerInterface $logger = null,
		?IClient $client = null,
	): EudiCredentialOfferService {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('find')->willReturnCallback(
			function ($id, ...$rest) {
				if (isset($this->rows[$id]) === false) {
					throw new DoesNotExistException('not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($this->rows[$id]);
				return $entity;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function ($config = []) {
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
			function ($object = [], $register = null, $schema = null, $uuid = null) {
				if ($uuid === null) {
					$uuid = bin2hex(random_bytes(8));
				}

				$object['uuid'] = $uuid;
				$this->rows[$uuid] = $object;

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		$appConfigStore = [];
		$appConfig = $this->createMock(\OCP\IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$appConfigStore) {
				return ($appConfigStore[$app . '.' . $key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$appConfigStore) {
				$appConfigStore[$app . '.' . $key] = $value;
				return true;
			}
		);

		$crypto = $this->createMock(\OCP\Security\ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $p): string => 'enc:' . base64_encode($p));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $c): string => base64_decode(substr($c, strlen('enc:'))));

		$keyService = new EudiIssuerKeyService($appConfig, $crypto, new NullLogger());
		$statusListService = new EudiStatusListService($objectService, $keyService, new NullLogger());
		$signatureService = new WebhookSignatureService(new NullLogger());

		$organisationBridge = $this->createMock(OrganisationBridgeService::class);
		$organisationBridge->method('getActiveOrganisation')->willReturn($activeOrganisation);

		if ($client === null) {
			$client = $this->createMock(IClient::class);
		}

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => ($this->cache[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0) {
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
			($logger ?? new NullLogger())
		);

	}//end makeService()

	/**
	 * Base64url-encode a string without padding.
	 *
	 * @param string $value Raw bytes.
	 *
	 * @return string
	 */
	private static function b64url(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}//end b64url()

	/**
	 * Hand-assemble a compact JWS (signature bytes are not meaningful).
	 *
	 * @param array $header  Protected header.
	 * @param array $payload Payload claims.
	 *
	 * @return string
	 */
	private static function craftJwt(array $header, array $payload): string {
		return self::b64url(json_encode($header)) . '.' . self::b64url(json_encode($payload)) . '.' . self::b64url('signature');
	}//end craftJwt()

	/**
	 * Build a signed proof.jwt.
	 *
	 * @param string     $nonce        The nonce claim.
	 * @param array|null $embeddedJwk  JWK to embed instead of the signing key's public part.
	 *
	 * @return string
	 */
	private function buildProofJwt(string $nonce, ?array $embeddedJwk = null): string {
		$walletKey = JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig']);
		$publicJwk = ($embeddedJwk ?? $walletKey->toPublic()->jsonSerialize());

		$jwsBuilder = new JWSBuilder(new AlgorithmManager([new ES256()]));
		$jws = $jwsBuilder->create()
			->withPayload(json_encode(['nonce' => $nonce, 'iat' => time()], JSON_UNESCAPED_SLASHES))
			->addSignature($walletKey, ['alg' => 'ES256', 'typ' => 'openid4vci-proof+jwt', 'jwk' => $publicJwk])
			->build();

		return (new CompactSerializer())->serialize($jws, 0);
	}//end buildProofJwt()

	/**
	 * Create an offer and exchange its code for a token.
	 *
	 * @param EudiCredentialOfferService $service The service.
	 * @param array                      $offer   Offer request overrides.
	 *
	 * @return array{offer: array, token: array}
	 */
	private function offerWithToken(EudiCredentialOfferService $service, array $offer = []): array {
		$created = $service->createOffer(
			array_merge(['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'], $offer),
			'consumer-1'
		);
		$token = $service->exchangeToken(['grant_type' => self::GRANT, 'pre-authorized_code' => $created['offerCode']]);

		return ['offer' => $created, 'token' => $token];
	}//end offerWithToken()

	/**
	 * The uuid of the issuance session holding an access token.
	 *
	 * @param string $accessToken The plaintext access token.
	 *
	 * @return string
	 */
	private function sessionUuidFor(string $accessToken): string {
		foreach ($this->rows as $uuid => $row) {
			if (($row['accessTokenHash'] ?? null) === hash('sha256', $accessToken)) {
				return $uuid;
			}
		}

		$this->fail('no issuance session found for the access token');
	}//end sessionUuidFor()

	/**
	 * Assert that issueCredential raises an EudiIssuanceException with the given status/code/message.
	 *
	 * @param callable $call       The call expected to throw.
	 * @param int      $httpStatus Expected HTTP status.
	 * @param string   $errorCode  Expected error code.
	 * @param string   $message    Expected message.
	 *
	 * @return void
	 */
	private function assertIssuanceError(callable $call, int $httpStatus, string $errorCode, string $message): void {
		try {
			$call();
			$this->fail('expected EudiIssuanceException: ' . $message);
		} catch (EudiIssuanceException $exception) {
			$this->assertSame($httpStatus, $exception->getHttpStatus());
			$this->assertSame($errorCode, $exception->getErrorCode());
			$this->assertSame($message, $exception->getMessage());
		}
	}//end assertIssuanceError()

	/**
	 * The active organisation's uuid is preferred over its id.
	 *
	 * @return void
	 */
	public function testResolveOrganisationIdPrefersUuid(): void {
		$service = $this->makeService(activeOrganisation: ['uuid' => 'org-uuid', 'id' => 'org-id']);

		$this->assertSame('org-uuid', $service->resolveOrganisationId());

	}//end testResolveOrganisationIdPrefersUuid()

	/**
	 * Without a uuid, the active organisation's id is used.
	 *
	 * @return void
	 */
	public function testResolveOrganisationIdFallsBackToId(): void {
		$service = $this->makeService(activeOrganisation: ['id' => 'org-id']);

		$this->assertSame('org-id', $service->resolveOrganisationId());

	}//end testResolveOrganisationIdFallsBackToId()

	/**
	 * A non-string or empty identifier resolves to null.
	 *
	 * @return void
	 */
	public function testResolveOrganisationIdReturnsNullForUnusableId(): void {
		$this->assertNull($this->makeService(activeOrganisation: ['id' => 42])->resolveOrganisationId());
		$this->assertNull($this->makeService(activeOrganisation: ['uuid' => ''])->resolveOrganisationId());
		$this->assertNull($this->makeService(activeOrganisation: null)->resolveOrganisationId());

	}//end testResolveOrganisationIdReturnsNullForUnusableId()

	/**
	 * A created offer is scoped to the resolved active organisation.
	 *
	 * @return void
	 */
	public function testCreateOfferStoresResolvedOrganisation(): void {
		$service = $this->makeService(activeOrganisation: ['uuid' => 'org-uuid']);

		$result = $service->createOffer(
			['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'],
			'consumer-1'
		);

		$this->assertSame('org-uuid', $this->rows[$result['uuid']]['organisationId']);

	}//end testCreateOfferStoresResolvedOrganisation()

	/**
	 * An unsupported format is rejected with 400 and persists nothing.
	 *
	 * @return void
	 */
	public function testCreateOfferRejectsUnsupportedFormat(): void {
		$service = $this->makeService();

		$this->assertIssuanceError(
			fn () => $service->createOffer(['format' => 'mso_mdoc', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'], 'consumer-1'),
			400,
			'invalid_request',
			'format must be one of: jwt_vc_json, dc+sd-jwt'
		);
		$this->assertSame([], $this->rows);

	}//end testCreateOfferRejectsUnsupportedFormat()

	/**
	 * An empty credentialPayload is rejected with 400 and persists nothing.
	 *
	 * @return void
	 */
	public function testCreateOfferRejectsEmptyPayload(): void {
		$service = $this->makeService();

		$this->assertIssuanceError(
			fn () => $service->createOffer(['format' => 'dc+sd-jwt', 'subjectId' => 'learner-1', 'credentialPayload' => []], 'consumer-1'),
			400,
			'invalid_request',
			'credentialPayload is required'
		);
		$this->assertSame([], $this->rows);

	}//end testCreateOfferRejectsEmptyPayload()

	/**
	 * An unknown offer uuid resolves to null.
	 *
	 * @return void
	 */
	public function testResolveUnknownOfferReturnsNull(): void {
		$this->assertNull($this->makeService()->resolveOfferForWallet('does-not-exist'));

	}//end testResolveUnknownOfferReturnsNull()

	/**
	 * A revoked offer is unresolvable and is not marked fetched.
	 *
	 * @return void
	 */
	public function testResolveRevokedOfferReturnsNull(): void {
		$service = $this->makeService();
		$offer = $service->createOffer(['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'], 'consumer-1');
		$this->rows[$offer['uuid']]['status'] = 'revoked';

		$this->assertNull($service->resolveOfferForWallet($offer['uuid']));
		$this->assertArrayNotHasKey('offerFetchedAt', $this->rows[$offer['uuid']]);

	}//end testResolveRevokedOfferReturnsNull()

	/**
	 * An offer whose plaintext code has left the cache is unresolvable.
	 *
	 * @return void
	 */
	public function testResolveOfferWithEvictedCodeReturnsNull(): void {
		$service = $this->makeService();
		$offer = $service->createOffer(['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc'], 'consumer-1');
		$this->cache = [];

		$this->assertNull($service->resolveOfferForWallet($offer['uuid']));
		$this->assertArrayNotHasKey('offerFetchedAt', $this->rows[$offer['uuid']]);

	}//end testResolveOfferWithEvictedCodeReturnsNull()

	/**
	 * A PIN-protected offer advertises a numeric tx_code in its grant.
	 *
	 * @return void
	 */
	public function testResolvePinProtectedOfferAdvertisesTxCode(): void {
		$service = $this->makeService();
		$offer = $service->createOffer(
			['format' => 'dc+sd-jwt', 'subjectId' => 'learner-1', 'credentialPayload' => ['a' => 1], 'txCode' => '1234'],
			'consumer-1'
		);

		$resolved = $service->resolveOfferForWallet($offer['uuid']);

		$grant = $resolved['grants']['urn:ietf:params:oauth:grant-type:pre-authorized_code'];
		$this->assertSame($offer['offerCode'], $grant['pre-authorized_code']);
		$this->assertSame(['input_mode' => 'numeric', 'description' => 'Enter the PIN provided to you'], $grant['tx_code']);
		$this->assertSame(['open-badges-3'], $resolved['credential_configuration_ids']);

	}//end testResolvePinProtectedOfferAdvertisesTxCode()

	/**
	 * An empty bearer token is rejected with 401.
	 *
	 * @return void
	 */
	public function testIssueCredentialRejectsEmptyBearer(): void {
		$service = $this->makeService();

		$this->assertIssuanceError(
			fn () => $service->issueCredential('', ['proof' => ['jwt' => 'x']]),
			401,
			'invalid_token',
			'Missing Bearer access token'
		);

	}//end testIssueCredentialRejectsEmptyBearer()

	/**
	 * An unknown bearer token is rejected with 401.
	 *
	 * @return void
	 */
	public function testIssueCredentialRejectsUnknownToken(): void {
		$service = $this->makeService();

		$this->assertIssuanceError(
			fn () => $service->issueCredential('unknown-token', ['proof' => ['jwt' => 'x']]),
			401,
			'invalid_token',
			'Invalid access token'
		);

	}//end testIssueCredentialRejectsUnknownToken()

	/**
	 * A token whose session has expired is rejected with 401.
	 *
	 * @return void
	 */
	public function testIssueCredentialRejectsExpiredSession(): void {
		$service = $this->makeService();
		$flow = $this->offerWithToken($service);
		$sessionUuid = $this->sessionUuidFor($flow['token']['access_token']);
		$this->rows[$sessionUuid]['expiresAt'] = (new DateTime('-1 minute'))->format('c');

		$this->assertIssuanceError(
			fn () => $service->issueCredential($flow['token']['access_token'], ['proof' => ['jwt' => $this->buildProofJwt($flow['token']['c_nonce'])]]),
			401,
			'invalid_token',
			'Session has expired'
		);

	}//end testIssueCredentialRejectsExpiredSession()

	/**
	 * A request without proof.jwt is rejected with 400.
	 *
	 * @return void
	 */
	public function testIssueCredentialRequiresProofJwt(): void {
		$service = $this->makeService();
		$flow = $this->offerWithToken($service);

		$this->assertIssuanceError(
			fn () => $service->issueCredential($flow['token']['access_token'], []),
			400,
			'invalid_proof',
			'proof.jwt is required'
		);

	}//end testIssueCredentialRequiresProofJwt()

	/**
	 * Each malformed or mismatched proof.jwt is rejected with its own reason
	 * and leaves the session unconsumed.
	 *
	 * @return void
	 */
	public function testIssueCredentialRejectsInvalidProofs(): void {
		$service = $this->makeService();
		$flow = $this->offerWithToken($service);
		$token = $flow['token']['access_token'];
		$nonce = $flow['token']['c_nonce'];
		$otherKey = JWKFactory::createECKey('P-256', ['alg' => 'ES256'])->toPublic()->jsonSerialize();

		$cases = [
			'Malformed proof.jwt' => 'not-a-jwt',
			'proof.jwt has an invalid typ header' => self::craftJwt(['alg' => 'ES256', 'typ' => 'JWT'], ['nonce' => $nonce]),
			'proof.jwt uses an unsupported algorithm' => self::craftJwt(['alg' => 'HS256', 'typ' => 'openid4vci-proof+jwt'], ['nonce' => $nonce]),
			'proof.jwt is missing an embedded jwk' => self::craftJwt(['alg' => 'ES256', 'typ' => 'openid4vci-proof+jwt'], ['nonce' => $nonce]),
			'proof.jwt signature does not match its embedded key' => $this->buildProofJwt(nonce: $nonce, embeddedJwk: $otherKey),
			'proof.jwt nonce does not match c_nonce' => $this->buildProofJwt(nonce: 'stale-nonce'),
		];

		foreach ($cases as $message => $proof) {
			$this->assertIssuanceError(
				fn () => $service->issueCredential($token, ['proof' => ['jwt' => $proof]]),
				400,
				'invalid_proof',
				$message
			);
		}

		$this->assertArrayNotHasKey('consumedAt', $this->rows[$this->sessionUuidFor($token)]);

	}//end testIssueCredentialRejectsInvalidProofs()

	/**
	 * A session whose offer row disappeared is rejected and not consumed.
	 *
	 * @return void
	 */
	public function testIssueCredentialRejectsWhenOfferIsGone(): void {
		$service = $this->makeService();
		$flow = $this->offerWithToken($service);
		unset($this->rows[$flow['offer']['uuid']]);

		$this->assertIssuanceError(
			fn () => $service->issueCredential($flow['token']['access_token'], ['proof' => ['jwt' => $this->buildProofJwt($flow['token']['c_nonce'])]]),
			400,
			'invalid_request',
			'The credential offer for this session no longer exists'
		);
		$this->assertArrayNotHasKey('consumedAt', $this->rows[$this->sessionUuidFor($flow['token']['access_token'])]);

	}//end testIssueCredentialRejectsWhenOfferIsGone()

	/**
	 * A dc+sd-jwt payload that is not a JSON object mints a credential with no
	 * disclosures, still bound to the holder key.
	 *
	 * @return void
	 */
	public function testSdJwtWithNonObjectPayloadHasNoDisclosures(): void {
		$service = $this->makeService();
		$flow = $this->offerWithToken($service, ['format' => 'dc+sd-jwt', 'credentialPayload' => '"just-a-string"']);

		$result = $service->issueCredential(
			$flow['token']['access_token'],
			['proof' => ['jwt' => $this->buildProofJwt($flow['token']['c_nonce'])]]
		);

		$this->assertSame('dc+sd-jwt', $result['format']);
		$this->assertStringEndsWith('~~', $result['credential']);

		$parts = explode('.', explode('~', $result['credential'])[0]);
		$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
		$this->assertSame([], $payload['_sd']);
		$this->assertSame('learner-1', $payload['sub']);
		$this->assertSame('open-badges-3', $payload['vct']);
		$this->assertArrayHasKey('jwk', $payload['cnf']);

	}//end testSdJwtWithNonObjectPayloadHasNoDisclosures()

	/**
	 * Revoking an unknown offer is a 404.
	 *
	 * @return void
	 */
	public function testRevokeUnknownOfferIsNotFound(): void {
		$service = $this->makeService();

		$this->assertIssuanceError(
			fn () => $service->revoke('does-not-exist', 'consumer-1'),
			404,
			'not_found',
			'Credential offer not found'
		);

	}//end testRevokeUnknownOfferIsNotFound()

	/**
	 * A failing status callback is logged and does not undo the revocation.
	 *
	 * @return void
	 */
	public function testRevokeLogsFailedStatusCallback(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with('https://example.test/cb', $this->callback(static fn (array $o): bool => isset($o['headers']['X-OpenConnector-Signature'])))
			->willThrowException(new RuntimeException('connection refused'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'EudiCredentialOfferService: status callback delivery failed',
				['exception' => 'connection refused']
			);

		$service = $this->makeService(logger: $logger, client: $client);
		$offer = $service->createOffer(
			['format' => 'jwt_vc_json', 'subjectId' => 'learner-1', 'credentialPayload' => 'abc', 'callbackUrl' => 'https://example.test/cb'],
			'consumer-1'
		);

		$result = $service->revoke($offer['uuid'], 'consumer-1');

		$this->assertSame(['alreadyRevoked' => false], $result);
		$this->assertSame('revoked', $this->rows[$offer['uuid']]['status']);

	}//end testRevokeLogsFailedStatusCallback()
}//end class
