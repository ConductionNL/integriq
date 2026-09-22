<?php

/**
 * Integriq SubjectEnvelopeService.
 *
 * Mints and verifies the signed subject envelope. An envelope lives at most a
 * minute, carries `use: idp-envelope` so it can never be mistaken for a
 * session, and its `jti` is single-use.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Exception\IdpAssertionException;
use Throwable;

/**
 * Signs and verifies subject envelopes.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 */
class SubjectEnvelopeService {

	/**
	 * The signing algorithm.
	 *
	 * @var string
	 */
	public const ALGORITHM = 'HS256';

	/**
	 * The shortest signing key this service will sign under.
	 *
	 * HS256 under a short key is forgeable offline, and a forged envelope is
	 * a login as anybody.
	 *
	 * @var integer
	 */
	public const MINIMUM_KEY_BYTES = 32;

	/**
	 * Constructor.
	 *
	 * @param EnvelopeReplayGuard $replayGuard Refuses a `jti` that has already been seen.
	 */
	public function __construct(
		private readonly EnvelopeReplayGuard $replayGuard,
	) {

	}//end __construct()

	/**
	 * Mint one signed envelope.
	 *
	 * @param SubjectEnvelope $envelope The subject.
	 * @param string $signingKey The envelope signing key.
	 * @param integer $ttlSeconds How long it lives, capped at 60.
	 * @param integer|null $now The clock, injectable for tests.
	 *
	 * @return string The compact JWS.
	 *
	 * @throws IdpAssertionException When the signing key is unusable or signing fails.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function mint(
		SubjectEnvelope $envelope,
		string $signingKey,
		int $ttlSeconds = SubjectEnvelope::MAX_TTL_SECONDS,
		?int $now = null,
	): string {
		$this->assertKeyUsable(signingKey: $signingKey);

		$issuedAt = ($now ?? time());
		$lifetime = min($ttlSeconds, SubjectEnvelope::MAX_TTL_SECONDS);
		if ($lifetime < 1) {
			$lifetime = 1;
		}

		$claims = array_merge(
			$envelope->toClaims(),
			[
				'jti' => bin2hex(random_bytes(16)),
				'iat' => $issuedAt,
				'exp' => ($issuedAt + $lifetime),
			]
		);

		try {
			$jws = (new JWSBuilder(new AlgorithmManager([new HS256()])))->create()
				->withPayload((string)json_encode($claims, JSON_UNESCAPED_SLASHES))
				->addSignature($this->key(signingKey: $signingKey), ['alg' => self::ALGORITHM, 'typ' => 'JWT'])
				->build();
		} catch (Throwable $exception) {
			// Rethrown, never returned as a string: an error message returned
			// where a token was expected is a token-shaped value that fails
			// open somewhere downstream.
			throw new IdpAssertionException(
				message: 'The subject envelope could not be signed: ' . $exception->getMessage()
			);
		}

		return (new CompactSerializer())->serialize($jws, 0);

	}//end mint()

	/**
	 * Verify one envelope and return its subject.
	 *
	 * Checks the signature, the `use` claim, the expiry and the audience, and
	 * burns the `jti` so a second verification of the same envelope fails.
	 *
	 * @param string $token The compact JWS.
	 * @param string $signingKey The envelope signing key.
	 * @param string $audience The consumer presenting it.
	 * @param integer|null $now The clock, injectable for tests.
	 *
	 * @return SubjectEnvelope The verified subject.
	 *
	 * @throws IdpAssertionException On any failure. The reason names the check, never the claims.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function verify(string $token, string $signingKey, string $audience, ?int $now = null): SubjectEnvelope {
		$this->assertKeyUsable(signingKey: $signingKey);

		$claims = $this->readVerifiedClaims(token: $token, signingKey: $signingKey);

		if ((string)($claims['use'] ?? '') !== SubjectEnvelope::USE_CLAIM) {
			throw new IdpAssertionException(
				message: 'The token is not a subject envelope, so it is refused.'
			);
		}

		if ((string)($claims['iss'] ?? '') !== SubjectEnvelope::ISSUER) {
			throw new IdpAssertionException(
				message: 'The envelope was not issued by this broker, so it is refused.'
			);
		}

		$clock = ($now ?? time());
		$expiry = (int)($claims['exp'] ?? 0);
		$issuedAt = (int)($claims['iat'] ?? 0);

		if ($expiry <= $clock || $expiry > ($issuedAt + SubjectEnvelope::MAX_TTL_SECONDS)) {
			// Both directions matter. An expired envelope is refused, and so
			// is one whose own `exp` sits further from its `iat` than the TTL
			// allows: a minter that stretched the lifetime is not trusted to
			// have respected anything else either.
			throw new IdpAssertionException(
				message: 'The envelope is outside its allowed lifetime, so it is refused.'
			);
		}

		if ((string)($claims['audience'] ?? '') !== $audience) {
			throw new IdpAssertionException(
				message: 'The envelope was minted for another consumer, so it is refused.'
			);
		}

		$this->replayGuard->burn(jti: (string)($claims['jti'] ?? ''), ttlSeconds: SubjectEnvelope::MAX_TTL_SECONDS);

		return SubjectEnvelope::fromClaims($claims);

	}//end verify()

	/**
	 * The claims of a token whose signature verified.
	 *
	 * @param string $token The compact JWS.
	 * @param string $signingKey The signing key.
	 *
	 * @return array<string,mixed> The claims.
	 *
	 * @throws IdpAssertionException When the token does not parse or does not verify.
	 */
	private function readVerifiedClaims(string $token, string $signingKey): array {
		try {
			$jws = (new CompactSerializer())->unserialize($token);
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The envelope is not a readable token, so it is refused.');
		}

		$verifier = new JWSVerifier(new AlgorithmManager([new HS256()]));
		if ($verifier->verifyWithKey($jws, $this->key(signingKey: $signingKey), 0) === false) {
			throw new IdpAssertionException(message: 'The envelope signature does not verify, so it is refused.');
		}

		$claims = json_decode((string)$jws->getPayload(), true);
		if (is_array($claims) === false) {
			throw new IdpAssertionException(message: 'The envelope payload is not a claim set, so it is refused.');
		}

		return $claims;

	}//end readVerifiedClaims()

	/**
	 * The signing key as a JWK.
	 *
	 * @param string $signingKey The raw key.
	 *
	 * @return JWK The key.
	 */
	private function key(string $signingKey): JWK {
		return new JWK(
			[
				'kty' => 'oct',
				'k' => rtrim(strtr(base64_encode($signingKey), '+/', '-_'), '='),
			]
		);

	}//end key()

	/**
	 * Refuse a key too short to sign under.
	 *
	 * @param string $signingKey The raw key.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When the key is too short.
	 */
	private function assertKeyUsable(string $signingKey): void {
		if (strlen($signingKey) < self::MINIMUM_KEY_BYTES) {
			throw new IdpAssertionException(
				message: 'The envelope signing key is shorter than '
				. (string)self::MINIMUM_KEY_BYTES . ' bytes, so no envelope is issued or accepted.'
			);
		}

	}//end assertKeyUsable()

}//end class
