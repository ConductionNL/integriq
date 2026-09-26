<?php

/**
 * Unit tests for VerzuimloketController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\VerzuimloketController;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the Verzuimloket push (berichten) endpoint and the signed inbound retour receiver.
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
 */
class VerzuimloketControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var VerzuimloketService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $verzuimloketService;

	/**
	 * @var WebhookSignatureService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $signatureService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var VerzuimloketController
	 */
	private VerzuimloketController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->verzuimloketService = $this->createMock(VerzuimloketService::class);
		$this->signatureService = $this->createMock(WebhookSignatureService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = $this->buildController();

	}//end setUp()

	/**
	 * Build a controller instance wired to the current mocks.
	 *
	 * @return VerzuimloketController
	 */
	private function buildController(): VerzuimloketController {
		return new VerzuimloketController(
			'integriq',
			$this->request,
			$this->verzuimloketService,
			$this->signatureService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()

	/**
	 * An unauthenticated caller gets 401 without reaching the Verzuimloket service.
	 *
	 * @return void
	 */
	public function testBerichtenRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->verzuimloketService->expects($this->never())->method('sendMelding');

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testBerichtenRequiresAuthentication()

	/**
	 * A missing required field is rejected 400 before the service is called.
	 *
	 * @return void
	 */
	public function testBerichtenRequiresMeldingTypeAndKenmerk(): void {
		$this->request->method('getParams')->willReturn(['meldingType' => 'eerste-melding']);

		$this->verzuimloketService->expects($this->never())->method('sendMelding');

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_fields', $response->getData()['error']);

	}//end testBerichtenRequiresMeldingTypeAndKenmerk()

	/**
	 * A valid push request returns the service's result verbatim.
	 *
	 * @return void
	 */
	public function testBerichtenReturnsResult(): void {
		$this->request->method('getParams')->willReturn(['meldingType' => 'eerste-melding', 'kenmerk' => 'k1', 'payload' => []]);

		$this->verzuimloketService->expects($this->once())
			->method('sendMelding')
			->willReturn(['ref' => 'MOCK-VERZUIM-1', 'meldingType' => 'eerste-melding', 'status' => 'sent']);

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ref' => 'MOCK-VERZUIM-1', 'meldingType' => 'eerste-melding', 'status' => 'sent'], $response->getData());

	}//end testBerichtenReturnsResult()

	/**
	 * A VerzuimloketTranslationException maps to 400 `invalid_melding`.
	 *
	 * @return void
	 */
	public function testBerichtenMapsTranslationExceptionTo400(): void {
		$this->request->method('getParams')->willReturn(['meldingType' => 'eerste-melding', 'kenmerk' => 'k1', 'payload' => []]);

		$this->verzuimloketService->method('sendMelding')->willThrowException(
			new VerzuimloketTranslationException(message: 'Required field "metricValue" is missing or empty.')
		);

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_melding', $response->getData()['error']);

	}//end testBerichtenMapsTranslationExceptionTo400()

	/**
	 * When no Verzuimloket source is configured, the endpoint reports a clean 503 `not_configured`.
	 *
	 * @return void
	 */
	public function testBerichtenReportsNotConfiguredCleanly(): void {
		$this->request->method('getParams')->willReturn(['meldingType' => 'eerste-melding', 'kenmerk' => 'k1', 'payload' => []]);

		$this->verzuimloketService->method('sendMelding')->willThrowException(
			new VerzuimloketProviderException(message: 'No active Verzuimloket source is configured (register "integriq", schema "source", type "verzuimloket", isEnabled=true). Configure one before using the Verzuimloket bridge.')
		);

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('not_configured', $response->getData()['error']);

	}//end testBerichtenReportsNotConfiguredCleanly()

	/**
	 * A generic transport failure maps to 502.
	 *
	 * @return void
	 */
	public function testBerichtenMapsProviderFailureTo502(): void {
		$this->request->method('getParams')->willReturn(['meldingType' => 'eerste-melding', 'kenmerk' => 'k1', 'payload' => []]);

		$this->verzuimloketService->method('sendMelding')->willThrowException(
			new VerzuimloketProviderException(message: 'DUO Verzuimloket endpoint responded with HTTP 503.')
		);

		$response = $this->controller->berichten();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('verzuimloket_send_failed', $response->getData()['error']);

	}//end testBerichtenMapsProviderFailureTo502()

	/**
	 * No Verzuimloket source configured at all fails the inbound webhook closed (401).
	 *
	 * @return void
	 */
	public function testRetourWithNoSourceConfiguredReturns401(): void {
		$this->verzuimloketService->method('resolveActiveSource')
			->willThrowException(new VerzuimloketProviderException(message: 'no source'));
		$this->signatureService->expects($this->never())->method('verify');

		$response = $this->controller->retour();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRetourWithNoSourceConfiguredReturns401()

	/**
	 * An unsigned/tampered retour is rejected 401 before any state change.
	 *
	 * @return void
	 */
	public function testRetourInvalidSignatureReturns401BeforeAnySideEffect(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->verzuimloketService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(false);

		$this->verzuimloketService->expects($this->never())->method('receiveReturn');

		$response = $this->controller->retour();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData()['error']);

	}//end testRetourInvalidSignatureReturns401BeforeAnySideEffect()

	/**
	 * A verified retour is routed to receiveReturn() and always acknowledges receipt.
	 *
	 * @return void
	 */
	public function testRetourVerifiedIsRoutedAndAcknowledged(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->verzuimloketService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);

		$this->verzuimloketService->expects($this->once())->method('receiveReturn');

		$response = $this->controller->retour();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['received']);

	}//end testRetourVerifiedIsRoutedAndAcknowledged()

	/**
	 * A processing exception after a verified signature never surfaces as a 500.
	 *
	 * @return void
	 */
	public function testRetourNeverCrashesOnProcessingException(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->verzuimloketService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);
		$this->verzuimloketService->method('receiveReturn')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller->retour();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['received']);

	}//end testRetourNeverCrashesOnProcessingException()
}//end class
