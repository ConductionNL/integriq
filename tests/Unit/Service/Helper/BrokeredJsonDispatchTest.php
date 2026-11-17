<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use Exception;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Helper\BrokeredCall;
use OCA\Integriq\Service\Helper\BrokeredCallRefusals;
use OCA\Integriq\Service\Helper\BrokeredJsonDispatch;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Throwable;

/**
 * One brokered JSON call: only the source's authentication (and the JSON
 * body, when there is one) reaches the broker, a source without a credential
 * reference is refused before anything is prepared, and the broker's
 * failures come back as the caller's own refusals.
 *
 * @covers \OCA\Integriq\Service\Helper\BrokeredJsonDispatch
 * @uses   \OCA\Integriq\Service\Helper\BrokeredCall
 */
final class BrokeredJsonDispatchTest extends TestCase {

	private BrokeredCallService&MockObject $broker;

	protected function setUp(): void {
		$this->broker = $this->createMock(BrokeredCallService::class);
	}

	public function testItPreparesAndDispatchesWithOnlyTheAuthenticationAndTheJson(): void {
		$auth     = ['type' => 'oauth', 'credentialRef' => 'cred-1'];
		$expected = ['authentication' => $auth, 'json' => ['amount' => 5]];
		$response = new Response(status: 202);

		$this->broker->expects($this->once())->method('hasCredentialRef')->with($expected)->willReturn(true);
		$this->broker->expects($this->once())->method('prepare')
			->with($expected, ['type' => 'peppol'], false)
			->willReturn(['credentialId' => 'cred-1', 'actingUserId' => 'alice']);
		$this->broker->expects($this->once())->method('dispatch')
			->with('cred-1', 'alice', 'POST', 'https://ap.example/send', $expected)
			->willReturn($response);

		$result = $this->dispatch()->send(
			sourceConfiguration: ['authentication' => $auth, 'location' => 'https://ap.example', 'headers' => ['X' => 'y']],
			call: new BrokeredCall(method: 'POST', url: 'https://ap.example/send', json: ['amount' => 5])
		);

		$this->assertSame($response, $result);
	}

	public function testACallWithoutJsonSendsNoJsonKeyAndAMissingAuthenticationIsEmpty(): void {
		$this->broker->expects($this->once())->method('hasCredentialRef')->with(['authentication' => []])->willReturn(true);
		$this->broker->method('prepare')->willReturn(['credentialId' => 'c', 'actingUserId' => null]);
		$this->broker->expects($this->once())->method('dispatch')
			->with('c', null, 'GET', 'https://x/status', ['authentication' => []])
			->willReturn(new Response());

		$this->dispatch()->send(sourceConfiguration: [], call: new BrokeredCall(method: 'GET', url: 'https://x/status'));
	}

	public function testASourceWithoutACredentialReferenceIsRefusedBeforeAnythingIsPrepared(): void {
		$this->broker->method('hasCredentialRef')->willReturn(false);
		$this->broker->expects($this->never())->method('prepare');
		$this->broker->expects($this->never())->method('dispatch');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('credential missing');
		$this->dispatch()->send(sourceConfiguration: [], call: new BrokeredCall(method: 'GET', url: 'https://x'));
	}

	public function testAConfigurationRefusalFromPrepareIsTheConfigurationRefusal(): void {
		$cause = new BrokeredCallConfigurationException('no such credential');
		$this->broker->method('hasCredentialRef')->willReturn(true);
		$this->broker->method('prepare')->willThrowException($cause);
		$this->broker->expects($this->never())->method('dispatch');

		try {
			$this->dispatch()->send(sourceConfiguration: [], call: new BrokeredCall(method: 'GET', url: 'https://x'));
			$this->fail('Expected the configuration refusal');
		} catch (RuntimeException $e) {
			$this->assertSame('configuration refused', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}

	public function testAnyOtherFailureIsTheTransportRefusal(): void {
		$cause = new Exception('timeout');
		$this->broker->method('hasCredentialRef')->willReturn(true);
		$this->broker->method('prepare')->willReturn(['credentialId' => 'c', 'actingUserId' => 'u']);
		$this->broker->method('dispatch')->willThrowException($cause);

		try {
			$this->dispatch()->send(sourceConfiguration: [], call: new BrokeredCall(method: 'GET', url: 'https://x'));
			$this->fail('Expected the transport refusal');
		} catch (RuntimeException $e) {
			$this->assertSame('transport failed', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}

	private function dispatch(): BrokeredJsonDispatch {
		$refusals = new class implements BrokeredCallRefusals {
			public function credentialMissing(): Exception {
				return new RuntimeException('credential missing');
			}

			public function configurationRefused(BrokeredCallConfigurationException $cause): Exception {
				return new RuntimeException('configuration refused', 0, $cause);
			}

			public function transportFailed(Throwable $cause): Exception {
				return new RuntimeException('transport failed', 0, $cause);
			}
		};

		return new BrokeredJsonDispatch(broker: $this->broker, refusals: $refusals, sourceType: 'peppol');
	}
}
