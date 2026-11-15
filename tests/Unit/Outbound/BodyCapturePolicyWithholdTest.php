<?php

/**
 * Unit tests for the parts of BodyCapturePolicy that the call service tests
 * do not reach: withholding a record's bodies, recording the asking user,
 * the window limit falling back to its default, and the replay-request
 * expiry of a failed call outside a window.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/call-log-without-bodies/specs/outbound-call-log/spec.md#requirement-a-caller-can-have-a-call-recorded-without-any-body
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use DateTimeImmutable;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\RetentionDefaultsInterface;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Withheld records, the window limit and the replay expiry.
 *
 * @covers \OCA\Integriq\Outbound\Call\BodyCapturePolicy
 */
final class BodyCapturePolicyWithholdTest extends TestCase {

	private const NOW = '2026-06-01T12:00:00+00:00';

	/**
	 * A policy on a fixed clock, with the given window limit and session user.
	 *
	 * @param int         $maxHours The configured window limit.
	 * @param string|null $userId   The session user, or null for no session.
	 *
	 * @return BodyCapturePolicy
	 */
	private function policy(int $maxHours = 72, ?string $userId = null): BodyCapturePolicy {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => $key === BodyCapturePolicy::MAX_HOURS_KEY ? $maxHours : $default
		);
		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		return new BodyCapturePolicy(appConfig: $config, clock: $clock, userSession: $session);
	}//end policy()

	/**
	 * A full call record with both bodies, headers and a query.
	 *
	 * @return array<string, mixed>
	 */
	private static function record(): array {
		return [
			'source' => 'src-1',
			'statusCode' => 500,
			'request' => [
				'url' => 'https://api.example.org/v1/zaken/123?token=abc#frag',
				'method' => 'POST',
				'headers' => ['Authorization' => 'Bearer x'],
				'json' => ['bsn' => '999'],
			],
			'response' => [
				'statusCode' => 500,
				'statusMessage' => 'Server Error',
				'responseTime' => 120,
				'size' => 42,
				'encoding' => 'utf-8',
				'headers' => ['Set-Cookie' => 'a'],
				'body' => '{"error":"x"}',
			],
			'replayRequest' => ['json' => ['bsn' => '999']],
			'bodyExpiresAt' => '2026-06-08T12:00:00+00:00',
			'bodyCaptured' => true,
		];
	}//end record()

	/**
	 * Withholding keeps the method and the URL without query or fragment, and only the response metadata.
	 *
	 * @return void
	 */
	public function testWithholdingKeepsOnlyTheCallMetadata(): void {
		$this->assertSame(
			[
				'source' => 'src-1',
				'statusCode' => 500,
				'request' => ['url' => 'https://api.example.org/v1/zaken/123', 'method' => 'POST'],
				'response' => ['statusCode' => 500, 'statusMessage' => 'Server Error', 'responseTime' => 120, 'size' => 42, 'encoding' => 'utf-8'],
				'bodyCaptured' => false,
			],
			$this->policy()->withhold(self::record())
		);
	}//end testWithholdingKeepsOnlyTheCallMetadata()

	/**
	 * A record without the capture flag does not gain one; bags that are not arrays become empty.
	 *
	 * @return void
	 */
	public function testWithholdingADegenerateRecord(): void {
		$this->assertSame(
			['request' => ['url' => '', 'method' => ''], 'response' => []],
			$this->policy()->withhold(['request' => 'raw', 'response' => null])
		);
		$this->assertSame(
			['request' => ['url' => 'https://x.test/a', 'method' => 'GET'], 'response' => []],
			$this->policy()->withhold(['request' => ['url' => 'https://x.test/a?', 'method' => 'GET']])
		);
	}//end testWithholdingADegenerateRecord()

	/**
	 * A withheld record names the asking user and is marked as without bodies.
	 *
	 * @return void
	 */
	public function testAWithheldRecordNamesTheAskingUser(): void {
		$record = $this->policy(userId: 'alice')->withheldRecord(['request' => ['url' => 'https://x.test/a?q=1', 'method' => 'GET'], 'response' => ['body' => 'x']]);

		$this->assertSame(
			['request' => ['url' => 'https://x.test/a', 'method' => 'GET'], 'response' => [], 'bodyCaptured' => false, 'userId' => 'alice'],
			$record
		);
	}//end testAWithheldRecordNamesTheAskingUser()

	/**
	 * Without a session user, or with an empty id, no user is recorded.
	 *
	 * @return void
	 */
	public function testAWithheldRecordWithoutAUserNamesNone(): void {
		$this->assertArrayNotHasKey('userId', $this->policy()->withheldRecord(self::record()));
		$this->assertArrayNotHasKey('userId', $this->policy(userId: '')->withheldRecord(self::record()));
		$this->assertArrayNotHasKey('userId', (new BodyCapturePolicy(appConfig: $this->createMock(IAppConfig::class)))->withheldRecord(self::record()));
	}//end testAWithheldRecordWithoutAUserNamesNone()

	/**
	 * A window limit below one hour falls back to the default; a positive one is used.
	 *
	 * @return void
	 */
	public function testTheWindowLimitFallsBackBelowOneHour(): void {
		$this->assertSame(BodyCapturePolicy::DEFAULT_MAX_HOURS, $this->policy(maxHours: 0)->maxHours());
		$this->assertSame(BodyCapturePolicy::DEFAULT_MAX_HOURS, $this->policy(maxHours: -5)->maxHours());
		$this->assertSame(5, $this->policy(maxHours: 5)->maxHours());
	}//end testTheWindowLimitFallsBackBelowOneHour()

	/**
	 * A failed call outside a window keeps its replay request until the record's error expiry.
	 *
	 * @return void
	 */
	public function testAFailedCallKeepsItsReplayRequestUntilTheErrorExpiry(): void {
		$record = $this->policy()->apply(self::record(), [], false, new DateTimeImmutable('2026-07-01T00:00:00+00:00'));

		$this->assertFalse($record['bodyCaptured']);
		$this->assertSame(self::record()['request'], $record['replayRequest']);
		$this->assertSame('2026-07-01T00:00:00+00:00', $record['bodyExpiresAt']);
		$this->assertArrayNotHasKey('json', $record['request']);
		$this->assertArrayNotHasKey('body', $record['response']);
	}//end testAFailedCallKeepsItsReplayRequestUntilTheErrorExpiry()

	/**
	 * A failed call kept for ever still lets its replay request go after the default error retention.
	 *
	 * @return void
	 */
	public function testAFailedCallKeptForEverStillLetsItsReplayRequestGo(): void {
		$record = $this->policy()->apply(['statusCode' => 0, 'request' => ['url' => 'https://x.test'], 'response' => []], [], false, null);

		$seconds = intdiv(RetentionDefaultsInterface::CALL_LOG, 1000);
		$this->assertSame(
			(new DateTimeImmutable(self::NOW))->modify('+' . $seconds . ' seconds')->format('c'),
			$record['bodyExpiresAt']
		);
		$this->assertSame(['url' => 'https://x.test'], $record['replayRequest']);
	}//end testAFailedCallKeptForEverStillLetsItsReplayRequestGo()

	/**
	 * A successful call outside a window keeps neither a replay request nor an expiry.
	 *
	 * @return void
	 */
	public function testASuccessfulCallOutsideAWindowKeepsNoReplay(): void {
		$record = $this->policy()->apply(['statusCode' => 204, 'request' => ['url' => 'https://x.test', 'body' => 'b'], 'response' => []], [], false, null);

		$this->assertSame(['statusCode' => 204, 'request' => ['url' => 'https://x.test'], 'response' => [], 'bodyCaptured' => false], $record);
	}//end testASuccessfulCallOutsideAWindowKeepsNoReplay()
}//end class
