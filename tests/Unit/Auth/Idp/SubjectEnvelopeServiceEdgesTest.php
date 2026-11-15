<?php

/**
 * The subject envelope's lifetime cap and the refusals for a token that is not a broker envelope.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
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
 * @covers \OCA\Integriq\Auth\Idp\SubjectEnvelopeService
 * @uses   \OCA\Integriq\Auth\Idp\SubjectEnvelope
 * @uses   \OCA\Integriq\Auth\Idp\EnvelopeReplayGuard
 * @uses   \OCA\Integriq\Auth\Idp\AtomicCache
 */
class SubjectEnvelopeServiceEdgesTest extends TestCase {

	private const KEY = '0123456789abcdef0123456789abcdef';

	private const NOW = 1790000000;

	/**
	 * The service over an in-memory shared cache.
	 *
	 * @return SubjectEnvelopeService
	 */
	private function service(): SubjectEnvelopeService {
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn(new FakeMemcache());

		return new SubjectEnvelopeService(replayGuard: new EnvelopeReplayGuard(cacheFactory: $factory));
	}//end service()

	/**
	 * One subject.
	 *
	 * @return SubjectEnvelope
	 */
	private function envelope(): SubjectEnvelope {
		return new SubjectEnvelope(
			subject: 'b1f0c0de',
			subType: 'bsn-pseudonym',
			provider: 'digid',
			audience: 'portal',
			organisation: 'gemeente-x',
			trust: 'substantial'
		);
	}//end envelope()

	/**
	 * The claims of a compact token, unverified.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string,mixed>
	 */
	private static function claims(string $token): array {
		return json_decode(json: (string) base64_decode(string: strtr(explode(separator: '.', string: $token)[1], '-_', '+/')), associative: true);
	}//end claims()

	/**
	 * A token signed with the broker key over any payload.
	 *
	 * @param string $payload The payload.
	 *
	 * @return string
	 */
	private static function signed(string $payload): string {
		$key = new JWK(values: ['kty' => 'oct', 'k' => rtrim(string: strtr(base64_encode(string: self::KEY), '+/', '-_'), characters: '=')]);
		$jws = (new JWSBuilder(signatureAlgorithmManager: new AlgorithmManager(algorithms: [new HS256()])))->create()
			->withPayload(payload: $payload)
			->addSignature(signatureKey: $key, protectedHeader: ['alg' => 'HS256', 'typ' => 'JWT'])
			->build();

		return (new CompactSerializer())->serialize(jws: $jws, signatureIndex: 0);
	}//end signed()

	/**
	 * Valid claims, with some changed.
	 *
	 * @param array<string,mixed> $overrides The changes.
	 *
	 * @return string
	 */
	private function token(array $overrides): string {
		$claims = array_merge(
			$this->envelope()->toClaims(),
			['jti' => 'j-1', 'iat' => self::NOW, 'exp' => (self::NOW + 30)],
			$overrides
		);

		return self::signed(payload: (string) json_encode(value: $claims));
	}//end token()

	/**
	 * Expect an assertion exception with exactly this message.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function expectRefusal(string $message): void {
		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(message: $message);
	}//end expectRefusal()

	/**
	 * A requested lifetime above the maximum is cut to it, below one second raised to one.
	 *
	 * @return void
	 */
	public function testTheLifetimeIsCapped(): void {
		$service = $this->service();

		$long  = self::claims(token: $service->mint(envelope: $this->envelope(), signingKey: self::KEY, ttlSeconds: 600, now: self::NOW));
		$short = self::claims(token: $service->mint(envelope: $this->envelope(), signingKey: self::KEY, ttlSeconds: 0, now: self::NOW));

		$this->assertSame(expected: self::NOW, actual: $long['iat']);
		$this->assertSame(expected: (self::NOW + 60), actual: $long['exp']);
		$this->assertSame(expected: (self::NOW + 1), actual: $short['exp']);
		$this->assertMatchesRegularExpression(pattern: '/^[0-9a-f]{32}$/', string: $long['jti']);
		$this->assertNotSame(expected: $long['jti'], actual: $short['jti']);
	}//end testTheLifetimeIsCapped()

	/**
	 * A signed token for another use is not an envelope.
	 *
	 * @return void
	 */
	public function testATokenForAnotherUseIsRefused(): void {
		$this->expectRefusal(message: 'The token is not a subject envelope, so it is refused.');

		$this->service()->verify(token: $this->token(overrides: ['use' => 'access']), signingKey: self::KEY, audience: 'portal', now: self::NOW);
	}//end testATokenForAnotherUseIsRefused()

	/**
	 * A signed envelope from another issuer is refused.
	 *
	 * @return void
	 */
	public function testAnotherIssuerIsRefused(): void {
		$this->expectRefusal(message: 'The envelope was not issued by this broker, so it is refused.');

		$this->service()->verify(token: $this->token(overrides: ['iss' => 'elsewhere']), signingKey: self::KEY, audience: 'portal', now: self::NOW);
	}//end testAnotherIssuerIsRefused()

	/**
	 * A string that is not a compact token is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableTokenIsRefused(): void {
		$this->expectRefusal(message: 'The envelope is not a readable token, so it is refused.');

		$this->service()->verify(token: 'not-a-token', signingKey: self::KEY, audience: 'portal', now: self::NOW);
	}//end testAnUnreadableTokenIsRefused()

	/**
	 * A signed payload that is not a claim set is refused.
	 *
	 * @return void
	 */
	public function testAPayloadThatIsNotAClaimSetIsRefused(): void {
		$this->expectRefusal(message: 'The envelope payload is not a claim set, so it is refused.');

		$this->service()->verify(token: self::signed(payload: '"just a string"'), signingKey: self::KEY, audience: 'portal', now: self::NOW);
	}//end testAPayloadThatIsNotAClaimSetIsRefused()

	/**
	 * An envelope verifies within its lifetime and not on its expiry second.
	 *
	 * @return void
	 */
	public function testTheExpirySecondIsOutside(): void {
		$service = $this->service();
		$this->assertSame(
			expected: 'b1f0c0de',
			actual: $service->verify(token: $this->token(overrides: []), signingKey: self::KEY, audience: 'portal', now: (self::NOW + 29))->getSubject()
		);

		$this->expectRefusal(message: 'The envelope is outside its allowed lifetime, so it is refused.');
		$service->verify(token: $this->token(overrides: ['jti' => 'j-2']), signingKey: self::KEY, audience: 'portal', now: (self::NOW + 30));
	}//end testTheExpirySecondIsOutside()
}//end class
