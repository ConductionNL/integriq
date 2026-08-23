<?php

/**
 * Integriq EudiCredentialOfferService.
 *
 * Owns the OpenID4VCI pre-authorized-code credential offer lifecycle for
 * the EUDI wallet credential issuance adapter: offer creation (app-facing,
 * consumer-gated), single-fetch offer resolution, pre-authorized_code
 * token exchange, proof-of-possession credential issuance (pass-through
 * for jwt_vc_json, mint-and-seal for dc+sd-jwt), and revocation.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use OCA\Integriq\Exception\EudiIssuanceException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Offer creation/resolution, token exchange, credential issuance, and
 * revocation for the EUDI wallet credential issuance adapter.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
 */
class EudiCredentialOfferService {

	/**
	 * Default offer resolution TTL in seconds (REQ-EUDI-005: 15 minutes).
	 *
	 * @var integer
	 */
	public const OFFER_TTL_SECONDS = 900;

	/**
	 * Issuance session lifetime in seconds.
	 *
	 * @var integer
	 */
	private const SESSION_TTL_SECONDS = 600;

	/**
	 * C_nonce lifetime in seconds.
	 *
	 * @var integer
	 */
	private const C_NONCE_TTL_SECONDS = 300;

	/**
	 * Maximum wrong-tx_code presentations before further attempts are throttled.
	 *
	 * @var integer
	 */
	private const TX_CODE_MAX_ATTEMPTS = 5;

	/**
	 * Supported credential formats.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_FORMATS = ['jwt_vc_json', 'dc+sd-jwt'];

	/**
	 * Signature algorithms accepted on an inbound wallet proof.jwt.
	 *
	 * @var string[]
	 */
	private const PROOF_ALGORITHMS = ['ES256', 'RS256', 'PS256'];

	/**
	 * Distributed cache used to hold the plaintext pre-authorized_code
	 * between offer creation and its single dereference at
	 * `GET /api/eudi/credential-offers/{id}` (design.md D-REPLAY: the
	 * OpenRegister-persisted row stores only the hash — mirrors
	 * `AuthorizationService`'s jti-replay cache usage for the same reason:
	 * a short-TTL cache is the right place for a value that must be
	 * reachable exactly once but never durably persisted in plaintext).
	 *
	 * @var ICache
	 */
	private readonly ICache $offerCodeCache;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService OR ObjectService used to read/write offer/session rows.
	 * @param EudiIssuerKeyService $keyService Issuer signing-key service (mint-and-seal, JWKS).
	 * @param EudiStatusListService $statusListService Status-list bit assignment/revocation.
	 * @param WebhookSignatureService $signatureService HMAC signer for revocation status callbacks.
	 * @param OrganisationBridgeService $organisationBridge Soft-fail OpenRegister organisation accessor.
	 * @param IClientService $clientService Nextcloud HTTP client factory (status callbacks).
	 * @param ICacheFactory $cacheFactory Cache factory (transient pre-authorized_code holding).
	 * @param LoggerInterface $logger Logger for lifecycle events (never logs secret material).
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly EudiIssuerKeyService $keyService,
		private readonly EudiStatusListService $statusListService,
		private readonly WebhookSignatureService $signatureService,
		private readonly OrganisationBridgeService $organisationBridge,
		private readonly IClientService $clientService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->offerCodeCache = $cacheFactory->createDistributed('openconnector.eudi.offercode');

	}//end __construct()

	/**
	 * Resolve the active OpenRegister organisation id, or null when
	 * unavailable (falls back to a single default-organisation scope
	 * everywhere this value is consumed — design.md D-KEY).
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function resolveOrganisationId(): ?string {
		$active = $this->organisationBridge->getActiveOrganisation();
		if ($active === null) {
			return null;
		}

		$id = ($active['uuid'] ?? $active['id'] ?? null);
		if (is_string($id) === true && $id !== '') {
			return $id;
		}

		return null;
	}//end resolveOrganisationId()

	/**
	 * Generate a UUID v4 (no external dependency required).
	 *
	 * @return string
	 */
	private static function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end generateUuid()

	/**
	 * Constant-time-friendly SHA-256 hash of a secret value for at-rest storage.
	 *
	 * @param string $value The plaintext secret.
	 *
	 * @return string The hex-encoded hash.
	 */
	private static function hashSecret(string $value): string {
		return hash('sha256', $value);
	}//end hashSecret()

	/**
	 * Create a credential offer (app-facing, consumer-gated).
	 *
	 * The caller (controller) MUST have already authenticated the consumer
	 * (REQ-CON-001 + authorization-jwt REQ-001) before invoking this. A
	 * missing/mismatching required field is rejected with a 400 before any
	 * row is persisted (REQ-EUDI-004).
	 *
	 * @param array $data Request body: `credentialPayload`, `format`, `subjectId`, `consumerId`,
	 *                    optional `credentialConfigurationId`, `txCode`, `callbackUrl`.
	 * @param string $consumerId The authenticated consumer's uuid (from the controller's auth gate).
	 *
	 * @return array{uuid: string, offerCode: string} The offer's uuid and its plaintext
	 *                                                pre-authorized_code (embedded by the
	 *                                                controller into offerUrl/credentialOfferUri).
	 *
	 * @throws EudiIssuanceException On a missing/invalid field (HTTP 400).
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-offer-creation-is-consumer-gated-and-app-facing-req-eudi-004
	 */
	public function createOffer(array $data, string $consumerId): array {
		$format = ($data['format'] ?? '');
		if (in_array(needle: $format, haystack: self::SUPPORTED_FORMATS, strict: true) === false) {
			throw new EudiIssuanceException(
				message: 'format must be one of: ' . implode(', ', self::SUPPORTED_FORMATS),
				httpStatus: 400,
				errorCode: 'invalid_request'
			);
		}

		$subjectId = ($data['subjectId'] ?? '');
		if (is_string($subjectId) === false || $subjectId === '') {
			throw new EudiIssuanceException(message: 'subjectId is required', httpStatus: 400, errorCode: 'invalid_request');
		}

		$credentialPayload = ($data['credentialPayload'] ?? null);
		if ($credentialPayload === null || $credentialPayload === '' || $credentialPayload === []) {
			throw new EudiIssuanceException(
				message: 'credentialPayload is required',
				httpStatus: 400,
				errorCode: 'invalid_request'
			);
		}

		if (is_array($credentialPayload) === true) {
			$storedPayload = json_encode($credentialPayload, JSON_UNESCAPED_SLASHES);
		} else {
			$storedPayload = (string)$credentialPayload;
		}

		$organisationId = $this->resolveOrganisationId();

		$assignment = $this->statusListService->assignIndex($organisationId);

		$preAuthorizedCode = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

		$txCode = ($data['txCode'] ?? null);
		$txCodeRequired = (is_string($txCode) === true && $txCode !== '');

		$now = new DateTime();
		$expiresAt = (clone $now)->modify('+' . self::OFFER_TTL_SECONDS . ' seconds');
		$uuid = self::generateUuid();

		$callbackUrl = ($data['callbackUrl'] ?? null);
		$callbackSigningSecret = null;
		if (is_string($callbackUrl) === true && $callbackUrl !== '') {
			$callbackSigningSecret = $this->signatureService->generateSecret();
		} else {
			$callbackUrl = null;
		}

		$credentialConfigurationId = ($data['credentialConfigurationId'] ?? $this->defaultConfigurationId(format: $format));

		$txCodeHash = null;
		if ($txCodeRequired === true) {
			$txCodeHash = self::hashSecret(value: (string)$txCode);
		}

		$row = [
			'consumerId' => $consumerId,
			'format' => $format,
			'credentialConfigurationId' => $credentialConfigurationId,
			'credentialPayload' => $storedPayload,
			'subjectId' => $subjectId,
			'organisationId' => ($organisationId ?? EudiIssuerKeyService::DEFAULT_ORGANISATION_SCOPE),
			'preAuthorizedCodeHash' => self::hashSecret(value: $preAuthorizedCode),
			'txCodeRequired' => $txCodeRequired,
			'txCodeHash' => $txCodeHash,
			'txCodeFailedAttempts' => 0,
			'status' => 'created',
			'statusListId' => $assignment['statusListId'],
			'statusListIndex' => $assignment['index'],
			'callbackUrl' => $callbackUrl,
			'callbackSigningSecret' => $callbackSigningSecret,
			'expiresAt' => $expiresAt->format('c'),
		];

		$this->orObjectService->saveObject(
			object: $row,
			register: 'openconnector',
			schema: 'eudi_credential_offer',
			uuid: $uuid
		);

		// Hold the plaintext code for exactly one dereference at resolution
		// time (design.md D-REPLAY) — never persisted in the OR row.
		$this->offerCodeCache->set('code:' . $uuid, $preAuthorizedCode, self::OFFER_TTL_SECONDS);

		$this->logger->info('EudiCredentialOfferService: offer created', ['uuid' => $uuid, 'format' => $format]);

		return ['uuid' => $uuid, 'offerCode' => $preAuthorizedCode];
	}//end createOffer()

	/**
	 * Default credential_configuration_id for a format when the caller does not supply one.
	 *
	 * @param string $format `jwt_vc_json` or `dc+sd-jwt`.
	 *
	 * @return string
	 */
	private function defaultConfigurationId(string $format): string {
		if ($format === 'dc+sd-jwt') {
			return 'open-badges-3';
		}

		return 'edci-diploma';
	}//end defaultConfigurationId()

	/**
	 * Resolve a credential offer for wallet consumption (single-fetch,
	 * atomic consume-on-read).
	 *
	 * @param string $uuid The offer uuid.
	 *
	 * @return array|null The OpenID4VCI `credential_offer` object, or null when
	 *                    not found / expired / already consumed (REQ-EUDI-005).
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-offer-resolution-is-public-single-fetch-short-ttl-req-eudi-005
	 */
	public function resolveOfferForWallet(string $uuid): ?array {
		try {
			$entity = $this->orObjectService->find(
				id: $uuid,
				register: 'openconnector',
				schema: 'eudi_credential_offer',
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			return null;
		}

		$data = $entity->getObject();

		if (empty($data['offerFetchedAt'] ?? null) === false) {
			// Already fetched once — permanently unresolvable.
			return null;
		}

		$expiresAt = ($data['expiresAt'] ?? null);
		if (empty($expiresAt) === false && (new DateTime($expiresAt)) < (new DateTime())) {
			return null;
		}

		if (in_array(needle: ($data['status'] ?? ''), haystack: ['revoked', 'expired'], strict: true) === true) {
			return null;
		}

		$code = $this->offerCodeCache->get('code:' . $uuid);
		if ($code === null) {
			// The plaintext code has already expired out of cache — treat as
			// unresolvable rather than returning a credential_offer with no
			// usable grant.
			return null;
		}

		// Set the single-use marker atomically (within this request) BEFORE
		// returning the response body, so a concurrent second request that
		// reads after this write observes the row as already consumed.
		$data['offerFetchedAt'] = (new DateTime())->format('c');
		$this->orObjectService->saveObject(
			object: $data,
			register: 'openconnector',
			schema: 'eudi_credential_offer',
			uuid: $uuid
		);

		$grant = ['pre-authorized_code' => $code];
		if (($data['txCodeRequired'] ?? false) === true) {
			$grant['tx_code'] = ['input_mode' => 'numeric', 'description' => 'Enter the PIN provided to you'];
		}

		return [
			'credential_issuer' => 'openconnector',
			'credential_configuration_ids' => [($data['credentialConfigurationId'] ?? $this->defaultConfigurationId(format: $data['format']))],
			'grants' => [
				'urn:ietf:params:oauth:grant-type:pre-authorized_code' => $grant,
			],
		];

	}//end resolveOfferForWallet()

	/**
	 * Find the offer whose pre-authorized_code hash matches the presented code.
	 *
	 * @param string $code The plaintext pre-authorized_code.
	 *
	 * @return ObjectEntity|null
	 */
	private function findOfferByCode(string $code): ?ObjectEntity {
		$hash = self::hashSecret(value: $code);
		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'openconnector',
					'schema' => 'eudi_credential_offer',
					'preAuthorizedCodeHash' => $hash,
				],
			],
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);

		return ($results[0] ?? null);
	}//end findOfferByCode()

	/**
	 * Exchange a pre-authorized_code for an access token
	 * (`urn:ietf:params:oauth:grant-type:pre-authorized_code` only).
	 *
	 * @param array $data Request body: `grant_type`, `pre-authorized_code`, optional `tx_code`.
	 *
	 * @return array{access_token: string, token_type: string, c_nonce: string, c_nonce_expires_in: int, expires_in: int}
	 *
	 * @throws EudiIssuanceException On any failure (`invalid_grant`/`invalid_request`, HTTP 400).
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-token-endpoint-issues-a-single-use-pre-authorized-code-grant-req-eudi-006
	 */
	public function exchangeToken(array $data): array {
		$grantType = ($data['grant_type'] ?? '');
		if ($grantType !== 'urn:ietf:params:oauth:grant-type:pre-authorized_code') {
			throw new EudiIssuanceException(
				message: 'Only the pre-authorized_code grant is supported',
				httpStatus: 400,
				errorCode: 'unsupported_grant_type'
			);
		}

		$code = ($data['pre-authorized_code'] ?? '');
		if (is_string($code) === false || $code === '') {
			throw new EudiIssuanceException(message: 'pre-authorized_code is required', httpStatus: 400, errorCode: 'invalid_request');
		}

		$entity = $this->findOfferByCode(code: $code);
		if ($entity === null) {
			throw new EudiIssuanceException(message: 'Unknown pre-authorized_code', httpStatus: 400, errorCode: 'invalid_grant');
		}

		$offerData = $entity->getObject();

		if (($offerData['status'] ?? '') !== 'created') {
			// Already claimed, revoked, or expired — a second exchange of the
			// same code MUST be rejected, never re-issue a token.
			throw new EudiIssuanceException(message: 'pre-authorized_code already used', httpStatus: 400, errorCode: 'invalid_grant');
		}

		$expiresAt = ($offerData['expiresAt'] ?? null);
		if (empty($expiresAt) === false && (new DateTime($expiresAt)) < (new DateTime())) {
			throw new EudiIssuanceException(message: 'pre-authorized_code has expired', httpStatus: 400, errorCode: 'invalid_grant');
		}

		if (($offerData['txCodeRequired'] ?? false) === true) {
			$attempts = (int)($offerData['txCodeFailedAttempts'] ?? 0);
			if ($attempts >= self::TX_CODE_MAX_ATTEMPTS) {
				throw new EudiIssuanceException(
					message: 'Too many incorrect tx_code attempts',
					httpStatus: 400,
					errorCode: 'invalid_grant'
				);
			}

			$presentedTxCode = ($data['tx_code'] ?? '');
			if (is_string($presentedTxCode) === false || $presentedTxCode === ''
				|| hash_equals((string)$offerData['txCodeHash'], self::hashSecret(value: $presentedTxCode)) === false
			) {
				// Wrong PIN does NOT consume the pre-authorized_code — the
				// wallet retains its remaining attempts.
				$offerData['txCodeFailedAttempts'] = ($attempts + 1);
				$this->orObjectService->saveObject(
					object: $offerData,
					register: 'openconnector',
					schema: 'eudi_credential_offer',
					uuid: $entity->getUuid()
				);

				throw new EudiIssuanceException(message: 'Incorrect tx_code', httpStatus: 400, errorCode: 'invalid_grant');
			}
		}//end if

		// Capture the fields the new session needs BEFORE mutating the offer
		// row's status, so the session write below never depends on the
		// post-mutation shape of $offerData.
		$offerFormat = $offerData['format'];
		$offerCredentialConfigId = ($offerData['credentialConfigurationId'] ?? null);

		// Consume the code atomically: flip status to claimed before issuing
		// the token, so a concurrent/replayed second request observes a
		// non-'created' status and is rejected above.
		$offerData['status'] = 'claimed';
		$this->orObjectService->saveObject(
			object: $offerData,
			register: 'openconnector',
			schema: 'eudi_credential_offer',
			uuid: $entity->getUuid()
		);

		$accessToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$cNonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

		$now = new DateTime();
		$sessionExpires = (clone $now)->modify('+' . self::SESSION_TTL_SECONDS . ' seconds');
		$nonceExpires = (clone $now)->modify('+' . self::C_NONCE_TTL_SECONDS . ' seconds');

		$sessionUuid = self::generateUuid();
		$this->orObjectService->saveObject(
			object: [
				'offerId' => $entity->getUuid(),
				'accessTokenHash' => self::hashSecret(value: $accessToken),
				'cNonce' => $cNonce,
				'cNonceExpiresAt' => $nonceExpires->format('c'),
				'format' => $offerFormat,
				'credentialConfigurationId' => $offerCredentialConfigId,
				'expiresAt' => $sessionExpires->format('c'),
			],
			register: 'openconnector',
			schema: 'eudi_issuance_session',
			uuid: $sessionUuid
		);

		return [
			'access_token' => $accessToken,
			'token_type' => 'bearer',
			'c_nonce' => $cNonce,
			'c_nonce_expires_in' => self::C_NONCE_TTL_SECONDS,
			'expires_in' => self::SESSION_TTL_SECONDS,
		];

	}//end exchangeToken()

	/**
	 * Verify a wallet proof-of-possession JWT (`proof.jwt`).
	 *
	 * Self-verifies the JWS against the embedded `jwk` header (proof of
	 * possession requires no external trust anchor — the wallet's key is
	 * self-asserted at this stage, per D-TRUST) and checks the `nonce`
	 * claim matches the session's current `c_nonce`.
	 *
	 * @param string $proofJwt The compact-serialized proof JWT.
	 * @param string $expectedNonce The session's current `c_nonce`.
	 *
	 * @return array The verified proof payload.
	 *
	 * @throws EudiIssuanceException On any verification failure (HTTP 400).
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-endpoint-verifies-proof-of-possession-and-dispatches-by-format-req-eudi-007
	 */
	private function verifyProof(string $proofJwt, string $expectedNonce): array {
		try {
			$serializerManager = new JWSSerializerManager([new CompactSerializer()]);
			$jws = $serializerManager->unserialize($proofJwt);
		} catch (Throwable $exception) {
			throw new EudiIssuanceException(message: 'Malformed proof.jwt', httpStatus: 400, errorCode: 'invalid_proof');
		}

		$header = $jws->getSignature(0)->getProtectedHeader();

		if (($header['typ'] ?? '') !== 'openid4vci-proof+jwt') {
			throw new EudiIssuanceException(message: 'proof.jwt has an invalid typ header', httpStatus: 400, errorCode: 'invalid_proof');
		}

		$algorithm = ($header['alg'] ?? '');
		if (in_array(needle: $algorithm, haystack: self::PROOF_ALGORITHMS, strict: true) === false) {
			throw new EudiIssuanceException(message: 'proof.jwt uses an unsupported algorithm', httpStatus: 400, errorCode: 'invalid_proof');
		}

		$jwkHeader = ($header['jwk'] ?? null);
		if (is_array($jwkHeader) === false) {
			throw new EudiIssuanceException(message: 'proof.jwt is missing an embedded jwk', httpStatus: 400, errorCode: 'invalid_proof');
		}

		$algorithmManager = new AlgorithmManager([new ES256(), new RS256(), new PS256()]);
		$verifier = new JWSVerifier($algorithmManager);
		$jwkSet = new JWKSet([new JWK($jwkHeader)]);

		if ($verifier->verifyWithKeySet(jws: $jws, jwkset: $jwkSet, signatureIndex: 0) === false) {
			throw new EudiIssuanceException(
				message: 'proof.jwt signature does not match its embedded key',
				httpStatus: 400,
				errorCode: 'invalid_proof'
			);
		}

		$payload = json_decode($jws->getPayload() ?? '', true);
		if (is_array($payload) === false || ($payload['nonce'] ?? null) !== $expectedNonce) {
			throw new EudiIssuanceException(message: 'proof.jwt nonce does not match c_nonce', httpStatus: 400, errorCode: 'invalid_proof');
		}

		$payload['jwk'] = $jwkHeader;

		return $payload;
	}//end verifyProof()

	/**
	 * Issue a credential (Bearer + proof-of-possession).
	 *
	 * Dispatches by the session's `format`: `jwt_vc_json` returns the
	 * consuming app's already-signed payload verbatim; `dc+sd-jwt` mints
	 * and signs a fresh SD-JWT VC with the resolved organisation's active
	 * issuer key (design.md D-SIGN).
	 *
	 * @param string $bearerAccessToken The presented (plaintext) access token.
	 * @param array $data Request body: `proof` => `['jwt' => string]`.
	 *
	 * @return array{format: string, credential: string}
	 *
	 * @throws EudiIssuanceException On any failure (HTTP 400/401), never returning credential material.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-endpoint-verifies-proof-of-possession-and-dispatches-by-format-req-eudi-007
	 */
	public function issueCredential(string $bearerAccessToken, array $data): array {
		if ($bearerAccessToken === '') {
			throw new EudiIssuanceException(message: 'Missing Bearer access token', httpStatus: 401, errorCode: 'invalid_token');
		}

		$hash = self::hashSecret(value: $bearerAccessToken);
		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'openconnector',
					'schema' => 'eudi_issuance_session',
					'accessTokenHash' => $hash,
				],
			],
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);
		$session = ($results[0] ?? null);

		if ($session === null) {
			throw new EudiIssuanceException(message: 'Invalid access token', httpStatus: 401, errorCode: 'invalid_token');
		}

		$sessionData = $session->getObject();

		$expiresAt = ($sessionData['expiresAt'] ?? null);
		if (empty($expiresAt) === false && (new DateTime($expiresAt)) < (new DateTime())) {
			throw new EudiIssuanceException(message: 'Session has expired', httpStatus: 401, errorCode: 'invalid_token');
		}

		if (empty($sessionData['consumedAt'] ?? null) === false) {
			throw new EudiIssuanceException(message: 'This session has already issued its credential', httpStatus: 400, errorCode: 'invalid_proof');
		}

		$proofJwt = ($data['proof']['jwt'] ?? '');
		if (is_string($proofJwt) === false || $proofJwt === '') {
			throw new EudiIssuanceException(message: 'proof.jwt is required', httpStatus: 400, errorCode: 'invalid_proof');
		}

		$proof = $this->verifyProof(proofJwt: $proofJwt, expectedNonce: (string)($sessionData['cNonce'] ?? ''));

		$offerId = ($sessionData['offerId'] ?? '');
		try {
			$offerEntity = $this->orObjectService->find(
				id: $offerId,
				register: 'openconnector',
				schema: 'eudi_credential_offer',
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			throw new EudiIssuanceException(
				message: 'The credential offer for this session no longer exists',
				httpStatus: 400,
				errorCode: 'invalid_request'
			);
		}

		$offerData = $offerEntity->getObject();
		$format = ($sessionData['format'] ?? $offerData['format']);

		if ($format === 'jwt_vc_json') {
			$credential = ($offerData['credentialPayload'] ?? '');
		} else {
			$claims = (json_decode((string)($offerData['credentialPayload'] ?? '{}'), true) ?? []);
			if (is_array($claims) === false) {
				$claims = [];
			}

			$credential = $this->buildSdJwtCredential(
				claims: $claims,
				organisationId: ($offerData['organisationId'] ?? null),
				subjectId: (string)($offerData['subjectId'] ?? ''),
				credentialConfigurationId: (string)($offerData['credentialConfigurationId'] ?? 'open-badges-3'),
				holderJwk: ($proof['jwk'] ?? null)
			);
		}

		// Atomically mark the session consumed — a second presentation
		// (replay) now finds `consumedAt` set above and is rejected.
		$sessionData['consumedAt'] = (new DateTime())->format('c');
		$this->orObjectService->saveObject(
			object: $sessionData,
			register: 'openconnector',
			schema: 'eudi_issuance_session',
			uuid: $session->getUuid()
		);

		return ['format' => $format, 'credential' => $credential];
	}//end issueCredential()

	/**
	 * Mint and sign a fresh SD-JWT VC from claims-shaped credential content.
	 *
	 * Minimal, standards-shaped SD-JWT VC: every top-level claim becomes a
	 * selectively-disclosable digest (`_sd`), the issuer-signed JWT part is
	 * signed with the resolved organisation's active issuer key, and the
	 * disclosures are appended per the SD-JWT serialization
	 * (`<jwt>~<disclosure>~...~`), with a trailing `~` (no Key Binding JWT
	 * in this issuance-time change).
	 *
	 * @param array $claims The claims-shaped credential content.
	 * @param string|null $organisationId The resolved organisation id, or null for default scope.
	 * @param string $subjectId The credential subject id.
	 * @param string $credentialConfigurationId Used to derive the `vct` (credential type) claim.
	 * @param array|null $holderJwk The wallet's public JWK from its proof (bound via `cnf`).
	 *
	 * @return string The SD-JWT VC serialization.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-endpoint-verifies-proof-of-possession-and-dispatches-by-format-req-eudi-007
	 */
	private function buildSdJwtCredential(
		array $claims,
		?string $organisationId,
		string $subjectId,
		string $credentialConfigurationId,
		?array $holderJwk,
	): string {
		$disclosures = [];
		$digests = [];

		foreach ($claims as $name => $value) {
			$salt = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
			$disclosureJson = json_encode([$salt, $name, $value], JSON_UNESCAPED_SLASHES);
			$disclosureB64 = rtrim(strtr(base64_encode($disclosureJson), '+/', '-_'), '=');
			$disclosures[] = $disclosureB64;
			$digests[] = rtrim(strtr(base64_encode(hash('sha256', $disclosureB64, true)), '+/', '-_'), '=');
		}

		$now = new DateTime();
		$exp = (clone $now)->modify('+1 year');

		$payload = [
			'iss' => 'openconnector',
			'sub' => $subjectId,
			'vct' => $credentialConfigurationId,
			'iat' => $now->getTimestamp(),
			'exp' => $exp->getTimestamp(),
			'_sd' => $digests,
			'_sd_alg' => 'sha-256',
		];

		if ($holderJwk !== null) {
			$payload['cnf'] = ['jwk' => $holderJwk];
		}

		$jwt = $this->keyService->signJwt(
			organisationId: $organisationId,
			payload: $payload,
			extraHeaderClaims: ['typ' => 'dc+sd-jwt']
		);

		return $jwt . '~' . implode('~', $disclosures) . '~';
	}//end buildSdJwtCredential()

	/**
	 * Revoke a credential offer's issued credential (consumer-gated,
	 * ownership-checked, idempotent).
	 *
	 * @param string $offerUuid The offer uuid to revoke.
	 * @param string $consumerId The authenticated consumer's uuid (from the controller's auth gate).
	 *
	 * @return array{alreadyRevoked: boolean}
	 *
	 * @throws EudiIssuanceException 404 when the offer does not exist, 403 when
	 *                               the authenticated consumer does not own it.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-revocation-flips-one-status-list-bit-and-fires-a-signed-callback-req-eudi-009
	 */
	public function revoke(string $offerUuid, string $consumerId): array {
		try {
			$entity = $this->orObjectService->find(
				id: $offerUuid,
				register: 'openconnector',
				schema: 'eudi_credential_offer',
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			throw new EudiIssuanceException(message: 'Credential offer not found', httpStatus: 404, errorCode: 'not_found');
		}

		$data = $entity->getObject();

		if (($data['consumerId'] ?? null) !== $consumerId) {
			throw new EudiIssuanceException(message: 'You do not own this credential offer', httpStatus: 403, errorCode: 'forbidden');
		}

		if (($data['status'] ?? '') === 'revoked') {
			return ['alreadyRevoked' => true];
		}

		$bitFlipped = $this->statusListService->revokeIndex(
			statusListId: (string)$data['statusListId'],
			index: (int)$data['statusListIndex']
		);

		$data['status'] = 'revoked';
		$data['revokedAt'] = (new DateTime())->format('c');
		$this->orObjectService->saveObject(
			object: $data,
			register: 'openconnector',
			schema: 'eudi_credential_offer',
			uuid: $entity->getUuid()
		);

		if ($bitFlipped === true) {
			$this->fireStatusCallback(offerData: $data);
		}

		return ['alreadyRevoked' => false];
	}//end revoke()

	/**
	 * Fire an HMAC-signed status callback to the offer's configured
	 * callback URL (reuses `WebhookSignatureService`'s
	 * `X-OpenConnector-Signature` scheme, REQ-WHS-001). Best-effort — a
	 * delivery failure is logged, not surfaced to the revoking consumer
	 * (the bit flip already succeeded and is the source of truth).
	 *
	 * @param array $offerData The offer row (post-revocation).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-revocation-flips-one-status-list-bit-and-fires-a-signed-callback-req-eudi-009
	 */
	private function fireStatusCallback(array $offerData): void {
		$callbackUrl = ($offerData['callbackUrl'] ?? null);
		$secret = ($offerData['callbackSigningSecret'] ?? null);
		if (empty($callbackUrl) === true || empty($secret) === true) {
			return;
		}

		$rawBody = json_encode(
			[
				'type' => 'nl.conduction.eudi.credential.revoked',
				'offerId' => ($offerData['uuid'] ?? null),
				'status' => 'revoked',
			],
			JSON_UNESCAPED_SLASHES
		);

		try {
			$signature = $this->signatureService->sign(rawBody: $rawBody, secret: $secret);
			$client = $this->clientService->newClient();
			$client->post(
				$callbackUrl,
				[
					'body' => $rawBody,
					'headers' => [
						'Content-Type' => 'application/json',
						'X-OpenConnector-Signature' => $signature,
					],
					'timeout' => 15,
				]
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'EudiCredentialOfferService: status callback delivery failed',
				['exception' => $exception->getMessage()]
			);
		}

	}//end fireStatusCallback()
}//end class
