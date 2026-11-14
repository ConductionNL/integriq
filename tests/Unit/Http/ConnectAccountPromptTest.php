<?php

/**
 * Unit tests for ConnectAccountPrompt.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Http;

use OCA\Integriq\Http\ConnectAccountPrompt;
use OCA\Integriq\Http\ProblemResponse;
use OCA\Integriq\Service\PerUserAccountNotConnectedException;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The connect prompt names the provider and the broker's connect start.
 */
#[CoversClass(ConnectAccountPrompt::class)]
#[UsesClass(ProblemResponse::class)]
#[UsesClass(PerUserAccountNotConnectedException::class)]
final class ConnectAccountPromptTest extends TestCase {

	/**
	 * The prompt over a URL generator that records what it is asked for.
	 *
	 * @param array<int, array{0: string, 1: array<string, string>}> $asked Collects the routes asked for.
	 *
	 * @return ConnectAccountPrompt
	 */
	private function prompt(array &$asked): ConnectAccountPrompt {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			function (string $route, array $parameters = []) use (&$asked): string {
				$asked[] = [$route, $parameters];
				return 'https://cloud.example.nl/connect';
			}
		);

		return new ConnectAccountPrompt(urlGenerator: $urls);
	}//end prompt()

	/**
	 * 401, the connect type, the provider, the connect URL and the request id.
	 *
	 * @return void
	 */
	public function testTheAnswerIsA401ConnectProblem(): void {
		$asked = [];
		$response = $this->prompt($asked)->respond(
			exception: new PerUserAccountNotConnectedException(provider: 'google'),
			returnUrl: 'https://cloud.example.nl/apps/agenda',
			instance: 'req-7'
		);

		$this->assertSame(401, $response->getStatus());
		$document = $response->getData();
		$this->assertSame('https://integriq.nl/problems/connect-account-required', $document['type']);
		$this->assertSame('Connect your account first', $document['title']);
		$this->assertSame(401, $document['status']);
		$this->assertSame('google', $document['provider']);
		$this->assertSame('https://cloud.example.nl/connect', $document['connectUrl']);
		$this->assertSame('req-7', $document['instance']);
		$this->assertSame(
			[['openregister.credentialOauth2.start', ['provider' => 'google', 'scope' => 'personal', 'returnUrl' => 'https://cloud.example.nl/apps/agenda']]],
			$asked
		);
	}//end testTheAnswerIsA401ConnectProblem()

	/**
	 * Without a return URL the connect start carries none.
	 *
	 * @return void
	 */
	public function testWithoutAReturnUrlNoneIsSent(): void {
		$asked = [];
		$this->prompt($asked)->connectUrl(provider: 'microsoft', returnUrl: '');

		$this->assertSame([['openregister.credentialOauth2.start', ['provider' => 'microsoft', 'scope' => 'personal']]], $asked);
	}//end testWithoutAReturnUrlNoneIsSent()
}//end class
