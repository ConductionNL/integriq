<?php

/**
 * Unit tests for the envelope, its single-use guards and the exchange.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\EnvelopeCodeStore;
use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\SubjectEnvelope;
use OCA\Integriq\Auth\Idp\SubjectEnvelopeService;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests minting, guarding and exchanging a subject envelope.
 *
 * @spec openspec/changes/idp-broker-envelope-runtime/specs/digid-eherkenning-auth-adapter/spec.md#requirement-single-use-artefacts-refuse-rather-than-degrade
 */
class EnvelopeLifecycleTest extends TestCase {

	/**
	 * A key long enough to sign under.
	 *
	 * @var string
	 */
	private const SIGNING_KEY = 'envelope-signing-key-0123456789abcdef';

	/**
	 * The consumer's exchange secret.
	 *
	 * @var string
	 */
	private const CONSUMER_SECRET = 'portaliq-exchange-secret-0123456789';

	/**
	 * A cache factory whose distributed cache is the given one.
	 *
	 * @param object|null $cache The cache, or null for an instance with none.
	 *
	 * @return ICacheFactory The factory.
	 */
	private function cacheFactory(?object $cache): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cache !== null);

		if ($cache !== null) {
			$factory->method('createDistributed')->willReturn($cache);
		}

		return $factory;
	}//end cacheFactory()

	/**
	 * One subject to wrap.
	 *
	 * @param string $audience The consumer.
	 *
	 * @return SubjectEnvelope The envelope.
	 */
	private function envelope(string $audience = 'portaliq'): SubjectEnvelope {
		return new SubjectEnvelope(
			subject: 'b1f0c0de',
			subType: 'bsn-pseudonym',
			provider: 'digid',
			audience: $audience,
			organisation: 'gemeente-x',
			trust: 'substantial'
		);
	}//end envelope()

	/**
	 * A broker config answering the given values.
	 *
	 * @param string $flag The feature flag value.
	 * @param string $signingKey The signing key.
	 * @param array<string,string> $consumers The consumer secrets.
	 *
	 * @return IdpBrokerConfig The config.
	 */
	private function config(string $flag = '1', string $signingKey = self::SIGNING_KEY, array $consumers = []): IdpBrokerConfig {
		if ($consumers === []) {
			$consumers = ['portaliq' => self::CONSUMER_SECRET];
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($flag, $signingKey, $consumers) {
				return match ($key) {
					IdpBrokerConfig::KEY_ENABLED => $flag,
					IdpBrokerConfig::KEY_SIGNING => $signingKey,
					IdpBrokerConfig::KEY_CONSUMERS => (string)json_encode($consumers),
					default => $default,
				};
			}
		);

		return new IdpBrokerConfig($appConfig);
	}//end config()

	/**
	 * A minted envelope verifies, and carries the claims the spec names.
	 *
	 * @return void
	 */
	public function testAMintedEnvelopeVerifiesAndCarriesItsClaims(): void {
		$guard = new EnvelopeReplayGuard($this->cacheFactory(new FakeMemcache()));
		$service = new SubjectEnvelopeService(replayGuard: $guard);

		$token = $service->mint(envelope: $this->envelope(), signingKey: self::SIGNING_KEY);
		$verified = $service->verify(token: $token, signingKey: self::SIGNING_KEY, audience: 'portaliq');

		$this->assertSame('b1f0c0de', $verified->getSubject());
		$this->assertSame('bsn-pseudonym', $verified->getSubType());
		$this->assertSame('digid', $verified->getProvider());
		$this->assertSame('gemeente-x', $verified->getOrganisation());
		$this->assertSame('substantial', $verified->getTrust());

		$claims = json_decode((string)base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), false), true);
		$this->assertSame('idp-envelope', $claims['use']);
		$this->assertSame('openconnector-idp-broker', $claims['iss']);
		$this->assertLessThanOrEqual(60, ($claims['exp'] - $claims['iat']));
		$this->assertNotSame('', (string)$claims['jti']);

	}//end testAMintedEnvelopeVerifiesAndCarriesItsClaims()

	/**
	 * An envelope verifies once. The second verification burns on the same
	 * jti and is refused.
	 *
	 * @return void
	 */
	public function testAnEnvelopeVerifiesExactlyOnce(): void {
		$guard = new EnvelopeReplayGuard($this->cacheFactory(new FakeMemcache()));
		$service = new SubjectEnvelopeService(replayGuard: $guard);
		$token = $service->mint(envelope: $this->envelope(), signingKey: self::SIGNING_KEY);

		$service->verify(token: $token, signingKey: self::SIGNING_KEY, audience: 'portaliq');

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('already been used');
		$service->verify(token: $token, signingKey: self::SIGNING_KEY, audience: 'portaliq');

	}//end testAnEnvelopeVerifiesExactlyOnce()

	/**
	 * An expired envelope is refused.
	 *
	 * @return void
	 */
	public function testAnExpiredEnvelopeIsRefused(): void {
		$guard = new EnvelopeReplayGuard($this->cacheFactory(new FakeMemcache()));
		$service = new SubjectEnvelopeService(replayGuard: $guard);
		$token = $service->mint(envelope: $this->envelope(), signingKey: self::SIGNING_KEY, now: 1000);

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('outside its allowed lifetime');
		$service->verify(token: $token, signingKey: self::SIGNING_KEY, audience: 'portaliq', now: 1100);

	}//end testAnExpiredEnvelopeIsRefused()

	/**
	 * A ttl beyond the cap is capped rather than honoured, so nothing can mint
	 * an envelope that lives longer than a minute.
	 *
	 * @return void
	 */
	public function testALongTtlIsCappedAtSixtySeconds(): void {
		$guard = new EnvelopeReplayGuard($this->cacheFactory(new FakeMemcache()));
		$service = new SubjectEnvelopeService(replayGuard: $guard);

		$token = $service->mint(
			envelope: $this->envelope(),
			signingKey: self::SIGNING_KEY,
			ttlSeconds: 86400,
			now: 1000
		);

		$claims = json_decode((string)base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), false), true);
		$this->assertSame(1060, $claims['exp']);

	}//end testALongTtlIsCappedAtSixtySeconds()

	/**
	 * An envelope minted for one consumer is refused to another.
	 *
	 * @return void
	 */
	public function testAnEnvelopeForAnotherConsumerIsRefused(): void {
		$guard = new EnvelopeReplayGuard($this->cacheFactory(new FakeMemcache()));
		$service = new SubjectEnvelopeService(replayGuard: $guard);
		$token = $service->mint(envelope: $this->envelope(audience: 'portaliq'), signingKey: self::SIGNING_KEY);

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('minted for another consumer');
		$service->verify(token: $token, signingKey: self::SIGNING_KEY, audience: 'dossiq');

	}//end testAnEnvelopeForAnotherConsumerIsRefused()

	/**
	 * An envelope signed with another key does not verify.
	 *
	 * @return void
	 */
	public function testAForgedSignatureIsRefused(): void {
		$guard = new EnvelopeReplayGuard($this->cacheFactory(new FakeMemcache()));
		$service = new SubjectEnvelopeService(replayGuard: $guard);
		$token = $service->mint(envelope: $this->envelope(), signingKey: 'another-key-0123456789abcdef0123456789');

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('signature does not verify');
		$service->verify(token: $token, signingKey: self::SIGNING_KEY, audience: 'portaliq');

	}//end testAForgedSignatureIsRefused()

	/**
	 * A key too short to sign under is refused rather than used.
	 *
	 * @return void
	 */
	public function testAShortSigningKeyIsRefused(): void {
		$guard = new EnvelopeReplayGuard($this->cacheFactory(new FakeMemcache()));

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('shorter than 32 bytes');
		(new SubjectEnvelopeService(replayGuard: $guard))->mint(envelope: $this->envelope(), signingKey: 'kort');

	}//end testAShortSigningKeyIsRefused()

	/**
	 * Without a shared atomic cache the guard refuses instead of waving the
	 * artefact through.
	 *
	 * @return void
	 */
	public function testWithoutASharedCacheTheGuardRefuses(): void {
		$guard = new EnvelopeReplayGuard($this->cacheFactory(null));

		$this->assertFalse($guard->isUsable());

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('No shared cache is configured');
		$guard->burn(jti: 'some-jti', ttlSeconds: 60);

	}//end testWithoutASharedCacheTheGuardRefuses()

	/**
	 * A code redeems exactly once.
	 *
	 * @return void
	 */
	public function testACodeRedeemsExactlyOnce(): void {
		$store = new EnvelopeCodeStore($this->cacheFactory(new FakeMemcache()));
		$code = $store->issue(envelopeToken: 'the-envelope', consumer: 'portaliq');

		$this->assertSame('the-envelope', $store->redeem(code: $code, consumer: 'portaliq'));

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('already redeemed');
		$store->redeem(code: $code, consumer: 'portaliq');

	}//end testACodeRedeemsExactlyOnce()

	/**
	 * Another consumer's code is refused, and burnt in the process so it
	 * cannot be retried.
	 *
	 * @return void
	 */
	public function testAnotherConsumersCodeIsRefusedAndBurnt(): void {
		$cache = new FakeMemcache();
		$store = new EnvelopeCodeStore($this->cacheFactory($cache));
		$code = $store->issue(envelopeToken: 'the-envelope', consumer: 'portaliq');

		try {
			$store->redeem(code: $code, consumer: 'dossiq');
			$this->fail('The wrong consumer was allowed to redeem.');
		} catch (IdpAssertionException $exception) {
			$this->assertStringContainsString('belongs to another consumer', $exception->getMessage());
		}

		$this->assertSame([], $cache->entries, 'The code survived a refused redemption.');

	}//end testAnotherConsumersCodeIsRefusedAndBurnt()

	/**
	 * The code is not the cache key, so a cache dump hands nobody a usable
	 * code.
	 *
	 * @return void
	 */
	public function testTheCodeIsNotTheCacheKey(): void {
		$cache = new FakeMemcache();
		$store = new EnvelopeCodeStore($this->cacheFactory($cache));
		$code = $store->issue(envelopeToken: 'the-envelope', consumer: 'portaliq');

		$this->assertArrayNotHasKey($code, $cache->entries);
		$this->assertSame([hash('sha256', $code)], array_keys($cache->entries));

	}//end testTheCodeIsNotTheCacheKey()

	/**
	 * The exchange round trip: issue a code, redeem it with the right secret.
	 *
	 * @return void
	 */
	public function testTheExchangeRoundTripWorks(): void {
		$cache = new FakeMemcache();
		$exchange = new EnvelopeExchangeService(
			config: $this->config(),
			envelopeService: new SubjectEnvelopeService(
				replayGuard: new EnvelopeReplayGuard($this->cacheFactory($cache))
			),
			codeStore: new EnvelopeCodeStore($this->cacheFactory($cache)),
			logger: $this->createMock(LoggerInterface::class)
		);

		$code = $exchange->issueCode(envelope: $this->envelope());
		$token = $exchange->redeemCode(code: $code, consumer: 'portaliq', presentedSecret: self::CONSUMER_SECRET);

		$this->assertNotSame('', $token);
		$this->assertCount(3, explode('.', $token));

	}//end testTheExchangeRoundTripWorks()

	/**
	 * A wrong secret is refused, and the refusal does not say which check
	 * failed.
	 *
	 * @return void
	 */
	public function testAWrongSecretIsRefused(): void {
		$cache = new FakeMemcache();
		$exchange = new EnvelopeExchangeService(
			config: $this->config(),
			envelopeService: new SubjectEnvelopeService(
				replayGuard: new EnvelopeReplayGuard($this->cacheFactory($cache))
			),
			codeStore: new EnvelopeCodeStore($this->cacheFactory($cache)),
			logger: $this->createMock(LoggerInterface::class)
		);

		$code = $exchange->issueCode(envelope: $this->envelope());

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('The exchange request is refused.');
		$exchange->redeemCode(code: $code, consumer: 'portaliq', presentedSecret: 'wrong');

	}//end testAWrongSecretIsRefused()

	/**
	 * A consumer nobody configured is refused with the same message a wrong
	 * secret gets.
	 *
	 * @return void
	 */
	public function testAnUnknownConsumerIsRefusedIdentically(): void {
		$cache = new FakeMemcache();
		$exchange = new EnvelopeExchangeService(
			config: $this->config(),
			envelopeService: new SubjectEnvelopeService(
				replayGuard: new EnvelopeReplayGuard($this->cacheFactory($cache))
			),
			codeStore: new EnvelopeCodeStore($this->cacheFactory($cache)),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('The exchange request is refused.');
		$exchange->redeemCode(code: 'anything', consumer: 'nobody', presentedSecret: 'anything');

	}//end testAnUnknownConsumerIsRefusedIdentically()

	/**
	 * With the feature flag off nothing is issued and nothing is redeemed.
	 *
	 * @return void
	 */
	public function testTheFlagOffRefusesEverything(): void {
		$cache = new FakeMemcache();
		$exchange = new EnvelopeExchangeService(
			config: $this->config(flag: '0'),
			envelopeService: new SubjectEnvelopeService(
				replayGuard: new EnvelopeReplayGuard($this->cacheFactory($cache))
			),
			codeStore: new EnvelopeCodeStore($this->cacheFactory($cache)),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('feature flag is off');
		$exchange->issueCode(envelope: $this->envelope());

	}//end testTheFlagOffRefusesEverything()

	/**
	 * With the flag on but no signing key the broker still refuses, rather
	 * than signing under an empty key.
	 *
	 * @return void
	 */
	public function testNoSigningKeyRefusesEvenWithTheFlagOn(): void {
		$cache = new FakeMemcache();
		$exchange = new EnvelopeExchangeService(
			config: $this->config(signingKey: ''),
			envelopeService: new SubjectEnvelopeService(
				replayGuard: new EnvelopeReplayGuard($this->cacheFactory($cache))
			),
			codeStore: new EnvelopeCodeStore($this->cacheFactory($cache)),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('no envelope signing key is set');
		$exchange->issueCode(envelope: $this->envelope());

	}//end testNoSigningKeyRefusesEvenWithTheFlagOn()

}//end class
