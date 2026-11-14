<?php

/**
 * Unit tests for SubjectEnvelopeService: minting and every refusal of verify().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\SubjectEnvelope;
use OCA\Integriq\Auth\Idp\SubjectEnvelopeService;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests SubjectEnvelopeService.
 *
 * @covers \OCA\Integriq\Auth\Idp\SubjectEnvelopeService
 * @uses \OCA\Integriq\Auth\Idp\AtomicCache
 * @uses \OCA\Integriq\Auth\Idp\EnvelopeReplayGuard
 * @uses \OCA\Integriq\Auth\Idp\SubjectEnvelope
 */
class SubjectEnvelopeServiceTest extends TestCase {

	/**
	 * A key long enough to sign under.
	 */
	private const KEY = 'envelope-signing-key-0123456789abcdef';

	/**
	 * A fixed clock.
	 */
	private const NOW = 1_800_000_000;

	/**
	 * The service under test.
	 *
	 * @var SubjectEnvelopeService
	 */
	private SubjectEnvelopeService $service;

	/**
	 * Set up the service over an in-memory replay cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn(new FakeMemcache());
		$this->service = new SubjectEnvelopeService(replayGuard: new EnvelopeReplayGuard($factory));
	}

	/**
	 * One subject to wrap.
	 *
	 * @return SubjectEnvelope The envelope.
	 */
	private function envelope(): SubjectEnvelope {
		return new SubjectEnvelope(
			subject: 'b1f0c0de',
			subType: 'bsn-pseudonym',
			provider: 'digid',
			audience: 'portaliq',
			organisation: 'gemeente-x',
			trust: 'substantial'
		);
	}

	/**
	 * Sign an arbitrary payload under the key, as a broker would.
	 *
	 * @param string $payload The raw payload.
	 * @param string $key     The signing key.
	 *
	 * @return string The compact token.
	 */
	private function forge(string $payload, string $key = self::KEY): string {
		$jwk = new JWK(['kty' => 'oct', 'k' => rtrim(strtr(base64_encode($key), '+/', '-_'), '=')]);
		$jws = (new JWSBuilder(new AlgorithmManager([new HS256()])))->create()
			->withPayload($payload)
			->addSignature($jwk, ['alg' => 'HS256', 'typ' => 'JWT'])
			->build();

		return (new CompactSerializer())->serialize($jws, 0);
	}

	/**
	 * Valid claims, with overrides.
	 *
	 * @param array<string, mixed> $overrides The claims to replace.
	 *
	 * @return string The signed token.
	 */
	private function claims(array $overrides = []): string {
		$claims = array_merge(
			$this->envelope()->toClaims(),
			['jti' => 'jti-1', 'iat' => self::NOW, 'exp' => self::NOW + 60],
			$overrides
		);

		return $this->forge(payload: (string)json_encode($claims));
	}

	/**
	 * The payload of a compact token.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed> The claims.
	 */
	private function payload(string $token): array {
		$part = explode('.', $token)[1];
		return json_decode((string)base64_decode(strtr($part, '-_', '+/')), true);
	}

	/**
	 * Assert that verifying the token is refused with the given message.
	 *
	 * @param string $token   The token.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(string $token, string $message): void {
		try {
			$this->service->verify(token: $token, signingKey: self::KEY, audience: 'portaliq', now: self::NOW);
			$this->fail('Expected a refusal: ' . $message);
		} catch (IdpAssertionException $exception) {
			$this->assertSame($message, $exception->getMessage());
		}
	}

	/**
	 * A minted token carries the envelope claims, a fresh jti and the issue
	 * time; its lifetime is clamped to one to sixty seconds.
	 *
	 * @return void
	 */
	public function testMintClampsTheLifetime(): void {
		$short = $this->payload($this->service->mint(envelope: $this->envelope(), signingKey: self::KEY, ttlSeconds: 0, now: self::NOW));
		$long = $this->payload($this->service->mint(envelope: $this->envelope(), signingKey: self::KEY, ttlSeconds: 600, now: self::NOW));
		$mid = $this->payload($this->service->mint(envelope: $this->envelope(), signingKey: self::KEY, ttlSeconds: 30, now: self::NOW));

		$this->assertSame(self::NOW, $short['iat']);
		$this->assertSame(self::NOW + 1, $short['exp']);
		$this->assertSame(self::NOW + 60, $long['exp']);
		$this->assertSame(self::NOW + 30, $mid['exp']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $short['jti']);
		$this->assertNotSame($short['jti'], $long['jti']);
		$this->assertSame('b1f0c0de', $short['sub']);
		$this->assertSame(SubjectEnvelope::USE_CLAIM, $short['use']);
	}

	/**
	 * A key shorter than 32 bytes is refused on both sides.
	 *
	 * @return void
	 */
	public function testAShortKeyIsRefused(): void {
		$message = 'The envelope signing key is shorter than 32 bytes, so no envelope is issued or accepted.';
		try {
			$this->service->mint(envelope: $this->envelope(), signingKey: str_repeat('k', 31));
			$this->fail('A short key must not mint.');
		} catch (IdpAssertionException $exception) {
			$this->assertSame($message, $exception->getMessage());
		}

		$this->expectExceptionMessage($message);
		$this->service->verify(token: $this->claims(), signingKey: str_repeat('k', 31), audience: 'portaliq');
	}

	/**
	 * A valid token verifies once to its envelope; its jti is then burnt.
	 *
	 * @return void
	 */
	public function testAValidTokenVerifiesOnce(): void {
		$token = $this->claims();

		$envelope = $this->service->verify(token: $token, signingKey: self::KEY, audience: 'portaliq', now: self::NOW);

		$this->assertSame($this->envelope()->toClaims(), $envelope->toClaims());
		$this->assertRefused($token, 'This artefact has already been used, so it is refused.');
	}

	/**
	 * Something that is not a compact token is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableTokenIsRefused(): void {
		$this->assertRefused('not-a-token', 'The envelope is not a readable token, so it is refused.');
	}

	/**
	 * A token signed under another key does not verify.
	 *
	 * @return void
	 */
	public function testAForeignSignatureIsRefused(): void {
		$this->assertRefused(
			$this->forge(payload: '{}', key: 'another-key-0123456789abcdefghijkl'),
			'The envelope signature does not verify, so it is refused.'
		);
	}

	/**
	 * A signed payload that is not a JSON object is refused.
	 *
	 * @return void
	 */
	public function testAPayloadThatIsNoClaimSetIsRefused(): void {
		$this->assertRefused($this->forge(payload: '"just a string"'), 'The envelope payload is not a claim set, so it is refused.');
	}

	/**
	 * A token for another use, or from another issuer, is refused.
	 *
	 * @return void
	 */
	public function testAnotherUseOrIssuerIsRefused(): void {
		$this->assertRefused($this->claims(['use' => 'access-token']), 'The token is not a subject envelope, so it is refused.');
		$this->assertRefused($this->claims(['iss' => 'someone-else']), 'The envelope was not issued by this broker, so it is refused.');
	}

	/**
	 * An expired token, or one claiming more than the maximum lifetime, is refused.
	 *
	 * @return void
	 */
	public function testALifetimeOutsideTheWindowIsRefused(): void {
		$message = 'The envelope is outside its allowed lifetime, so it is refused.';
		$this->assertRefused($this->claims(['exp' => self::NOW]), $message);
		$this->assertRefused($this->claims(['iat' => self::NOW - 10, 'exp' => self::NOW + 61]), $message);
	}

	/**
	 * A token minted for another consumer is refused, and its jti is not burnt.
	 *
	 * @return void
	 */
	public function testAnotherAudienceIsRefused(): void {
		$token = $this->claims();
		try {
			$this->service->verify(token: $token, signingKey: self::KEY, audience: 'other', now: self::NOW);
			$this->fail('Another audience must be refused.');
		} catch (IdpAssertionException $exception) {
			$this->assertSame('The envelope was minted for another consumer, so it is refused.', $exception->getMessage());
		}

		$this->assertSame('b1f0c0de', $this->service->verify(token: $token, signingKey: self::KEY, audience: 'portaliq', now: self::NOW)->toClaims()['sub']);
	}
}
