<?php

/**
 * Verdict matrix of WebhookSignatureService::verify().
 *
 * Every scheme against valid, tampered, stale, malformed and missing headers,
 * the empty and the placeholder secret and a non-default tolerance: the
 * accepted cases and the refused ones, plus the warnings the untimestamped
 * schemes log for a tolerance they cannot use.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/webhook-signing/spec.md#requirement-inbound-verification-reads-the-microsoft-teams-scheme-req-whs-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\WebhookSignatureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\WebhookSignatureService
 */
class WebhookSignatureVerdictGoldenTest extends TestCase {
	private const BODY = '{"event":"created","id":42}';
	private const SECRET = 'whsec_shared';
	private const TEAMS_SECRET = 'c2VjcmV0LWJ5dGVz';

	/**
	 * Every verdict, and the warnings logged.
	 *
	 * @return void
	 */
	public function testEveryVerdict(): void {
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$service = new WebhookSignatureService($logger);

		$now = time();
		$github = 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET);
		$teams = 'HMAC ' . base64_encode(hash_hmac('sha256', self::BODY, (string)base64_decode(self::TEAMS_SECRET), true));
		$stamped = $service->sign(self::BODY, self::SECRET, null, $now);
		$dual = $service->sign(self::BODY, 'whsec_new', self::SECRET, $now);
		$stale = $service->sign(self::BODY, self::SECRET, null, ($now - 1000));
		$cfg = static fn (string $scheme, string $secret = self::SECRET, ?int $tolerance = null): array => array_filter(
			['scheme' => $scheme, 'secret' => $secret, 'toleranceSeconds' => $tolerance],
			static fn ($value): bool => $value !== null
		);

		$cases = [
			'oc-valid' => [self::BODY, $stamped, $cfg('openconnector'), true],
			'oc-default-scheme' => [self::BODY, $stamped, ['secret' => self::SECRET], true],
			'stripe-valid' => [self::BODY, $stamped, $cfg('stripe'), true],
			'oc-dual-old-secret' => [self::BODY, $dual, $cfg('openconnector'), true],
			'oc-tampered' => [self::BODY . ' ', $stamped, $cfg('openconnector'), false],
			'oc-stale' => [self::BODY, $stale, $cfg('openconnector'), false],
			'oc-stale-wide-tolerance' => [self::BODY, $stale, $cfg('openconnector', self::SECRET, 2000), true],
			'oc-no-timestamp' => [self::BODY, 'v1=' . hash_hmac('sha256', $now . '.' . self::BODY, self::SECRET), $cfg('openconnector'), false],
			'oc-no-signature' => [self::BODY, 't=' . $now, $cfg('openconnector'), false],
			'oc-garbage' => [self::BODY, 'garbage', $cfg('openconnector'), false],
			'oc-bad-timestamp' => [self::BODY, 't=12a,v1=abc', $cfg('openconnector'), false],
			'oc-empty-header' => [self::BODY, '', $cfg('openconnector'), false],
			'oc-empty-secret' => [self::BODY, $stamped, $cfg('openconnector', ''), false],
			'oc-placeholder-secret' => [self::BODY, $service->sign(self::BODY, SensitiveFieldRegistry::PLACEHOLDER, null, $now), $cfg('openconnector', SensitiveFieldRegistry::PLACEHOLDER), false],
			'github-valid' => [self::BODY, $github, $cfg('github'), true],
			'github-bare-hex' => [self::BODY, substr($github, 7), $cfg('github'), true],
			'github-tampered' => [self::BODY . 'x', $github, $cfg('github'), false],
			'github-tolerance-warns' => [self::BODY, $github, $cfg('github', self::SECRET, 60), true],
			'github-timestamped-header' => [self::BODY, $stamped, $cfg('github'), false],
			'teams-valid' => [self::BODY, $teams, $cfg('teams', self::TEAMS_SECRET), true],
			'teams-padded' => [self::BODY, '  ' . $teams . '  ', $cfg('teams', self::TEAMS_SECRET), true],
			'teams-literal-secret' => [self::BODY, 'HMAC ' . base64_encode(hash_hmac('sha256', self::BODY, self::TEAMS_SECRET, true)), $cfg('teams', self::TEAMS_SECRET), false],
			'teams-no-prefix' => [self::BODY, substr($teams, 5), $cfg('teams', self::TEAMS_SECRET), false],
			'teams-empty-value' => [self::BODY, 'HMAC   ', $cfg('teams', self::TEAMS_SECRET), false],
			'teams-undecodable-secret' => [self::BODY, $teams, $cfg('teams', '***not base64***'), false],
			'teams-tolerance-warns' => [self::BODY, $teams, $cfg('teams', self::TEAMS_SECRET, 10), true],
			'unknown-scheme-reads-timestamped' => [self::BODY, $stamped, $cfg('custom'), true],
		];

		$verdicts = [];
		$expected = [];
		foreach ($cases as $name => [$body, $header, $config, $verdict]) {
			$verdicts[$name] = $service->verify($body, $header, $config);
			$expected[$name] = $verdict;
		}

		$this->assertSame($expected, $verdicts);
		$this->assertSame(
			[
				'webhook_signature: toleranceSeconds is ignored for scheme "github" (the scheme carries no timestamp).',
				'webhook_signature: toleranceSeconds is ignored for scheme "teams" (the scheme carries no timestamp).',
			],
			$warnings
		);
	}
}
