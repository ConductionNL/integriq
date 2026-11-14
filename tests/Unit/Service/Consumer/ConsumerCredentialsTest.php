<?php

/**
 * Unit tests for ConsumerCredentials: keyed hashes, generation, live matching and last used.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-consumer-credentials/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use DateTimeImmutable;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\Consumer\ConsumerCredentials
 */
final class ConsumerCredentialsTest extends TestCase {

	/**
	 * The fake app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * How many values the fake random source generated.
	 *
	 * @var int
	 */
	private int $generated = 0;

	public function testAKeyIsHashedWithTheInstancePepperNotPlainSha256(): void {
		$credentials = $this->credentials();

		$hash = $credentials->hashKey(key: 'secret-key');

		$this->assertSame(hash_hmac('sha256', 'secret-key', $this->config['consumer_credential_pepper']), $hash);
		$this->assertNotSame(hash('sha256', 'secret-key'), $hash);
		$this->assertSame($hash, $credentials->hashKey(key: 'secret-key'));
	}

	public function testThePepperIsGeneratedOnceAndKeptAsASensitiveValue(): void {
		$writes = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$writes): bool {
				$writes[] = [$app, $key, $lazy, $sensitive];
				$this->config[$key] = $value;
				return true;
			}
		);

		$credentials = new ConsumerCredentials($appConfig, $this->random());
		$credentials->hashKey(key: 'a');
		$credentials->hashKey(key: 'b');

		$this->assertSame([['integriq', 'consumer_credential_pepper', true, true]], $writes);
		$this->assertSame(1, $this->generated);
		$this->assertSame(64, strlen($this->config['consumer_credential_pepper']));
	}

	public function testAStoredPepperIsReusedAcrossInstances(): void {
		$first = $this->credentials()->hashKey(key: 'k');

		$this->assertSame($first, $this->credentials()->hashKey(key: 'k'));
	}

	public function testAGeneratedKeyIsReturnedButNeverStoredInItsEntry(): void {
		$credentials = $this->credentials();

		$generated = $credentials->generate(label: 'Partner 2026', expiresAt: '2027-01-01T00:00:00+00:00', now: new DateTimeImmutable('2026-11-13T10:00:00+00:00'));

		$key = $generated['key'];
		$entry = $generated['credential'];
		$this->assertSame(ConsumerCredentials::KEY_LENGTH, strlen($key));
		$this->assertNotContains($key, $entry);
		$this->assertSame(substr($key, 0, 6), $entry['keyPrefix']);
		$this->assertSame($credentials->hashKey(key: $key), $entry['keyHash']);
		$this->assertSame('Partner 2026', $entry['label']);
		$this->assertSame('apiKey', $entry['type']);
		$this->assertSame('2026-11-13T10:00:00+00:00', $entry['createdAt']);
		$this->assertSame('2027-01-01T00:00:00+00:00', $entry['expiresAt']);
		$this->assertNull($entry['lastUsedAt']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $entry['id']);
	}

	public function testEveryLiveKeyOfAConsumerMatches(): void {
		$credentials = $this->credentials();
		$now = new DateTimeImmutable('2026-11-13T10:00:00+00:00');
		$list = [
			['type' => 'clientCertificate', 'keyHash' => $credentials->hashKey(key: 'old')],
			$credentials->apiKeyEntry(key: 'old', label: 'old'),
			$credentials->apiKeyEntry(key: 'new', label: 'new', expiresAt: '2026-12-01T00:00:00+00:00'),
		];

		$this->assertSame(1, $credentials->matchingApiKey(credentials: $list, key: 'old', now: $now));
		$this->assertSame(2, $credentials->matchingApiKey(credentials: $list, key: 'new', now: $now));
		$this->assertNull($credentials->matchingApiKey(credentials: $list, key: 'other', now: $now));
		$this->assertNull($credentials->matchingApiKey(credentials: $list, key: '', now: $now));
	}

	public function testAnExpiredOrUnreadableExpiryNeverMatches(): void {
		$credentials = $this->credentials();
		$now = new DateTimeImmutable('2026-11-13T10:00:00+00:00');

		$expired = [$credentials->apiKeyEntry(key: 'k', label: 'l', expiresAt: '2026-11-13T10:00:00+00:00')];
		$unreadable = [$credentials->apiKeyEntry(key: 'k', label: 'l', expiresAt: 'soon')];
		$notAString = [['expiresAt' => 5] + $credentials->apiKeyEntry(key: 'k', label: 'l')];

		$this->assertNull($credentials->matchingApiKey(credentials: $expired, key: 'k', now: $now));
		$this->assertNull($credentials->matchingApiKey(credentials: $unreadable, key: 'k', now: $now));
		$this->assertNull($credentials->matchingApiKey(credentials: $notAString, key: 'k', now: $now));
		$this->assertTrue($credentials->isLive(credential: ['expiresAt' => ''], now: $now));
	}

	public function testAListThatIsNotAListOfEntriesMatchesNothing(): void {
		$credentials = $this->credentials();
		$now = new DateTimeImmutable();
		$entry = $credentials->apiKeyEntry(key: 'k', label: 'l');

		$this->assertNull($credentials->matchingApiKey(credentials: null, key: 'k', now: $now));
		$this->assertNull($credentials->matchingApiKey(credentials: ['a' => $entry], key: 'k', now: $now));
		$this->assertNull($credentials->matchingApiKey(credentials: ['x', ['type' => 'apiKey', 'keyHash' => 7]], key: 'k', now: $now));
	}

	public function testLastUsedIsWrittenAtMostOnceAMinute(): void {
		$credentials = $this->credentials();
		$now = new DateTimeImmutable('2026-11-13T10:00:00+00:00');

		$this->assertSame('2026-11-13T10:00:00+00:00', $credentials->touched(credential: ['lastUsedAt' => null], now: $now)['lastUsedAt']);
		$this->assertNull($credentials->touched(credential: ['lastUsedAt' => '2026-11-13T09:59:01+00:00'], now: $now));
		$this->assertSame('2026-11-13T10:00:00+00:00', $credentials->touched(credential: ['lastUsedAt' => '2026-11-13T09:59:00+00:00'], now: $now)['lastUsedAt']);
		$this->assertSame('2026-11-13T10:00:00+00:00', $credentials->touched(credential: ['lastUsedAt' => 'garbled'], now: $now)['lastUsedAt']);
	}

	public function testThePrefixIsSixCharactersAndNeverMoreThanAQuarterOfTheKey(): void {
		$this->assertSame('abcdef', ConsumerCredentials::prefixOf(key: str_repeat('abcdefgh', 6)));
		$this->assertSame('abcd', ConsumerCredentials::prefixOf(key: 'abcdefghijklmnop'));
		$this->assertSame('', ConsumerCredentials::prefixOf(key: 'abc'));
	}

	public function testAShownCredentialHasNoHash(): void {
		$entry = $this->credentials()->apiKeyEntry(key: 'k', label: 'l');

		$shown = ConsumerCredentials::shown(credential: $entry);

		$this->assertArrayNotHasKey('keyHash', $shown);
		$this->assertSame($entry['keyPrefix'], $shown['keyPrefix']);
	}

	/**
	 * The credentials over the fake app config and random source.
	 *
	 * @return ConsumerCredentials
	 */
	private function credentials(): ConsumerCredentials {
		return new ConsumerCredentials($this->appConfig(), $this->random());
	}

	/**
	 * An in-memory app config.
	 *
	 * @return IAppConfig&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return $appConfig;
	}

	/**
	 * A random source that answers distinct strings of the asked length.
	 *
	 * @return ISecureRandom
	 */
	private function random(): ISecureRandom {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length): string {
				$this->generated++;
				return substr(str_repeat(hash('sha256', 'r' . $this->generated), 4), 0, $length);
			}
		);

		return $random;
	}
}
