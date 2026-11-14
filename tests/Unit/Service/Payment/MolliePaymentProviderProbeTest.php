<?php

/**
 * Unit tests for the read-only provider check of the Mollie and log bindings.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/messaging-payments-review/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Payment;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The probe answers accepted with a mode, or the refusal, and never the key.
 *
 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
 */
class MolliePaymentProviderProbeTest extends TestCase {

	/**
	 * @var BrokeredCallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $broker;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var MolliePaymentProvider
	 */
	private MolliePaymentProvider $provider;

	/**
	 * A brokered Mollie configuration.
	 *
	 * @var array
	 */
	private array $configuration = [
		'provider' => 'mollie',
		'baseUrl' => 'https://api.mollie.example/v2/',
		'authentication' => ['credentialRef' => ['credentialId' => 'cred-uuid']],
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->broker = $this->createMock(BrokeredCallService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->provider = new MolliePaymentProvider($this->broker, $l, $this->logger);

	}//end setUp()

	/**
	 * Broker a credential and answer the dispatch with the given response.
	 *
	 * @param Response $response The Mollie answer.
	 *
	 * @return void
	 */
	private function answer(Response $response): void {
		$this->broker->method('hasCredentialRef')->willReturn(true);
		$this->broker->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => 'admin']);
		$this->broker->expects($this->once())
			->method('dispatch')
			->with('cred-uuid', 'admin', 'GET', 'https://api.mollie.example/v2/profiles/me', ['authentication' => $this->configuration['authentication']])
			->willReturn($response);

	}//end answer()

	/**
	 * An accepted test key answers ok, mode test and the profile name (REQ-PRV-003).
	 *
	 * @return void
	 */
	public function testAnAcceptedTestKeyAnswersOkInTestMode(): void {
		$this->answer(new Response(200, [], json_encode(['resource' => 'profile', 'id' => 'pfl_1', 'mode' => 'test', 'name' => 'Basisschool De Linde'])));

		$this->assertSame(
			['ok' => true, 'mode' => 'test', 'profile' => 'Basisschool De Linde', 'message' => null],
			$this->provider->probe(sourceConfiguration: $this->configuration)
		);

	}//end testAnAcceptedTestKeyAnswersOkInTestMode()

	/**
	 * A live key answers mode live; a missing name is null rather than invented.
	 *
	 * @return void
	 */
	public function testALiveKeyAnswersLiveMode(): void {
		$this->answer(new Response(200, [], json_encode(['mode' => 'live'])));

		$this->assertSame(
			['ok' => true, 'mode' => 'live', 'profile' => null, 'message' => null],
			$this->provider->probe(sourceConfiguration: $this->configuration)
		);

	}//end testALiveKeyAnswersLiveMode()

	/**
	 * A refused key is reported as a refusal, not thrown (REQ-PRV-003).
	 *
	 * @return void
	 */
	public function testARefusedKeyIsReportedNotThrown(): void {
		$this->answer(new Response(401, [], '{"status":401,"title":"Unauthorized Request"}'));

		$this->assertSame(
			['ok' => false, 'mode' => null, 'profile' => null, 'message' => 'Mollie responded with HTTP 401.'],
			$this->provider->probe(sourceConfiguration: $this->configuration)
		);

	}//end testARefusedKeyIsReportedNotThrown()

	/**
	 * An answer without a test or live mode is not taken as accepted.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAModeIsNotAccepted(): void {
		$this->answer(new Response(200, [], json_encode(['mode' => 'sandbox'])));

		$result = $this->provider->probe(sourceConfiguration: $this->configuration);

		$this->assertFalse($result['ok']);
		$this->assertSame('Mollie answered the profile lookup without a test or live mode.', $result['message']);

	}//end testAnAnswerWithoutAModeIsNotAccepted()

	/**
	 * Without a credentialRef the probe refuses before any dispatch.
	 *
	 * @return void
	 */
	public function testWithoutACredentialNothingIsDispatched(): void {
		$this->broker->method('hasCredentialRef')->willReturn(false);
		$this->broker->expects($this->never())->method('dispatch');

		$result = $this->provider->probe(sourceConfiguration: ['provider' => 'mollie']);

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('credentialRef', (string)$result['message']);

	}//end testWithoutACredentialNothingIsDispatched()

	/**
	 * The log binding needs no key and answers ok in log mode.
	 *
	 * @return void
	 */
	public function testTheLogBindingAnswersOkInLogMode(): void {
		$this->assertSame(
			['ok' => true, 'mode' => 'log', 'profile' => null, 'message' => null],
			(new LogPaymentProvider())->probe(sourceConfiguration: [])
		);

	}//end testTheLogBindingAnswersOkInLogMode()
}//end class
